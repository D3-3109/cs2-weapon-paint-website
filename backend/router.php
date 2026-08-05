<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/' && isset($_GET['qr'])) {
    require __DIR__ . '/qr.php';
    return true;
}
if ($path === '/qr-login' && isset($_GET['token'])) {
    require __DIR__ . '/qr-login.php';
    return true;
}

if (preg_match('#^/images_qr/(sess_[A-Za-z0-9_-]+)\.png$#D', $path, $matches)) {
    $_GET['session_id'] = $matches[1];
    require __DIR__ . '/qr-image.php';
    return true;
}

return false;
