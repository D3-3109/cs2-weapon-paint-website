<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/class/Database.php';
require_once __DIR__ . '/class/SteamId.php';
require_once __DIR__ . '/class/PngWriter.php';
$errorReporting = error_reporting();
error_reporting($errorReporting & ~E_DEPRECATED);
require_once __DIR__ . '/vendor/phpqrcode/phpqrcode.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $matches) || !defined('GAME_SERVER_API_KEY') || GAME_SERVER_API_KEY === '' || !hash_equals((string) GAME_SERVER_API_KEY, trim($matches[1]))) {
    http_response_code(401);
    echo json_encode(['success' => false, 'qr_url' => null, 'redirect_url' => null, 'is_new' => false]);
    exit;
}

$steamid = wp_parse_steamid64(isset($_GET['qr']) ? (string) $_GET['qr'] : null);
if ($steamid === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'qr_url' => null, 'redirect_url' => null, 'is_new' => false]);
    exit;
}

$sessionId = 'sess_' . bin2hex(random_bytes(8));
$loginToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$ttl = defined('QR_LOGIN_TTL') ? max(60, (int) QR_LOGIN_TTL) : 300;
$imageDir = __DIR__ . '/storage/images_qr';
$imageFile = $imageDir . '/' . $sessionId . '.png';
if (!is_dir($imageDir) && !mkdir($imageDir, 0775, true) && !is_dir($imageDir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'qr_url' => null, 'redirect_url' => null, 'is_new' => false]);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('INSERT INTO qr_login_sessions (session_id, token_hash, steam_id, expires_at) VALUES (?, ?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . $ttl . ' SECOND))');
    $stmt->execute([$sessionId, hash('sha256', $loginToken), $steamid]);
    $matrix = QRcode::text(rtrim(DOMAIN_NAME, '/') . '/qr-login?token=' . rawurlencode($loginToken), false, QR_ECLEVEL_M);
    $png = wp_qr_matrix_to_png($matrix);
    if (file_put_contents($imageFile, $png, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save QR image');
    }
} catch (Throwable $e) {
    @unlink($imageFile);
    if (isset($db, $sessionId)) {
        try {
            $stmt = $db->prepare('DELETE FROM qr_login_sessions WHERE session_id = ?');
            $stmt->execute([$sessionId]);
        } catch (Throwable) {
            // Keep the original generation error as the response.
        }
    }
    http_response_code(503);
    echo json_encode(['success' => false, 'qr_url' => null, 'redirect_url' => null, 'is_new' => false]);
    exit;
}

error_reporting($errorReporting);
echo json_encode(['success' => true, 'qr_url' => rtrim(DOMAIN_NAME, '/') . '/images_qr/' . $sessionId . '.png', 'redirect_url' => null, 'is_new' => true]);
