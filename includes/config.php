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
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', (int) env('SESSION_LIFETIME', 86400));

if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');

    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly'  => true,
        'samesite'  => 'Strict',
    ]);
    session_start();
}
