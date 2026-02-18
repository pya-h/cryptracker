<?php
/**
 * Shared view helpers, CSRF protection, security headers & layout.
 */

require_once __DIR__ . '/config.php';
function sendSecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrfToken()) . '">';
}

function csrfVerify(): bool
{
    $token = $_POST['_csrf'] ?? $_GET['_csrf'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrfGuard(): void
{
    if (!csrfVerify()) {
        http_response_code(403);
        die('Invalid or missing CSRF token. Please go back and try again.');
    }
}

function plClass(float $v): string
{
    return $v >= 0 ? 'profit' : 'loss';
}

/** +$100.00 or -$100.00 sign placement */
function formatPL(float $v, int $decimals = 2): string
{
    if ($v >= 0) {
        return '+$' . number_format($v, $decimals);
    }
    return '-$' . number_format(abs($v), $decimals);
}

function formatCrypto(float $amount, int $decimals = 8): string
{
    return rtrim(rtrim(number_format($amount, $decimals), '0'), '.');
}

function formatUSD(float $v, int $decimals = 2): string
{
    return '$' . number_format($v, $decimals);
}

function formatPercent(float $v, int $decimals = 2): string
{
    $sign = $v >= 0 ? '+' : '';
    return $sign . number_format($v, $decimals) . '%';
}

/** Abbreviate large numbers: 1.2B, 340.5M, 12.3K */
function formatBigNum(float $v): string
{
    if ($v >= 1e12) return '$' . number_format($v / 1e12, 2) . 'T';
    if ($v >= 1e9)  return '$' . number_format($v / 1e9, 2) . 'B';
    if ($v >= 1e6)  return '$' . number_format($v / 1e6, 2) . 'M';
    if ($v >= 1e3)  return '$' . number_format($v / 1e3, 2) . 'K';
    return '$' . number_format($v, 2);
}

/** Abbreviate supply numbers without currency sign */
function formatSupply(float $v): string
{
    if ($v <= 0) return '—';
    if ($v >= 1e12) return number_format($v / 1e12, 2) . 'T';
    if ($v >= 1e9)  return number_format($v / 1e9, 2) . 'B';
    if ($v >= 1e6)  return number_format($v / 1e6, 2) . 'M';
    if ($v >= 1e3)  return number_format($v / 1e3, 2) . 'K';
    return number_format($v, 0);
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function renderFlashes(): string
{
    $html = '';
    foreach (getFlashes() as $f) {
        $cls = $f['type'] === 'error' ? 'alert-error' : 'alert-success';
        $html .= '<div class="alert ' . $cls . ' animate-slide-down"><p>' . e($f['message']) . '</p></div>';
    }
    return $html;
}
function layoutHead(string $title, bool $authPage = false): void
{
    sendSecurityHeaders();
    $bodyClass = $authPage ? 'class="auth-page"' : '';
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . e($title) . ' – ' . e(APP_NAME) . '</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css?v=' . filemtime(__DIR__ . '/../assets/style.css') . '">
    <meta name="csrf-token" content="' . e(csrfToken()) . '">
</head>
<body ' . $bodyClass . '>';
}

function layoutNav(array $user): void
{
    echo '<nav class="navbar animate-slide-down">
        <a href="index.php" class="nav-brand">
            <span class="brand-icon">◈</span> ' . e(APP_NAME) . '
        </a>
        <div class="nav-right">
            <span class="nav-user">
                <span class="user-avatar">' . strtoupper(e($user['username'])[0]) . '</span>
                ' . e($user['username']) . '
            </span>
            <a href="logout.php" class="btn btn-sm btn-outline">Logout</a>
        </div>
    </nav>';
}

function layoutFooter(): void
{
    echo '<script src="assets/app.js?v=' . filemtime(__DIR__ . '/../assets/app.js') . '"></script>
</body>
</html>';
}
