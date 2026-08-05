<?php

$sessionId = (string) ($_GET['session_id'] ?? '');
if (!preg_match('/^sess_[A-Za-z0-9_-]+$/', $sessionId)) {
    http_response_code(400);
    exit;
}

$file = __DIR__ . '/storage/images_qr/' . $sessionId . '.png';
if (!is_file($file)) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: no-store');
header('Content-Length: ' . filesize($file));
readfile($file);
