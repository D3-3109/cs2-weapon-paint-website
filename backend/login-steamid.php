<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/class/SessionBootstrap.php';
require_once __DIR__ . '/class/SteamId.php';

$steamid = wp_parse_steamid64(isset($_GET['steamid']) ? (string) $_GET['steamid'] : null);
if ($steamid === null) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid SteamID64';
    exit;
}

wp_boot_session();
$_SESSION['steamid'] = $steamid;
$_SESSION['login_at'] = time();
unset($_SESSION['user_profile'], $_SESSION['user_profile_at']);
wp_refresh_session_cookie();

header('Location: ' . rtrim(DOMAIN_NAME, '/') . '/');
exit;
