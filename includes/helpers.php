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

function plMode(): string
{
    return $_SESSION['pl_mode'] ?? 'avg';
}

/**
 * Unified P/L calculator supporting two modes:
 *   'avg'  — weighted-average cost basis
 *   'fifo' — exact/FIFO: sells consume oldest buy lots first
 *
 * Returns holdings, cost basis, realized/unrealized/total PL, and a timeline
 * suitable for the analytics table and canvas graph.
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

/** FIFO: sells consume oldest buy lots first */
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
