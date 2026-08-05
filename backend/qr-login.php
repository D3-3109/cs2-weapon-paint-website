<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/class/Database.php';
require_once __DIR__ . '/class/SessionBootstrap.php';

$token = (string) ($_GET['token'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
    http_response_code(400);
    echo 'Invalid or expired login token';
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $db->beginTransaction();
    $stmt = $db->prepare('UPDATE qr_login_sessions SET consumed_at = CURRENT_TIMESTAMP WHERE token_hash = ? AND consumed_at IS NULL AND expires_at > CURRENT_TIMESTAMP');
    $tokenHash = hash('sha256', $token);
    $stmt->execute([$tokenHash]);
    if ($stmt->rowCount() !== 1) {
        $db->rollBack();
        http_response_code(401);
        echo 'Invalid or expired login token';
        exit;
    }
    $stmt = $db->prepare('SELECT steam_id FROM qr_login_sessions WHERE token_hash = ?');
    $stmt->execute([$tokenHash]);
    $steamid = (string) $stmt->fetchColumn();
    $db->commit();
} catch (Throwable) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(503);
    echo 'Login service unavailable';
    exit;
}

wp_boot_session();
$_SESSION['steamid'] = $steamid;
$_SESSION['login_at'] = time();
unset($_SESSION['user_profile'], $_SESSION['user_profile_at']);
wp_refresh_session_cookie();
header('Location: ' . rtrim(DOMAIN_NAME, '/') . '/');
exit;
