<?php
/**
 * Main Dashboard – lists user's tracked tokens with summary P/L.
 */

require_once __DIR__ . '/../cryptracker/includes/auth.php';
require_once __DIR__ . '/../cryptracker/includes/helpers.php';

$user = requireAuth();
$tokens = dbGetUserTokens($user['id']);
$mode = plMode();
$summaries     = [];
$totalRealPL   = 0;
$totalInvested = 0;

/* Prices loaded asynchronously via JS — page renders instantly */
foreach ($tokens as $tk) {
    $id = $tk['id'];
    $pl = calcTokenPL($id, 0, $mode);

    $summaries[] = [
        'token'       => $tk,
        'holdings'    => $pl['holdings'],
        'avgBuy'      => $pl['avg_buy'],
        'invested'    => $pl['cost_basis'],
        'realizedPL'  => $pl['realized_pl'],
    ];

    $totalRealPL   += $pl['realized_pl'];
    $totalInvested += $pl['cost_basis'];
}

layoutHead('Dashboard');
layoutNav($user);
?>

    <main class="container" data-page="dashboard"
          data-precision="<?= precision() ?>"
          data-worthless-zeros="<?= worthlessZeros() ? '1' : '0' ?>"
          <?= currencyDataAttrs() ?>
          <?= !empty($summaries) ? 'data-deferred-prices="1"' : '' ?>>

        <?= renderFlashes() ?>

        <section class="portfolio-summary">
            <div class="summary-card stagger-1">
                <span class="summary-label">Total Invested</span>
                <span class="summary-value" data-countup="<?= $totalInvested ?>"><?= formatMoney($totalInvested) ?></span>
            </div>
            <div class="summary-card stagger-2">
                <span class="summary-label">Realized P/L</span>
                <span class="summary-value <?= plClass($totalRealPL) ?>" data-countup="<?= $totalRealPL ?>" data-pl="1">
                    <?= formatMoneyPL($totalRealPL) ?>
                </span>
            </div>
            <div class="summary-card stagger-3">
                <span class="summary-label">Unrealized P/L</span>
                <?php if (!empty($summaries)): ?>
                <span class="summary-value" data-live="unrealized-total">
                    <span class="loading-skeleton loading-inline">&mdash;</span>
                </span>
                <?php else: ?>
                <span class="summary-value"><?= formatMoneyPL(0) ?></span>
                <?php endif; ?>
            </div>
            <div class="summary-card highlight stagger-4">
                <span class="summary-label">Total P/L</span>
                <?php if (!empty($summaries)): ?>
                <span class="summary-value" data-live="total-pl-total">
                    <span class="loading-skeleton loading-inline">&mdash;</span>
                </span>
                <?php else: ?>
                <span class="summary-value <?= plClass($totalRealPL) ?>"><?= formatMoneyPL($totalRealPL) ?></span>
                <?php endif; ?>
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
                            data-cost-basis="<?= $s['invested'] ?>"
                            data-realized-pl="<?= $s['realizedPL'] ?>">
                            <td>
                                <strong><?= e($s['token']['symbol']) ?></strong>
                                <small><?= e($s['token']['name']) ?></small>
                            </td>
                            <td data-live="price"><span class="loading-skeleton loading-inline">&mdash;</span></td>
                            <td data-live="change24"><span class="loading-skeleton loading-inline">&mdash;</span></td>
                            <td><?= formatCrypto($s['holdings']) ?></td>
                            <td><?= formatMoney($s['avgBuy']) ?></td>
                            <td data-live="currentVal"><span class="loading-skeleton loading-inline">&mdash;</span></td>
                            <td class="<?= plClass($s['realizedPL']) ?>">
                                <?= formatMoneyPL($s['realizedPL']) ?>
                            </td>
                            <td data-live="unrealizedPL"><span class="loading-skeleton loading-inline">&mdash;</span></td>
                            <td data-live="totalPL"><span class="loading-skeleton loading-inline">&mdash;</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>
    </main>

<?php layoutFooter(); ?>
