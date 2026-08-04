<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/' && isset($_GET['qr'])) {
    require __DIR__ . '/qr.php';
    return true;
}

if (preg_match('#^/images_qr/([0-9]+)\.png$#D', $path, $matches)) {
    $_GET['steamid'] = $matches[1];
    require __DIR__ . '/qr-image.php';
    return true;
}

if (preg_match('#^/login/steamid/([0-9]+)$#D', $path, $matches)) {
    $_GET['steamid'] = $matches[1];
    require __DIR__ . '/login-steamid.php';
    return true;
}

return false;
