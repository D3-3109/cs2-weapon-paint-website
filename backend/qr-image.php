<?php

require_once __DIR__ . '/class/SteamId.php';

$steamid = wp_parse_steamid64(isset($_GET['steamid']) ? (string) $_GET['steamid'] : null);
if ($steamid === null) {
    http_response_code(400);
    exit;
}

$file = __DIR__ . '/storage/images_qr/' . $steamid . '.png';
if (!is_file($file)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: no-store');
header('Content-Length: ' . filesize($file));
readfile($file);
