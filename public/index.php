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

/* Global banks overview (dashboard-only): the per-token amounts of every wallet
   in one place, so the user sees each bank's composition without opening each
   token. Display-only — reuses the same balances as the token-page Banks section
   and never affects P/L. Live USD values are computed client-side from quotes. */
$banks    = banksList($user['id']);
$hasBanks = count($banks) >= 2;

$bankOverview = [];
if ($hasBanks) {
    $byId = [];
    foreach ($banks as $b) {
        $byId[(int) $b['id']] = [
            'id'         => (int) $b['id'],
            'name'       => $b['name'],
            'is_default' => !empty($b['is_default']) ? 1 : 0,
            'tokens'     => [],
        ];
    }
    foreach ($summaries as $s) {
        if ($s['holdings'] <= BANK_EPS) continue;
        foreach (bankBreakdownForToken($user['id'], (int) $s['token']['id'], $s['holdings']) as $bd) {
            if ($bd['amount'] <= BANK_EPS || !isset($byId[$bd['id']])) continue;
            $byId[$bd['id']]['tokens'][] = [
                'cmc_id' => (int) $s['token']['cmc_id'],
                'symbol' => $s['token']['symbol'],
                'name'   => $s['token']['name'],
                'amount' => $bd['amount'],
            ];
        }
    }
    $bankOverview = array_values($byId);
}

layoutHead('Dashboard');
layoutNav($user);
?>

    <main class="container" data-page="dashboard"
          data-precision="<?= precision() ?>"
          data-worthless-zeros="<?= worthlessZeros() ? '1' : '0' ?>"
          data-scientific="<?= scientificNotation() ? '1' : '0' ?>"
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

        <div class="dashboard-grid<?= $hasBanks ? ' has-aside' : '' ?>">
        <div class="dashboard-main">

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

        </div>

        <?php if ($hasBanks): ?>
        <aside class="bank-overview animate-fade-in-up">
            <h2>&#127974; Banks</h2>
            <p class="bank-ov-intro">Your holdings across wallets. Hover for the top tokens, click for the full breakdown.</p>
            <div class="bank-ov-list">
                <?php foreach ($bankOverview as $bk): $n = count($bk['tokens']); ?>
                <button type="button" class="bank-ov-card" data-bank-id="<?= (int) $bk['id'] ?>">
                    <span class="bank-ov-head">
                        <span class="bank-ov-name">
                            <?= e($bk['name']) ?>
                            <?php if ($bk['is_default']): ?><span class="bank-badge">default</span><?php endif; ?>
                        </span>
                        <span class="bank-ov-count"><?= $n ?> token<?= $n === 1 ? '' : 's' ?></span>
                    </span>
                    <span class="bank-ov-value" data-bank-ov-value><span class="loading-skeleton loading-inline">&mdash;</span></span>
                    <span class="bank-ov-pop" data-bank-ov-pop></span>
                </button>
                <?php endforeach; ?>
            </div>
        </aside>
        <?php endif; ?>

        </div>
    </main>

    <?php if ($hasBanks): ?>
    <div class="modal-overlay" id="bankOverviewModal">
        <div class="modal animate-scale-in">
            <div class="modal-header">
                <h2><span class="bank-ov-modal-icon">&#127974;</span> <span id="bankOvModalName">Bank</span></h2>
                <button type="button" class="modal-close" id="closeBankOv">&times;</button>
            </div>
            <div class="modal-body">
                <div class="bank-ov-modal-total">
                    <span>Total value</span>
                    <strong id="bankOvModalTotal">&mdash;</strong>
                </div>
                <div class="table-responsive">
                    <table class="token-table bank-ov-table">
                        <thead>
                            <tr><th>Token</th><th>Amount</th><th>Price</th><th>Value</th></tr>
                        </thead>
                        <tbody id="bankOvModalBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script>window._bankOverview = <?= json_encode($bankOverview, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <?php endif; ?>

<?php layoutFooter(); ?>
