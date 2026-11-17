<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/class/QrLoginService.php';
require_once __DIR__ . '/class/SessionBootstrap.php';
require_once __DIR__ . '/class/UserData.php';

if (!function_exists('wp_render_qr_login_error')) {
    function wp_render_qr_login_error(int $statusCode, string $titleZh, string $detailZh, string $detailEn): never
    {
        http_response_code($statusCode);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');

        $homeUrl = defined('DOMAIN_NAME') && DOMAIN_NAME !== ''
            ? htmlspecialchars(rtrim((string) DOMAIN_NAME, '/') . '/', ENT_QUOTES, 'UTF-8')
            : '/';
        $safeTitleZh = htmlspecialchars($titleZh, ENT_QUOTES, 'UTF-8');
        $safeDetailZh = htmlspecialchars($detailZh, ENT_QUOTES, 'UTF-8');
        $safeDetailEn = htmlspecialchars($detailEn, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$safeTitleZh}</title>
  <style>
    body{margin:0;min-height:100dvh;display:grid;place-items:center;padding:24px;background:#0e1419;color:#c7d5e0;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;box-sizing:border-box}
    main{max-width:420px;width:100%;background:#171a21;border:1px solid #2a3441;border-radius:12px;padding:24px;box-shadow:0 16px 40px rgba(0,0,0,.45)}
    h1{margin:0 0 12px;font-size:20px;color:#fff}
    p{margin:0 0 10px;line-height:1.55;font-size:14px;color:#9fb0c0}
    a{display:inline-block;margin-top:12px;padding:10px 18px;border-radius:8px;background:#1a9fff;color:#fff;text-decoration:none;font-weight:600;font-size:14px}
  </style>
</head>
<body>
  <main>
    <h1>{$safeTitleZh}</h1>
    <p>{$safeDetailZh}</p>
    <p>{$safeDetailEn}</p>
    <a href="{$homeUrl}">返回首页 / Home</a>
  </main>
</body>
</html>
HTML;
        exit;
    }
}

$token = (string) ($_GET['token'] ?? '');
if (!QrLoginService::isValidToken($token)) {
    wp_render_qr_login_error(
        QrLoginService::HTTP_BAD_REQUEST,
        '二维码链接无效',
        '该二维码链接格式无效，请在游戏聊天框重新输入 !cskin 获取新的二维码。',
        'Invalid or expired login token.'
    );
}

try {
    $consumed = QrLoginService::consumeToken($token);
} catch (Throwable $e) {
    error_log('[WeaponPaints][QR] Token consumption failed: ' . $e->getMessage());
    wp_render_qr_login_error(
        QrLoginService::HTTP_SERVICE_UNAVAILABLE,
        '登录服务暂时不可用',
        '服务器数据库连接繁忙，请稍后在游戏内重试 !cskin。',
        'Login service unavailable.'
    );
}

if ($consumed === null) {
    wp_render_qr_login_error(
        QrLoginService::HTTP_UNAUTHORIZED,
        '二维码已过期或已使用',
        '一次性登录二维码已失效，请在游戏聊天框重新输入 !cskin 生成新二维码。',
        'Invalid or expired login token.'
    );
}

$steamId = $consumed['steam_id'];

wp_boot_session();
session_regenerate_id(true);
$_SESSION['steamid'] = $steamId;
$_SESSION['login_at'] = time();

$sessionProfile = [
    'steamid' => $steamId,
    'personaname' => null,
    'avatar' => null,
    'profileurl' => null,
    'loccountrycode' => null,
];

try {
    $saved = UserData::upsertFromSteam($steamId, true);
    if (is_array($saved)) {
        $sessionProfile = [
            'steamid' => $saved['steamid'] ?? $steamId,
            'personaname' => $saved['personaname'] ?? null,
            'avatar' => $saved['avatar'] ?? null,
            'profileurl' => $saved['profileurl'] ?? null,
            'loccountrycode' => $saved['loccountrycode'] ?? null,
        ];
    }
} catch (Throwable $e) {
    error_log('[WeaponPaints][QR] Steam profile sync skipped: ' . $e->getMessage());
}

$_SESSION['user_profile'] = $sessionProfile;
$_SESSION['user_profile_at'] = time();
wp_refresh_session_cookie();

header('Location: ' . rtrim(DOMAIN_NAME, '/') . '/');
exit;
