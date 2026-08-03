<?php
/**
 * Environment loader & application configuration.
 * Reads .env file and exposes helpers.
 */

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        die('.env file not found. Copy .env.example to .env and configure it.');
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
    }
}

$envPath = defined('APP_BASE_PATH') ? APP_BASE_PATH . '/.env' : dirname(__DIR__) . '/.env';
loadEnv($envPath);

function env(string $key, $default = null)
{
    return $_ENV[$key] ?? $default;
}

if (!defined('APP_NAME'))         define('APP_NAME',         env('APP_NAME', 'CrypTracker'));
if (!defined('CMC_API_KEY'))      define('CMC_API_KEY',      env('CMC_API_KEY', ''));
if (!defined('PREFERRED_DATABASE_TYPE'))      define('PREFERRED_DATABASE_TYPE',      env('PREFERRED_DATABASE_TYPE', ''));
// How long a signed-in session stays valid, in seconds. Default: 604800 = 1 week.
if (!defined('SESSION_LIFETIME')) {
    $__lifetime = (int) env('SESSION_LIFETIME', 604800);
    if ($__lifetime <= 0) $__lifetime = 604800;
    define('SESSION_LIFETIME', $__lifetime);
}

if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');

    // Keep server-side session data alive for the full SESSION_LIFETIME window.
    // Without this, PHP's default garbage collection (~24 min) deletes the
    // session file after brief inactivity and signs the user out — even though
    // the cookie itself would still be valid. This is the main reason users
    // were being forced to log in again so often.
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);

    // Store sessions in a private, app-owned directory inside the core folder
    // (outside the web root) instead of the shared system temp dir. On shared
    // hosting the global temp dir is garbage-collected on other tenants'
    // (short) gc_maxlifetime, which would silently destroy long-lived sessions.
    $sessionDir = (defined('APP_BASE_PATH') ? APP_BASE_PATH : dirname(__DIR__)) . '/database/sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0700, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly'  => true,
        'samesite'  => 'Strict',
    ]);
    session_start();
}
