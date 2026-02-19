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
$mode = plMode();
$summaries     = [];
$totalRealPL   = 0;
$totalUnrealPL = 0;
$totalInvested = 0;

foreach ($tokens as $tk) {
    $id       = $tk['id'];
    $cmcId    = $tk['cmc_id'];
    $price    = $quotes[$cmcId]['price'] ?? 0;
    $change24 = $quotes[$cmcId]['percent_change_24h'] ?? 0;

    $pl = calcTokenPL($id, $price, $mode);

    $summaries[] = [
        'token'       => $tk,
        'price'       => $price,
        'change24'    => $change24,
        'holdings'    => $pl['holdings'],
        'avgBuy'      => $pl['avg_buy'],
        'invested'    => $pl['cost_basis'],
        'currentVal'  => $pl['current_value'],
        'realizedPL'  => $pl['realized_pl'],
        'unrealizedPL'=> $pl['unrealized_pl'],
        'totalPL'     => $pl['total_pl'],
    ];

    $totalRealPL   += $pl['realized_pl'];
    $totalUnrealPL += $pl['unrealized_pl'];
    $totalInvested += $pl['cost_basis'];
}

$totalPL = $totalRealPL + $totalUnrealPL;

layoutHead('Dashboard');
layoutNav($user);
?>

    <main class="container" data-page="dashboard"
          data-precision="<?= precision() ?>"
          data-worthless-zeros="<?= worthlessZeros() ? '1' : '0' ?>">

        <?= renderFlashes() ?>

        <section class="portfolio-summary">
            <div class="summary-card stagger-1">
                <span class="summary-label">Total Invested</span>
                <span class="summary-value" data-countup="<?= $totalInvested ?>" data-prefix="$"><?= formatUSD($totalInvested) ?></span>
            </div>
            <div class="summary-card stagger-2">
                <span class="summary-label">Realized P/L</span>
                <span class="summary-value <?= plClass($totalRealPL) ?>" data-countup="<?= $totalRealPL ?>" data-pl="1">
                    <?= formatPL($totalRealPL) ?>
                </span>
            </div>
            <div class="summary-card stagger-3">
                <span class="summary-label">Unrealized P/L</span>
                <span class="summary-value <?= plClass($totalUnrealPL) ?>" data-countup="<?= $totalUnrealPL ?>" data-pl="1" data-live="unrealized-total">
                    <?= formatPL($totalUnrealPL) ?>
                </span>
            </div>
            <div class="summary-card highlight stagger-4">
                <span class="summary-label">Total P/L</span>
                <span class="summary-value <?= plClass($totalPL) ?>" data-countup="<?= $totalPL ?>" data-pl="1" data-live="total-pl-total">
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
                        <tr class="clickable-row" data-href="token.php?id=<?= (int)$s['token']['id'] ?>"
                            data-cmc-id="<?= (int)$s['token']['cmc_id'] ?>"
                            data-holdings="<?= $s['holdings'] ?>"
                            data-cost-basis="<?= $s['invested'] ?>">
                            <td>
                                <strong><?= e($s['token']['symbol']) ?></strong>
                                <small><?= e($s['token']['name']) ?></small>
                            </td>
                            <td data-live="price"><?= formatUSD($s['price']) ?></td>
                            <td class="<?= plClass($s['change24']) ?>" data-live="change24">
                                <?= formatPercent($s['change24']) ?>
                            </td>
                            <td><?= formatCrypto($s['holdings']) ?></td>
                            <td><?= formatUSD($s['avgBuy']) ?></td>
                            <td data-live="currentVal"><?= formatUSD($s['currentVal']) ?></td>
                            <td class="<?= plClass($s['realizedPL']) ?>">
                                <?= formatPL($s['realizedPL']) ?>
                            </td>
                            <td class="<?= plClass($s['unrealizedPL']) ?>" data-live="unrealizedPL">
                                <?= formatPL($s['unrealizedPL']) ?>
                            </td>
                            <td class="<?= plClass($s['totalPL']) ?>" data-live="totalPL">
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
