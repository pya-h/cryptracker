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
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; connect-src 'self'");
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

function formatPL(float $v, int $decimals = -1): string
{
    if ($decimals < 0) $decimals = precision();
    if ($v >= 0) {
        return '+$' . number_format($v, $decimals);
    }
    return '-$' . number_format(abs($v), $decimals);
}

function formatCrypto(float $amount, int $decimals = -1): string
{
    if ($decimals < 0) $decimals = precision();
    $max = max($decimals, 8);
    return rtrim(rtrim(number_format($amount, $max), '0'), '.');
}

function formatUSD(float $v, int $decimals = -1): string
{
    if ($decimals < 0) $decimals = precision();
    return '$' . number_format($v, $decimals);
}

function formatPercent(float $v, int $decimals = -1): string
{
    if ($decimals < 0) $decimals = precision();
    $sign = $v >= 0 ? '+' : '';
    return $sign . number_format($v, $decimals) . '%';
}

function formatBigNum(float $v): string
{
    $p = precision();
    if ($v >= 1e12) return '$' . number_format($v / 1e12, $p) . 'T';
    if ($v >= 1e9)  return '$' . number_format($v / 1e9, $p) . 'B';
    if ($v >= 1e6)  return '$' . number_format($v / 1e6, $p) . 'M';
    if ($v >= 1e3)  return '$' . number_format($v / 1e3, $p) . 'K';
    return '$' . number_format($v, $p);
}

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

function plMode(): string
{
    return $_SESSION['pl_mode'] ?? 'avg';
}

function precision(): int
{
    return (int) ($_SESSION['precision'] ?? 2);
}

function theme(): string
{
    return $_SESSION['theme'] ?? 'dark';
}

/**
 * P/L calculator: 'avg' (weighted-average) or 'fifo' (oldest lots first).
 */
function calcTokenPL(int $tokenId, float $currentPrice, string $mode = 'avg'): array
{
    $txs = dbGetTransactions($tokenId);

    if ($mode === 'fifo') {
        return _calcFifo($txs, $currentPrice);
    }
    return _calcAvg($txs, $currentPrice);
}

function _calcAvg(array $txs, float $currentPrice): array
{
    $totalBought = 0.0;
    $totalSpent  = 0.0;
    $totalSold   = 0.0;
    $realizedPL  = 0.0;
    $timeline    = [];

    $runBuyAmt   = 0.0;
    $runBuyCost  = 0.0;
    $runHoldings = 0.0;
    $runRealized = 0.0;

    foreach ($txs as $tx) {
        if ($tx['type'] === 'buy') {
            $totalBought += $tx['amount'];
            $totalSpent  += $tx['total_value'];
            $runBuyAmt   += $tx['amount'];
            $runBuyCost  += $tx['total_value'];
            $runHoldings += $tx['amount'];
            $txRealized   = 0.0;
        } else {
            $totalSold   += $tx['amount'];
            $runAvg       = ($runBuyAmt > 0) ? ($runBuyCost / $runBuyAmt) : 0;
            $txRealized   = $tx['amount'] * ($tx['price_per_unit'] - $runAvg);
            $realizedPL  += $txRealized;
            $runRealized += $txRealized;
            $runHoldings -= $tx['amount'];
        }

        $runAvg        = ($runBuyAmt > 0) ? ($runBuyCost / $runBuyAmt) : 0;
        $runCostBasis  = $runHoldings * $runAvg;
        $runCurrValue  = $runHoldings * $tx['price_per_unit'];
        $runUnrealized = $runCurrValue - $runCostBasis;
        $runTotalPL    = $runRealized + $runUnrealized;

        $timeline[] = [
            'date'         => $tx['created_at'],
            'type'         => $tx['type'],
            'amount'       => $tx['amount'],
            'ppu'          => $tx['price_per_unit'],
            'total'        => $tx['total_value'],
            'realized'     => $txRealized,
            'holdings'     => $runHoldings,
            'avg_cost'     => $runAvg,
            'cum_realized' => $runRealized,
            'cum_total_pl' => $runTotalPL,
        ];
    }

    $holdings  = max(0, $totalBought - $totalSold);
    $avgBuy    = ($totalBought > 0) ? ($totalSpent / $totalBought) : 0;
    $costBasis = $holdings * $avgBuy;
    $currValue = $holdings * $currentPrice;
    $unrealPL  = $currValue - $costBasis;

    return [
        'holdings'      => $holdings,
        'avg_buy'       => $avgBuy,
        'cost_basis'    => $costBasis,
        'current_value' => $currValue,
        'realized_pl'   => $realizedPL,
        'unrealized_pl' => $unrealPL,
        'total_pl'      => $realizedPL + $unrealPL,
        'total_spent'   => $totalSpent,
        'timeline'      => $timeline,
    ];
}

