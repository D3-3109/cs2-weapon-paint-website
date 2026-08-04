<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/class/SteamId.php';
require_once __DIR__ . '/class/PngWriter.php';
$errorReporting = error_reporting();
error_reporting($errorReporting & ~E_DEPRECATED);
require_once __DIR__ . '/vendor/phpqrcode/phpqrcode.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$steamid = wp_parse_steamid64(isset($_GET['qr']) ? (string) $_GET['qr'] : null);
if ($steamid === null) {
    error_reporting($errorReporting);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'qr_url' => null,
        'redirect_url' => null,
        'is_new' => false,
    ]);
    exit;
}

$redirectPath = '/login/steamid/' . $steamid;
$loginUrl = rtrim(DOMAIN_NAME, '/') . $redirectPath;
$directory = __DIR__ . '/storage/images_qr';
$file = $directory . '/' . $steamid . '.png';
$isNew = !is_file($file);

if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'qr_url' => null, 'redirect_url' => null, 'is_new' => false]);
    exit;
}

try {
    $matrix = QRcode::text($loginUrl, false, QR_ECLEVEL_M);
    error_reporting($errorReporting);
    $png = wp_qr_matrix_to_png($matrix);
    if (file_put_contents($file, $png, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save QR image');
    }
} catch (Throwable) {
    error_reporting($errorReporting);
    http_response_code(500);
    echo json_encode(['success' => false, 'qr_url' => null, 'redirect_url' => null, 'is_new' => false]);
    exit;
}

echo json_encode([
    'success' => true,
    'qr_url' => '/images_qr/' . $steamid . '.png',
    'redirect_url' => $redirectPath,
    'is_new' => $isNew,
]);
