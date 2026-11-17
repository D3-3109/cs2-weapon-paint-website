<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/class/SteamId.php';
require_once __DIR__ . '/class/QrLoginService.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$hasBearerFormat = preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches) === 1;
$hasConfiguredKey = defined('GAME_SERVER_API_KEY') && GAME_SERVER_API_KEY !== '';
$isAuthorized = $hasBearerFormat
    && $hasConfiguredKey
    && hash_equals((string) GAME_SERVER_API_KEY, trim($matches[1]));

if (!$isAuthorized) {
    http_response_code(QrLoginService::HTTP_UNAUTHORIZED);
    echo json_encode(['success' => false, 'qr_url' => null]);
    exit;
}

$rawSteamId = isset($_GET['qr']) ? (string) $_GET['qr'] : null;
$steamId = wp_parse_steamid64($rawSteamId);
if ($steamId === null) {
    http_response_code(QrLoginService::HTTP_BAD_REQUEST);
    echo json_encode(['success' => false, 'qr_url' => null]);
    exit;
}

try {
    $session = QrLoginService::issueSession(
        $steamId,
        DOMAIN_NAME,
        QrLoginService::resolveTtlSeconds()
    );
} catch (Throwable $e) {
    error_log('[WeaponPaints][QR] Failed to issue QR session: ' . $e->getMessage());
    http_response_code(QrLoginService::HTTP_SERVICE_UNAVAILABLE);
    echo json_encode(['success' => false, 'qr_url' => null]);
    exit;
}

echo json_encode([
    'success' => true,
    'qr_url' => $session['qr_url'],
]);
