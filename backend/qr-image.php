<?php

if (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

$httpBadRequest = 400;
$httpNotFound = 404;
$minTtlSeconds = 60;
$defaultTtlSeconds = 300;

$sessionId = (string) ($_GET['session_id'] ?? '');
if (preg_match('/^sess_[A-Za-z0-9_-]+$/D', $sessionId) !== 1) {
    http_response_code($httpBadRequest);
    exit;
}

$file = __DIR__ . '/storage/images_qr/' . $sessionId . '.png';
if (!is_file($file)) {
    http_response_code($httpNotFound);
    exit;
}

$ttlSeconds = defined('QR_LOGIN_TTL')
    ? max($minTtlSeconds, (int) QR_LOGIN_TTL)
    : $defaultTtlSeconds;
$mtime = @filemtime($file);

if ($mtime !== false && (time() - $mtime) > $ttlSeconds) {
    @unlink($file);
    http_response_code($httpNotFound);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Length: ' . filesize($file));
readfile($file);