function _calcFifo(array $txs, float $currentPrice): array
{
    $lots        = [];
    $realizedPL  = 0.0;
    $totalSpent  = 0.0;
    $timeline    = [];
    $runRealized = 0.0;

    foreach ($txs as $tx) {
        $txRealized = 0.0;

        if ($tx['type'] === 'buy') {
            $lots[] = ['amount' => $tx['amount'], 'price' => $tx['price_per_unit']];
            $totalSpent += $tx['total_value'];
        } else {
            $remaining  = $tx['amount'];
            $sellPrice  = $tx['price_per_unit'];
            $sellCost   = 0.0;

            while ($remaining > 1e-10 && !empty($lots)) {
                $take = min($remaining, $lots[0]['amount']);
                $sellCost += $take * $lots[0]['price'];
                $lots[0]['amount'] -= $take;
                $remaining -= $take;
                if ($lots[0]['amount'] < 1e-10) {
                    array_shift($lots);
                }
            }

            $txRealized  = ($tx['amount'] * $sellPrice) - $sellCost;
            $realizedPL += $txRealized;
            $runRealized += $txRealized;
        }

        $holdings  = array_sum(array_column($lots, 'amount'));
        $costBasis = 0.0;
        foreach ($lots as $l) $costBasis += $l['amount'] * $l['price'];
        $avgCost   = ($holdings > 1e-10) ? ($costBasis / $holdings) : 0;

        $runCurrValue  = $holdings * $tx['price_per_unit'];
        $runUnrealized = $runCurrValue - $costBasis;
        $runTotalPL    = $runRealized + $runUnrealized;

        $timeline[] = [
            'date'         => $tx['created_at'],
            'type'         => $tx['type'],
            'amount'       => $tx['amount'],
            'ppu'          => $tx['price_per_unit'],
            'total'        => $tx['total_value'],
            'realized'     => $txRealized,
            'holdings'     => $holdings,
            'avg_cost'     => $avgCost,
            'cum_realized' => $runRealized,
            'cum_total_pl' => $runTotalPL,
        ];
    }

    $holdings  = array_sum(array_column($lots, 'amount'));
    $costBasis = 0.0;
    foreach ($lots as $l) $costBasis += $l['amount'] * $l['price'];
    $avgBuy    = ($holdings > 1e-10) ? ($costBasis / $holdings) : 0;
    $currValue = $holdings * $currentPrice;
    $unrealPL  = $currValue - $costBasis;

    return [
        'holdings'      => $holdings,
        'avg_buy'       => $avgBuy,
        'cost_basis'    => $costBasis,
        'current_value' => $currValue,
        'realized_pl'   => $realizedPL,
        'unrealized_pl' => $unrealPL,
        'total_pl'      => $realizedPL + $unrealPL,
        'total_spent'   => $totalSpent,
        'timeline'      => $timeline,
    ];
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
    $themeClass = theme() === 'light' ? 'theme-light' : '';
    $classes = trim(($authPage ? 'auth-page' : '') . ' ' . $themeClass);
    $bodyAttr = $classes ? 'class="' . $classes . '"' : '';
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
<body ' . $bodyAttr . '>';
}

function layoutNav(array $user): void
{
    $mode = plMode();
    $isExact = $mode === 'fifo';
    $modeLabel = $isExact ? 'Exact' : 'Average';
    $redirect = $_SERVER['REQUEST_URI'] ?? 'index.php';
    $redirect = basename($redirect);
    if (!preg_match('/^(index\.php|token\.php\?id=\d+)$/', $redirect)) {
        $redirect = 'index.php';
    }

    echo '<nav class="navbar animate-slide-down">
        <a href="index.php" class="nav-brand">
            <span class="brand-icon">◈</span> ' . e(APP_NAME) . '
        </a>
        <div class="nav-right">
            <form method="POST" action="toggle_mode.php" class="mode-toggle-form">
                ' . csrfField() . '
                <input type="hidden" name="redirect" value="' . e($redirect) . '">
                <button type="submit" class="mode-toggle" data-tooltip="P/L calculation mode">
                    <span class="mode-label">' . $modeLabel . '</span>
                    <span class="mode-switch ' . ($isExact ? 'active' : '') . '">
                        <span class="mode-knob"></span>
                    </span>
                </button>
            </form>
            <div class="user-menu-wrapper">
                <button type="button" class="nav-user-btn" id="userMenuBtn">
                    <span class="user-avatar">' . strtoupper(e($user['username'])[0]) . '</span>
                    <span class="user-name">' . e($user['username']) . '</span>
                    <span class="user-caret">▾</span>
                </button>
                <div class="user-menu" id="userMenu">
                    <button type="button" class="user-menu-item" id="openCustomize">
                        <span class="menu-icon">⚙</span> Customize
                    </button>
                    <button type="button" class="user-menu-item" id="exportCsv">
                        <span class="menu-icon">⤓</span> Export to CSV
                    </button>
                    <div class="user-menu-divider"></div>
                    <a href="logout.php" class="user-menu-item menu-danger">
                        <span class="menu-icon">⏻</span> Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>';

    layoutCustomizeModal($user, $redirect);
}

