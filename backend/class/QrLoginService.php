<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/PngWriter.php';

final class QrLoginService
{
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_SERVICE_UNAVAILABLE = 503;

    private const MIN_TTL_SECONDS = 60;
    private const DEFAULT_TTL_SECONDS = 300;
    private const SESSION_ID_BYTES = 8;
    private const TOKEN_BYTES = 32;
    private const ORPHAN_FILE_GRACE_SECONDS = 60;
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';
    private const SESSION_ID_PATTERN = '/^sess_[A-Za-z0-9_-]+$/D';

    private function __construct() {}

    public static function resolveTtlSeconds(): int
    {
        if (!defined('QR_LOGIN_TTL')) {
            return self::DEFAULT_TTL_SECONDS;
        }

        return max(self::MIN_TTL_SECONDS, (int) QR_LOGIN_TTL);
    }

    public static function isValidToken(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    public static function isValidSessionId(string $sessionId): bool
    {
        return preg_match(self::SESSION_ID_PATTERN, $sessionId) === 1;
    }

    public static function imageFilePath(string $sessionId): string
    {
        return self::imageDirectory() . '/' . $sessionId . '.png';
    }

    /**
     * @return array{session_id: string, qr_url: string}
     */
    public static function issueSession(string $steamId, string $domainName, int $ttlSeconds): array
    {
        $effectiveTtl = max(self::MIN_TTL_SECONDS, $ttlSeconds);
        $imageDir = self::ensureImageDirectory();
        $sessionId = 'sess_' . bin2hex(random_bytes(self::SESSION_ID_BYTES));
        $loginToken = rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
        $imageFile = $imageDir . '/' . $sessionId . '.png';
        $baseUrl = rtrim($domainName, '/');

        $db = null;
        try {
            $db = Database::getInstance()->getConnection();
            self::cleanupStaleSessions($db, $steamId, $imageDir, $effectiveTtl);

            $insertSql = 'INSERT INTO qr_login_sessions (session_id, token_hash, steam_id, expires_at) '
                . 'VALUES (?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $effectiveTtl . ' SECOND))';
            $stmt = $db->prepare($insertSql);
            $stmt->execute([$sessionId, hash('sha256', $loginToken), $steamId]);

            $png = self::renderQrPng($baseUrl . '/qr-login?token=' . rawurlencode($loginToken));
            if (file_put_contents($imageFile, $png, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write QR image file');
            }
        } catch (Throwable $e) {
            @unlink($imageFile);
            if ($db instanceof PDO) {
                self::deleteSessionByIdBestEffort($db, $sessionId);
            }
            throw $e;
        }

        return [
            'session_id' => $sessionId,
            'qr_url' => $baseUrl . '/images_qr/' . $sessionId . '.png',
        ];
    }

    /**
     * Atomically marks a valid token as consumed and deletes its one-time QR image.
     *
     * @return array{session_id: string, steam_id: string}|null
     */
    public static function consumeToken(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);
        $db = Database::getInstance()->getConnection();
        $db->beginTransaction();

        try {
            $updateStmt = $db->prepare(
                'UPDATE qr_login_sessions '
                . 'SET consumed_at = CURRENT_TIMESTAMP '
                . 'WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP'
            );
            $updateStmt->execute([$tokenHash]);

            if ($updateStmt->rowCount() !== 1) {
                $db->rollBack();
                return null;
            }

            $selectStmt = $db->prepare(
                'SELECT session_id, steam_id FROM qr_login_sessions WHERE token_hash = ? LIMIT 1'
            );
            $selectStmt->execute([$tokenHash]);
            $row = $selectStmt->fetch(PDO::FETCH_ASSOC);

            if (!is_array($row) || empty($row['steam_id']) || empty($row['session_id'])) {
                $db->rollBack();
                return null;
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $sessionId = (string) $row['session_id'];
        @unlink(self::imageFilePath($sessionId));

        return [
            'session_id' => $sessionId,
            'steam_id' => (string) $row['steam_id'],
        ];
    }

    private static function imageDirectory(): string
    {
        return dirname(__DIR__) . '/storage/images_qr';
    }

    private static function ensureImageDirectory(): string
    {
        $dir = self::imageDirectory();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create QR image directory');
        }

        return $dir;
    }

    private static function renderQrPng(string $loginUrl): string
    {
        $previousReporting = error_reporting();
        error_reporting($previousReporting & ~E_DEPRECATED);

        try {
            require_once dirname(__DIR__) . '/vendor/phpqrcode/phpqrcode.php';
            $matrix = QRcode::text($loginUrl, false, QR_ECLEVEL_M);
        } finally {
            error_reporting($previousReporting);
        }

        return wp_qr_matrix_to_png($matrix);
    }

    private static function cleanupStaleSessions(
        PDO $db,
        string $steamId,
        string $imageDir,
        int $ttlSeconds
    ): void {
        $staleStmt = $db->prepare(
            'SELECT session_id FROM qr_login_sessions '
            . 'WHERE expires_at <= CURRENT_TIMESTAMP '
            . 'OR (steam_id = ? AND consumed_at IS NULL)'
        );
        $staleStmt->execute([$steamId]);
        $staleIds = $staleStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        foreach ($staleIds as $oldSessionId) {
            if (is_string($oldSessionId) && self::isValidSessionId($oldSessionId)) {
                @unlink($imageDir . '/' . $oldSessionId . '.png');
            }
        }

        $deleteStmt = $db->prepare(
            'DELETE FROM qr_login_sessions '
            . 'WHERE expires_at <= CURRENT_TIMESTAMP '
            . 'OR (steam_id = ? AND consumed_at IS NULL)'
        );
        $deleteStmt->execute([$steamId]);

        $cutoff = time() - ($ttlSeconds + self::ORPHAN_FILE_GRACE_SECONDS);
        foreach (glob($imageDir . '/sess_*.png') ?: [] as $filePath) {
            $mtime = @filemtime($filePath);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($filePath);
            }
        }
    }

    private static function deleteSessionByIdBestEffort(PDO $db, string $sessionId): void
    {
        try {
            $stmt = $db->prepare('DELETE FROM qr_login_sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
        } catch (Throwable) {
            // Preserve the primary failure from session creation.
        }
    }
}
