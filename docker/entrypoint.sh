#!/bin/sh
set -eu

: "${DOMAIN_NAME:?DOMAIN_NAME is required}"
: "${DB_HOST:?DB_HOST is required}"
: "${DB_NAME:?DB_NAME is required}"
: "${DB_USER:?DB_USER is required}"
: "${DB_PASS:?DB_PASS is required}"
: "${GAME_SERVER_API_KEY:?GAME_SERVER_API_KEY is required}"

api_dir=/var/www/html/api
storage_dir="$api_dir/storage"

mkdir -p "$storage_dir/sessions" "$storage_dir/cache" "$storage_dir/ratelimit" "$storage_dir/images_qr"
chown -R www-data:www-data "$storage_dir"

export DOMAIN_NAME="${DOMAIN_NAME%/}"
export STEAM_API_KEY="${STEAM_API_KEY:-}"
export GAME_SERVER_API_KEY="${GAME_SERVER_API_KEY:-}"
export DB_PORT="${DB_PORT:-3306}"
export SESSION_LIFETIME="${SESSION_LIFETIME:-2592000}"
export API_RATE_LIMIT="${API_RATE_LIMIT:-120}"
export API_RATE_WINDOW="${API_RATE_WINDOW:-60}"
export API_READ_CACHE_TTL="${API_READ_CACHE_TTL:-5}"
export QR_LOGIN_TTL="${QR_LOGIN_TTL:-300}"
export SSL_REDIRECT="${SSL_REDIRECT:-false}"

cat > "$api_dir/config.php" <<PHP
<?php
define('STEAM_API_KEY', getenv('STEAM_API_KEY') ?: '');
define('GAME_SERVER_API_KEY', getenv('GAME_SERVER_API_KEY') ?: '');
define('DOMAIN_NAME', rtrim((string) getenv('DOMAIN_NAME'), '/'));
define('DB_HOST', (string) getenv('DB_HOST'));
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', (string) getenv('DB_NAME'));
define('DB_USER', (string) getenv('DB_USER'));
define('DB_PASS', (string) getenv('DB_PASS'));
define('SESSION_LIFETIME', (int) (getenv('SESSION_LIFETIME') ?: 2592000));
define('API_RATE_LIMIT', (int) (getenv('API_RATE_LIMIT') ?: 120));
define('API_RATE_WINDOW', (int) (getenv('API_RATE_WINDOW') ?: 60));
define('API_READ_CACHE_TTL', (int) (getenv('API_READ_CACHE_TTL') ?: 5));
define('QR_LOGIN_TTL', (int) (getenv('QR_LOGIN_TTL') ?: 300));
define('SSL_REDIRECT', getenv('SSL_REDIRECT') ?: 'false');
define('API_DOMAIN_NAME', DOMAIN_NAME . '/api');
define('STEAM_DOMAIN_NAME', API_DOMAIN_NAME);
define('STEAM_LOGOUT_PAGE', API_DOMAIN_NAME . '/steamauth/logout.php');
define('STEAM_LOGIN_PAGE', API_DOMAIN_NAME . '/steamauth/login.php');
PHP

exec "$@"
