<?php
/**
 * Main Dashboard – lists user's tracked tokens with summary P/L.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/helpers.php';

$user = requireAuth();
$tokens = dbGetUserTokens($user['id']);
$cmcIds = array_column($tokens, 'cmc_id');
$quotes = $cmcIds ? apiGetQuotes($cmcIds) : [];
$summaries     = [];
$totalRealPL   = 0;
$totalImagPL   = 0;
$totalInvested = 0;

foreach ($tokens as $tk) {
    $id       = $tk['id'];
    $cmcId    = $tk['cmc_id'];
    $price    = $quotes[$cmcId]['price'] ?? 0;
    $change24 = $quotes[$cmcId]['percent_change_24h'] ?? 0;

    $buys  = dbGetTransactions($id, 'buy');
    $sells = dbGetTransactions($id, 'sell');

    $totalBought  = 0;
    $totalSpent   = 0;
    $totalSold    = 0;
    $realizedPL   = 0;

    foreach ($buys as $b)  { $totalBought += $b['amount']; $totalSpent += $b['total_value']; }
    foreach ($sells as $s) { $totalSold   += $s['amount']; $realizedPL += $s['realized_pl']; }

    $holdings = max(0, $totalBought - $totalSold);
    $avgBuy   = ($totalBought > 0) ? ($totalSpent / $totalBought) : 0;

    $currentValue  = $holdings * $price;
    $costBasis     = $holdings * $avgBuy;
    $unrealizedPL  = $currentValue - $costBasis;

    $summaries[] = [
        'token'       => $tk,
        'price'       => $price,
        'change24'    => $change24,
        'holdings'    => $holdings,
        'avgBuy'      => $avgBuy,
        'invested'    => $costBasis,
        'currentVal'  => $currentValue,
        'realizedPL'  => $realizedPL,
        'unrealizedPL'=> $unrealizedPL,
        'totalPL'     => $realizedPL + $unrealizedPL,
    ];

    $totalRealPL   += $realizedPL;
    $totalImagPL   += $unrealizedPL;
    $totalInvested += $costBasis;
}

$totalPL = $totalRealPL + $totalImagPL;

layoutHead('Dashboard');
layoutNav($user);
?>

    <main class="container">

        <?= renderFlashes() ?>

        <section class="portfolio-summary">
            <div class="summary-card stagger-1">
                <span class="summary-label">Total Invested</span>
                <span class="summary-value"><?= formatUSD($totalInvested) ?></span>
            </div>
            <div class="summary-card stagger-2">
                <span class="summary-label">Realized P/L</span>
                <span class="summary-value <?= plClass($totalRealPL) ?>">
                    <?= formatPL($totalRealPL) ?>
                </span>
            </div>
            <div class="summary-card stagger-3">
                <span class="summary-label">Unrealized P/L</span>
                <span class="summary-value <?= plClass($totalImagPL) ?>">
                    <?= formatPL($totalImagPL) ?>
                </span>
            </div>
            <div class="summary-card highlight stagger-4">
                <span class="summary-label">Total P/L</span>
                <span class="summary-value <?= plClass($totalPL) ?>">
                    <?= formatPL($totalPL) ?>
                </span>
            </div>
        </section>

        <section class="add-token-section">
            <h2>Add Token</h2>
            <div class="search-wrapper">
                <input type="text" id="tokenSearch" placeholder="Search by name or symbol…" autocomplete="off">
                <div id="searchResults" class="search-results"></div>
            </div>
        </section>

        <section class="token-table-section">
            <h2>Your Tokens</h2>
            <?php if (empty($summaries)): ?>
                <p class="empty-state">No tokens tracked yet. Search and add one above!</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="token-table">
                    <thead>
                        <tr>
                            <th>Token</th>
                            <th>Price</th>
                            <th>24h</th>
                            <th>Holdings</th>
                            <th>Avg Buy</th>
                            <th>Current Value</th>
                            <th>Real P/L</th>
                            <th>Unreal P/L</th>
                            <th>Total P/L</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summaries as $s): ?>
                        <tr class="clickable-row" data-href="token.php?id=<?= (int)$s['token']['id'] ?>">
                            <td>
                                <strong><?= e($s['token']['symbol']) ?></strong>
                                <small><?= e($s['token']['name']) ?></small>
                            </td>
                            <td><?= formatUSD($s['price']) ?></td>
                            <td class="<?= plClass($s['change24']) ?>">
                                <?= formatPercent($s['change24']) ?>
                            </td>
                            <td><?= formatCrypto($s['holdings']) ?></td>
                            <td><?= formatUSD($s['avgBuy']) ?></td>
                            <td><?= formatUSD($s['currentVal']) ?></td>
                            <td class="<?= plClass($s['realizedPL']) ?>">
                                <?= formatPL($s['realizedPL']) ?>
                            </td>
                            <td class="<?= plClass($s['unrealizedPL']) ?>">
                                <?= formatPL($s['unrealizedPL']) ?>
                            </td>
                            <td class="<?= plClass($s['totalPL']) ?>">
                                <?= formatPL($s['totalPL']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </main>

<?php layoutFooter(); ?>