function layoutCustomizeModal(array $user, string $redirect): void
{
    $mode = plMode();
    $isExact = $mode === 'fifo';
    $modeLabel = $isExact ? 'Exact' : 'Average';
    $thm = theme();
    $prec = precision();

    echo '<div class="modal-overlay" id="customizeOverlay">
    <div class="modal animate-scale-in">
        <div class="modal-header">
            <h2>Customize</h2>
            <button type="button" class="modal-close" id="closeCustomize">&times;</button>
        </div>
        <div class="modal-body">

            <div class="setting-group">
                <span class="setting-label">P/L Calculation Mode</span>
                <form method="POST" action="toggle_mode.php" class="mode-toggle-form">
                    ' . csrfField() . '
                    <input type="hidden" name="redirect" value="' . e($redirect) . '">
                    <button type="submit" class="mode-toggle" data-tooltip="P/L calculation mode">
                        <span class="mode-label">' . $modeLabel . '</span>
                        <span class="mode-switch ' . ($isExact ? 'active' : '') . '">
                            <span class="mode-knob"></span>
                        </span>
                    </button>
                </form>
            </div>

            <div class="setting-group">
                <span class="setting-label">Theme</span>
                <form method="POST" action="save_settings.php" class="mode-toggle-form">
                    ' . csrfField() . '
                    <input type="hidden" name="action" value="theme">
                    <input type="hidden" name="redirect" value="' . e($redirect) . '">
                    <button type="submit" class="mode-toggle">
                        <span class="mode-label">' . ($thm === 'light' ? 'Light' : 'Dark') . '</span>
                        <span class="mode-switch ' . ($thm === 'light' ? 'active' : '') . '">
                            <span class="mode-knob"></span>
                        </span>
                    </button>
                </form>
            </div>

            <div class="setting-group">
                <span class="setting-label">Precision: <strong id="precVal">' . $prec . '</strong> digits</span>
                <form method="POST" action="save_settings.php" class="precision-form" id="precisionForm">
                    ' . csrfField() . '
                    <input type="hidden" name="action" value="precision">
                    <input type="hidden" name="redirect" value="' . e($redirect) . '">
                    <input type="range" name="precision" min="2" max="10" value="' . $prec . '" class="precision-slider" id="precSlider">
                    <div class="precision-range-labels"><span>2</span><span>10</span></div>
                    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.5rem">Apply</button>
                </form>
            </div>

            <div class="setting-divider"></div>

            <div class="setting-group">
                <span class="setting-label">Change Username</span>
                <form method="POST" action="save_settings.php" class="settings-form">
                    ' . csrfField() . '
                    <input type="hidden" name="action" value="username">
                    <input type="hidden" name="redirect" value="' . e($redirect) . '">
                    <input type="text" name="new_username" placeholder="New username" required
                           minlength="3" pattern="[a-zA-Z0-9_\-]{3,30}"
                           title="3-30 characters: letters, numbers, _ or -">
                    <button type="submit" class="btn btn-primary btn-sm">Update</button>
                </form>
            </div>

            <div class="setting-group">
                <span class="setting-label">Change Password</span>
                <form method="POST" action="save_settings.php" class="settings-form">
                    ' . csrfField() . '
                    <input type="hidden" name="action" value="password">
                    <input type="hidden" name="redirect" value="' . e($redirect) . '">
                    <input type="password" name="old_password" placeholder="Current password" required>
                    <input type="password" name="new_password" placeholder="New password (min 6 chars)" required minlength="6">
                    <button type="submit" class="btn btn-primary btn-sm">Update</button>
                </form>
            </div>

        </div>
    </div>
</div>';
}

function layoutFooter(): void
{
    echo '<script src="assets/app.js?v=' . filemtime(__DIR__ . '/../assets/app.js') . '"></script>
</body>
</html>';
}
