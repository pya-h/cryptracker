<?php
/**
 * Single Token Page — detailed P/L, market data, analytics, and trade forms.
 */

require_once __DIR__ . '/../cryptracker/includes/auth.php';
require_once __DIR__ . '/../cryptracker/includes/helpers.php';

$user = requireAuth();

$tokenId = (int) ($_GET['id'] ?? 0);
$token   = dbGetUserToken($tokenId, $user['id']);

if (!$token) { header('Location: index.php'); exit; }

/* Prices loaded asynchronously via JS — page renders instantly */
$price = 0; $change1h = 0; $change24 = 0; $change7d = 0;
$marketCap = 0; $volume24 = 0; $csupply = 0; $tsupply = 0; $msupply = 0; $rank = 0;

$allTxAsc = dbGetTransactions($tokenId);
$allTx    = array_reverse($allTxAsc);

/* Swap legs carry a JSON snapshot in `metadata`; decode it (adding a display
   date) for the "⇄" chips and the swap-details modal. Legs recorded before this
   feature have no metadata and simply fall back to their plain note. */
$swapChipData = function (array $tx): ?array {
    if (empty($tx['metadata'])) return null;
    $m = json_decode((string) $tx['metadata'], true);
    if (!is_array($m) || empty($m['swap']) || !is_array($m['swap'])) return null;
    $s = $m['swap'];
    $s['date'] = date('M d, Y H:i', strtotime($tx['created_at']));
    return $s;
};

$swapByKey = [];
foreach ($allTxAsc as $t) {
    $s = $swapChipData($t);
    if ($s !== null) $swapByKey[$t['created_at'] . '|' . $t['type'] . '|' . $t['amount']] = $s;
}
$hasSwaps = !empty($swapByKey);

/* Which transaction row (if any) shown on this page is the user's last,
   undoable action. A swap has one leg per token, so at most one row matches. */
$undoable  = getUndoableAction($user['id']);
$undoIds   = $undoable ? array_flip($undoable['tx_ids']) : [];
$undoKind  = $undoable['kind'] ?? '';
$undoPlural = ($undoable && count($undoable['tx_ids']) > 1);

$mode = plMode();
$pl   = ($mode === 'fifo') ? _calcFifo($allTxAsc, 0) : _calcAvg($allTxAsc, 0);

$holdings     = $pl['holdings'];
$avgBuy       = $pl['avg_buy'];
$costBasis    = $pl['cost_basis'];
$currentValue = 0;
$realizedPL   = $pl['realized_pl'];
$unrealizedPL = 0;
$totalPL      = $realizedPL;
$totalSpent   = $pl['total_spent'];
$plTimeline   = $pl['timeline'];

$lastCumRealized = !empty($plTimeline) ? end($plTimeline)['cum_realized'] : 0;
$lastEntry       = !empty($plTimeline) ? end($plTimeline) : null;

$graphPoints = array_map(fn($p) => [
    'date'         => $p['date'],
    'total_pl'     => $p['total_pl'],
    'unrealized'   => $p['unrealized'],
    'cum_realized' => $p['cum_realized'],
], $plTimeline);

$graphPoints[] = [
    'date'         => date('Y-m-d H:i:s'),
    'total_pl'     => $lastEntry ? $lastEntry['total_pl'] : 0,
    'unrealized'   => $lastEntry ? $lastEntry['unrealized'] : 0,
    'cum_realized' => $lastCumRealized,
    'is_now'       => true,
];

$graphData = json_encode($graphPoints);

/* Other tracked tokens available as swap targets (for the Convert modal). */
$swapTargets = [];
foreach (dbGetUserTokens($user['id']) as $t) {
    if ((int) $t['id'] === (int) $tokenId) continue;
    $tpl = calcTokenPL((int) $t['id'], 0, $mode);
    $swapTargets[] = [
        'id'       => (int) $t['id'],
        'cmc_id'   => (int) $t['cmc_id'],
        'symbol'   => $t['symbol'],
        'name'     => $t['name'],
        'holdings' => $tpl['holdings'],
    ];
}

/* Banks (wallets) — display-only segmentation of this token's holdings. The
   per-bank selector on trade forms only appears once the user has 2+ banks. */
$banks         = banksList($user['id']);
$bankCount     = count($banks);
$hasBanks      = $bankCount >= 2;
$bankBreakdown = bankBreakdownForToken($user['id'], $tokenId, $holdings);

layoutHead($token['symbol']);
layoutNav($user);
?>

    <main class="container" data-page="token" data-token-id="<?= (int)$tokenId ?>"
          data-cmc-id="<?= (int)$token['cmc_id'] ?>"
          data-holdings="<?= $holdings ?>"
          data-cost-basis="<?= $costBasis ?>"
          data-avg-buy="<?= $avgBuy ?>"
          data-realized-pl="<?= $realizedPL ?>"
          data-unrealized-pl="<?= $unrealizedPL ?>"
          data-total-spent="<?= $totalSpent ?>"
          data-pl-mode="<?= e($mode) ?>"
          data-symbol="<?= e($token['symbol']) ?>"
          data-name="<?= e($token['name']) ?>"
          data-current-price="<?= $price ?>"
          data-precision="<?= precision() ?>"
          data-worthless-zeros="<?= worthlessZeros() ? '1' : '0' ?>"
          data-scientific="<?= scientificNotation() ? '1' : '0' ?>"
          <?= currencyDataAttrs() ?>
          data-pl-timeline="<?= e(json_encode($plTimeline)) ?>"
          data-transactions="<?= e(json_encode($allTxAsc)) ?>"
          data-deferred-prices="1">
        <a href="index.php" class="back-link">&larr; Back to Dashboard</a>

        <section class="token-header">
            <div>
                <h1>
                    <span class="rank-badge" data-live="rank" style="display:none"></span>
                    <?= e($token['name']) ?>
                    <span class="symbol-badge"><?= e($token['symbol']) ?></span>
                </h1>
                <p class="live-price">
                    Current Price: <strong data-live="price"><span class="loading-skeleton loading-inline">&mdash;</span></strong>
                    <span class="price-badge" data-live="change24"><span class="loading-skeleton loading-inline">&mdash;</span></span>
                </p>
            </div>
        </section>

        <?= renderFlashes() ?>

        <section class="pl-cards">
            <div class="pl-card">
                <h3>Realized P/L</h3>
                <p class="pl-value <?= plClass($realizedPL) ?>" data-countup="<?= $realizedPL ?>" data-pl="1">
                    <?= formatMoneyPL($realizedPL) ?>
                </p>
                <small>From completed sells vs buy cost</small>
            </div>
            <div class="pl-card">
                <h3>Unrealized P/L</h3>
                <p class="pl-value" data-live="unrealizedPL">
                    <span class="loading-skeleton loading-inline">&mdash;</span>
                    <span class="pl-percent" data-live="unrealizedPercent"></span>
                </p>
                <small>If you sell <?= formatCrypto($holdings) ?> <?= e($token['symbol']) ?> now</small>
            </div>
            <div class="pl-card highlight">
                <h3>Total P/L</h3>
                <p class="pl-value" data-live="totalPL">
                    <span class="loading-skeleton loading-inline">&mdash;</span>
                    <span class="pl-percent" data-live="totalPercent"></span>
                </p>
                <small>Realized + Unrealized</small>
            </div>
        </section>

        <section class="holdings-info">
            <div class="info-grid">
                <div><span class="label">Holdings</span><span class="val"><?= formatCrypto($holdings) ?> <?= e($token['symbol']) ?></span></div>
                <div><span class="label">Avg Buy Price</span><span class="val"><?= formatMoney($avgBuy) ?></span></div>
                <div><span class="label">Cost Basis</span><span class="val"><?= formatMoney($costBasis) ?></span></div>
                <div><span class="label">Current Value</span><span class="val" data-live="currentVal"><span class="loading-skeleton loading-inline">&mdash;</span></span></div>
            </div>
        </section>

        <section class="banks-wrap animate-fade-in-up">
            <details class="banks-details">
                <summary class="banks-summary">
                    <span class="banks-summary-title">&#127974; Banks</span>
                    <span class="banks-summary-sub">Split your <?= e($token['symbol']) ?> across wallets</span>
                    <span class="banks-caret" aria-hidden="true">&#9662;</span>
                </summary>
                <div class="banks-panel">
                    <p class="banks-intro">
                        Track how your holdings are spread across wallets or exchanges. This is for
                        your own reference only &mdash; it never affects P/L, charts, or history.
                        Your <strong><?= formatCrypto($holdings) ?> <?= e($token['symbol']) ?></strong> is split as:
                    </p>

                    <div class="bank-list" id="bankList">
                        <?php foreach ($bankBreakdown as $b): ?>
                        <div class="bank-row" data-bank-balance="<?= $b['amount'] ?>">
                            <span class="bank-name">
                                <?= e($b['name']) ?>
                                <?php if ($b['is_default']): ?><span class="bank-badge">default</span><?php endif; ?>
                            </span>
                            <span class="bank-amount"><?= formatCrypto($b['amount']) ?> <?= e($token['symbol']) ?></span>
                            <span class="bank-value" data-bank-value>&mdash;</span>
                            <?php if (!$b['is_default']): ?>
                            <form method="POST" action="bank.php" class="bank-del-form"
                                  data-confirm="Remove the bank &quot;<?= e($b['name']) ?>&quot;? It must be empty of every token first.">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="bank_id" value="<?= (int) $b['id'] ?>">
                                <input type="hidden" name="return_id" value="<?= (int) $tokenId ?>">
                                <button type="submit" class="bank-del-btn" title="Remove bank" aria-label="Remove bank">&times;</button>
                            </form>
                            <?php else: ?>
                            <span class="bank-del-spacer" aria-hidden="true"></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="bank-actions">
                        <form method="POST" action="bank.php" class="bank-add-form">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="return_id" value="<?= (int) $tokenId ?>">
                            <input type="text" name="bank_name" maxlength="40" required placeholder="New bank name"
                                   pattern="[A-Za-z0-9 ._#()\-]{1,40}"
                                   title="1-40 characters: letters, numbers, spaces, . _ - # ( )">
                            <button type="submit" class="btn btn-primary btn-sm">+ Add Bank</button>
                        </form>

                        <?php if ($hasBanks): ?>
                        <form method="POST" action="bank.php" class="bank-move-form">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="token_id" value="<?= (int) $tokenId ?>">
                            <input type="hidden" name="return_id" value="<?= (int) $tokenId ?>">
                            <span class="bank-move-title">Move <?= e($token['symbol']) ?></span>
                            <input type="number" name="amount" step="any" min="0.00000001" required
                                   placeholder="Amount" id="bankMoveAmount">
                            <select name="from_bank_id" id="bankMoveFrom" class="bank-select">
                                <?php foreach ($bankBreakdown as $b): ?>
                                <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?><?= $b['is_default'] ? ' (default)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="bank-move-arrow" aria-hidden="true">&rarr;</span>
                            <select name="to_bank_id" id="bankMoveTo" class="bank-select">
                                <?php foreach ($bankBreakdown as $b): ?>
                                <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?><?= $b['is_default'] ? ' (default)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-primary btn-sm">Move</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </details>
        </section>

        <section class="market-data-section animate-fade-in-up">
            <h2>Market Data</h2>
            <div class="market-grid">
                <div class="market-item">
                    <span class="label">1h Change</span>
                    <span class="val" data-live-market="change1h"><span class="loading-skeleton loading-inline">&mdash;</span></span>
                </div>
                <div class="market-item">
                    <span class="label">24h Change</span>
                    <span class="val" data-live-market="change24"><span class="loading-skeleton loading-inline">&mdash;</span></span>
                </div>
                <div class="market-item">
                    <span class="label">7d Change</span>
                    <span class="val" data-live-market="change7d"><span class="loading-skeleton loading-inline">&mdash;</span></span>
                </div>
                <div class="market-item">
                    <span class="label">Market Cap</span>
                    <span class="val" data-live-market="marketCap"><span class="loading-skeleton loading-inline">&mdash;</span></span>
                </div>
                <div class="market-item">
                    <span class="label">24h Volume</span>
                    <span class="val" data-live-market="volume24"><span class="loading-skeleton loading-inline">&mdash;</span></span>
                </div>
                <div class="market-item" data-live-market-parent="csupply">
                    <span class="label">Circulating Supply</span>
                    <span class="val" data-live-market="csupply"><span class="loading-skeleton loading-inline">&mdash;</span></span>
                </div>
                <div class="market-item" data-live-market-parent="tsupply" style="display:none">
                    <span class="label">Total Supply</span>
                    <span class="val" data-live-market="tsupply"></span>
                </div>
                <div class="market-item" data-live-market-parent="msupply" style="display:none">
                    <span class="label">Max Supply</span>
                    <span class="val" data-live-market="msupply"></span>
                </div>
            </div>
        </section>

        <section class="trade-forms">
            <div class="trade-card buy-card">
                <h3>Buy <?= e($token['symbol']) ?></h3>
                <form method="POST" action="transaction.php" id="buyForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="user_token_id" value="<?= (int)$token['id'] ?>">
                    <input type="hidden" name="type" value="buy">

                    <label>Amount (<?= e($token['symbol']) ?>)</label>
                    <input type="number" name="amount" step="any" min="0.00000001" required placeholder="0.00" id="buyAmount">

                    <label>Price per unit (USD)</label>
                    <input type="number" name="price_per_unit" step="any" min="0.00000001" required
                           value="" placeholder="Loading price…" id="buyPrice">

                    <?php if ($hasBanks): ?>
                    <label>Deposit into bank</label>
                    <select name="bank_id" id="buyBankSelect" class="bank-select">
                        <?php foreach ($banks as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?><?= !empty($b['is_default']) ? ' (default)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <div class="future-preview" id="buyPreview" style="display:none;">
                        <div class="future-preview-label">After this buy:</div>
                        <div class="future-preview-row">
                            <span class="label">Unrealized P/L</span>
                            <span class="val" id="buyPreviewUnrealized">—</span>
                        </div>
                        <div class="future-preview-row">
                            <span class="label">New Holdings</span>
                            <span class="val" id="buyPreviewHoldings">—</span>
                        </div>
                        <div class="future-preview-row">
                            <span class="label">New Avg Cost</span>
                            <span class="val" id="buyPreviewAvgCost">—</span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-buy">Buy</button>
                </form>
            </div>

            <div class="trade-card sell-card">
                <h3>Sell <?= e($token['symbol']) ?></h3>
                <form method="POST" action="transaction.php" id="sellForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="user_token_id" value="<?= (int)$token['id'] ?>">
                    <input type="hidden" name="type" value="sell">

                    <label>Amount (<?= e($token['symbol']) ?>)</label>
                    <input type="number" name="amount" step="any" min="0.00000001"
                           max="<?= $hasBanks ? $bankBreakdown[0]['amount'] : $holdings ?>" required placeholder="0.00" id="sellAmount">

                    <label>Price per unit (USD)</label>
                    <input type="number" name="price_per_unit" step="any" min="0.00000001" required
                           value="" placeholder="Loading price…" id="sellPrice">

                    <?php if ($hasBanks): ?>
                    <label>Sell from bank</label>
                    <select name="bank_id" id="sellBankSelect" class="bank-select">
                        <?php foreach ($banks as $b): ?>
                        <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?><?= !empty($b['is_default']) ? ' (default)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="bank-hint" id="sellBankHint"></small>
                    <?php endif; ?>

                    <div class="future-preview" id="sellPreview" style="display:none;">
                        <div class="future-preview-label">After this sell:</div>
                        <div class="future-preview-row">
                            <span class="label">Realized P/L</span>
                            <span class="val" id="sellPreviewRealized">—</span>
                        </div>
                        <div class="future-preview-row">
                            <span class="label">New Holdings</span>
                            <span class="val" id="sellPreviewHoldings">—</span>
                        </div>
                        <div class="future-preview-row">
                            <span class="label">Cum. Realized</span>
                            <span class="val" id="sellPreviewCumRealized">—</span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-sell" <?= $holdings <= 0 ? 'disabled' : '' ?>>Sell</button>
                </form>
            </div>
        </section>

        <?php if (!empty($swapTargets)): ?>
        <section class="swap-launch animate-fade-in-up">
            <div class="swap-launch-inner">
                <div class="swap-launch-text">
                    <h3><span class="swap-launch-icon">&#8646;</span> Convert <?= e($token['symbol']) ?></h3>
                    <p>Directly swap <?= e($token['symbol']) ?> into another token you track — records the sell and buy in one step.</p>
                </div>
                <div class="swap-launch-controls">
                    <select id="swapTargetSelect" aria-label="Convert into">
                        <?php foreach ($swapTargets as $st): ?>
                        <option value="<?= (int) $st['id'] ?>"><?= e($st['symbol']) ?> &mdash; <?= e($st['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-primary" id="openSwap" <?= $holdings <= 0 ? 'disabled' : '' ?>>Convert</button>
                </div>
            </div>
            <?php if ($holdings <= 0): ?>
            <p class="swap-launch-note">You have no <?= e($token['symbol']) ?> holdings to convert.</p>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if (!empty($plTimeline)): ?>
        <section class="pl-analytics animate-fade-in-up">
            <h2>P/L Analytics</h2>

            <div class="graph-container" id="graphContainer">
                <canvas id="plGraph" width="800" height="280"></canvas>
                <div id="graphSelRect" class="graph-sel-rect"></div>
                <div id="graphTooltip" class="graph-tooltip"></div>
                <div id="graphLegend" class="graph-legend"></div>
            </div>

            <div class="table-responsive">
                <table class="token-table analytics-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Price/Unit</th>
                            <th>Total</th>
                            <th>Holdings</th>
                            <th>Avg Cost</th>
                            <th>Realized P/L</th>
                            <th>Cum. Realized</th>
                            <th>Unrealized P/L</th>
                            <th>Total P/L</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($plTimeline as $row): ?>
                        <?php $rowSwap = $swapByKey[$row['date'] . '|' . $row['type'] . '|' . $row['amount']] ?? null; ?>
                        <tr>
                            <td><?= date('M d, Y H:i', strtotime($row['date'])) ?></td>
                            <td>
                                <?php $isBuy = $row['type'] === 'buy'; ?>
                                <span class="badge badge-<?= $row['type'] ?>">
                                    <?= $isBuy ? '+' : '-' ?><?= formatCrypto($row['amount']) ?>
                                </span>
                                <?php if ($rowSwap !== null): ?>
                                <button type="button" class="swap-chip swap-chip-icon" data-swap="<?= e(json_encode($rowSwap)) ?>" title="Swap details" aria-label="Swap details">&#8646;</button>
                                <?php endif; ?>
                            </td>
                            <td><?= formatMoney($row['ppu']) ?></td>
                            <td><?= formatMoney($row['total']) ?></td>
                            <td><?= formatCrypto($row['holdings']) ?></td>
                            <td><?= formatMoney($row['avg_cost']) ?></td>
                            <td class="<?= plClass($row['realized']) ?>">
                                <?php if ($row['type'] === 'sell'): ?>
                                    <?= formatMoneyPL($row['realized']) ?>
                                <?php else: ?>
                                    –
                                <?php endif; ?>
                            </td>
                            <td class="<?= plClass($row['cum_realized']) ?>"><?= formatMoneyPL($row['cum_realized']) ?></td>
                            <td class="<?= plClass($row['unrealized']) ?>"><?= formatMoneyPL($row['unrealized']) ?></td>
                            <td class="<?= plClass($row['total_pl']) ?>"><?= formatMoneyPL($row['total_pl']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="now-row" data-live-now="1"
                            data-cum-realized="<?= $lastCumRealized ?>">
                            <td data-live-now="date"><?= date('M d, Y H:i') ?></td>
                            <td><span class="badge badge-now">Now</span></td>
                            <td data-live-now="price"><span class="loading-skeleton loading-inline">&mdash;</span></td>
                            <td data-live-now="holdingVal"><span class="loading-skeleton loading-inline">&mdash;</span></td>
                            <td><?= formatCrypto($holdings) ?></td>
                            <td><?= formatMoney($avgBuy) ?></td>
                            <td>–</td>
                            <td class="<?= plClass($lastCumRealized) ?>"><?= formatMoneyPL($lastCumRealized) ?></td>
                            <td data-live-now="unrealized"><span class="loading-skeleton loading-inline">&mdash;</span></td>
                            <td data-live-now="totalPL"><span class="loading-skeleton loading-inline">&mdash;</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>

        <section class="tx-history">
            <h2>Transaction History</h2>
            <?php if (empty($allTx)): ?>
                <p class="empty-state">No transactions yet. Record a buy above!</p>
            <?php else: ?>
            <?php
                $txPLMap = [];
                foreach ($plTimeline as $row) {
                    $key = $row['date'] . '|' . $row['type'] . '|' . $row['amount'];
                    $txPLMap[$key] = $row['realized'];
                }
            ?>
            <div class="table-responsive">
                <table class="token-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Price/Unit</th>
                            <th>Total</th>
                            <th>Realized P/L</th>
                            <th aria-label="Actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allTx as $tx): ?>
                        <?php
                            $txKey = $tx['created_at'] . '|' . $tx['type'] . '|' . $tx['amount'];
                            $txPL  = $txPLMap[$txKey] ?? $tx['realized_pl'];
                            $isUndoable = isset($undoIds[(int) $tx['id']]);
                            $txSwap = $swapChipData($tx);
                        ?>
                        <tr<?= $isUndoable ? ' class="tx-undoable"' : '' ?>>
                            <td><?= date('M d, Y H:i', strtotime($tx['created_at'])) ?></td>
                            <td>
                                <span class="badge badge-<?= $tx['type'] ?>"><?= ucfirst($tx['type']) ?></span>
                                <?php if ($txSwap !== null): ?>
                                <button type="button" class="swap-chip" data-swap="<?= e(json_encode($txSwap)) ?>" title="Swap details">
                                    <span class="swap-chip-icon">&#8646;</span><?= e($tx['type'] === 'sell' ? $txSwap['to_symbol'] : $txSwap['from_symbol']) ?>
                                </button>
                                <?php elseif (!empty($tx['note'])): ?><small class="tx-note"><?= e($tx['note']) ?></small><?php endif; ?>
                            </td>
                            <td><?= formatCrypto($tx['amount']) ?></td>
                            <td><?= formatMoney($tx['price_per_unit']) ?></td>
                            <td><?= formatMoney($tx['total_value']) ?></td>
                            <td class="<?= plClass($txPL) ?>">
                                <?php if ($tx['type'] === 'sell'): ?>
                                    <?= formatMoneyPL($txPL) ?>
                                <?php else: ?>
                                    –
                                <?php endif; ?>
                            </td>
                            <td class="tx-action">
                                <?php if ($isUndoable): ?>
                                <form method="POST" action="undo.php" class="undo-form"
                                      data-confirm="Undo this <?= e($undoKind) ?>? This permanently removes the record<?= $undoPlural ? 's for both tokens' : '' ?>.">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="return_id" value="<?= (int) $tokenId ?>">
                                    <button type="submit" class="undo-btn" title="Undo this <?= e($undoKind) ?>" aria-label="Undo this <?= e($undoKind) ?>">&#8634;</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </section>

        <section class="danger-zone">
            <form method="POST" action="remove_token.php" data-confirm="Remove this token and all its transactions?">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int)$token['id'] ?>">
                <button type="submit" class="btn btn-danger">Remove Token</button>
            </form>
        </section>
    </main>

    <?php if ($hasSwaps): ?>
    <div class="modal-overlay" id="swapInfoOverlay">
        <div class="modal modal-swap-info animate-scale-in">
            <div class="modal-header">
                <h2><span class="swap-info-head-icon">&#8646;</span> Swap details</h2>
                <button type="button" class="modal-close" id="closeSwapInfo">&times;</button>
            </div>
            <div class="modal-body">
                <div class="swap-info-flow">
                    <div class="swap-info-side">
                        <span class="swap-info-role">Gave</span>
                        <strong id="swiFromAmt">&mdash;</strong>
                        <span id="swiFromPrice" class="swap-info-sub">&mdash;</span>
                    </div>
                    <span class="swap-info-arrow" aria-hidden="true">&#8594;</span>
                    <div class="swap-info-side">
                        <span class="swap-info-role">Received</span>
                        <strong id="swiToAmt">&mdash;</strong>
                        <span id="swiToPrice" class="swap-info-sub">&mdash;</span>
                    </div>
                </div>
                <div class="swap-info-rows">
                    <div><span>Value</span><strong id="swiValue" class="swap-info-figure">&mdash;</strong></div>
                    <div><span>Realized P/L</span><strong id="swiPl" class="swap-info-figure">&mdash;</strong></div>
                    <div><span>Rate</span><strong id="swiRate" class="swap-info-figure">&mdash;</strong></div>
                    <div><span>Date</span><strong id="swiDate" class="swap-info-figure">&mdash;</strong></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($swapTargets)): ?>
    <div class="modal-overlay" id="swapOverlay">
        <div class="modal modal-swap animate-scale-in">
            <div class="modal-header">
                <h2>Convert <?= e($token['symbol']) ?> <span class="swap-head-arrow">&#8594;</span> <span class="js-symB">&mdash;</span></h2>
                <button type="button" class="modal-close" id="closeSwap">&times;</button>
            </div>
            <div class="modal-body">
                <div class="swap-tokens">
                    <div class="swap-token-panel">
                        <span class="swap-token-role">From</span>
                        <span class="swap-token-sym"><?= e($token['symbol']) ?></span>
                        <div class="swap-token-meta">
                            <div><span>Price</span><strong id="swapFromPrice">&mdash;</strong></div>
                            <div><span>Holdings</span><strong id="swapFromHoldings">&mdash;</strong></div>
                            <div><span>Value</span><strong id="swapFromValue">&mdash;</strong></div>
                        </div>
                    </div>
                    <div class="swap-token-panel">
                        <span class="swap-token-role">To</span>
                        <span class="swap-token-sym js-symB">&mdash;</span>
                        <div class="swap-token-meta">
                            <div><span>Price</span><strong id="swapToPrice">&mdash;</strong></div>
                            <div><span>Holdings</span><strong id="swapToHoldings">&mdash;</strong></div>
                            <div><span>Value</span><strong id="swapToValue">&mdash;</strong></div>
                        </div>
                    </div>
                </div>

                <form method="POST" action="swap.php" id="swapForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="user_token_id" value="<?= (int) $token['id'] ?>">
                    <input type="hidden" name="target_token_id" id="swapTargetId" value="">

                    <?php if ($hasBanks): ?>
                    <div class="swap-banks">
                        <div class="swap-bank-field">
                            <label for="swapSourceBank">From bank (<?= e($token['symbol']) ?>)</label>
                            <select name="source_bank_id" id="swapSourceBank" class="bank-select">
                                <?php foreach ($banks as $b): ?>
                                <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?><?= !empty($b['is_default']) ? ' (default)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="swap-bank-field">
                            <label for="swapTargetBank">Into bank (<span class="js-symB">&mdash;</span>)</label>
                            <select name="target_bank_id" id="swapTargetBank" class="bank-select">
                                <?php foreach ($banks as $b): ?>
                                <option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?><?= !empty($b['is_default']) ? ' (default)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php endif; ?>

                    <label for="swapAmountA">You convert (<?= e($token['symbol']) ?>)</label>
                    <div class="swap-input-row">
                        <input type="number" step="any" min="0" name="amount_a" id="swapAmountA" placeholder="0.00" autocomplete="off" inputmode="decimal">
                        <button type="button" class="swap-max" id="swapMax">Max</button>
                    </div>

                    <div class="swap-mid" aria-hidden="true">&#8645;</div>

                    <label for="swapAmountB">You receive (<span class="js-symB">&mdash;</span>)</label>
                    <input type="number" step="any" min="0" name="amount_b" id="swapAmountB" placeholder="0.00" autocomplete="off" inputmode="decimal">

                    <p class="swap-rate" id="swapRate">&mdash;</p>

                    <details class="swap-advanced" id="swapAdvanced">
                        <summary>Advanced pricing</summary>
                        <div class="swap-advanced-body">
                            <label for="swapPriceA">Price of <?= e($token['symbol']) ?> (USD)</label>
                            <input type="number" step="any" min="0" name="price_a" id="swapPriceA" placeholder="0.00" autocomplete="off" inputmode="decimal">
                            <label for="swapPriceB">Price of <span class="js-symB">&mdash;</span> (USD)</label>
                            <input type="number" step="any" min="0" name="price_b" id="swapPriceB" placeholder="0.00" autocomplete="off" inputmode="decimal">
                            <label for="swapRatioInput">Ratio (1 <?= e($token['symbol']) ?> = ? <span class="js-symB">&mdash;</span>)</label>
                            <input type="number" step="any" min="0" id="swapRatioInput" placeholder="0.00" autocomplete="off" inputmode="decimal">
                            <p class="swap-hint">Defaults to live market prices. Adjust to record a custom rate &mdash; the value given up always equals the value received.</p>
                        </div>
                    </details>

                    <p class="swap-error" id="swapError" style="display:none;"></p>

                    <button type="submit" class="btn btn-primary swap-submit" id="swapSubmit">Swap</button>
                </form>
            </div>
        </div>
    </div>
    <script>window._swapTargets = <?= json_encode($swapTargets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <?php endif; ?>

    <script>
    window._plGraphData = <?= $graphData ?>;
    window._plHiddenSeries = new Set();
    window._banks = <?= json_encode($bankBreakdown, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window._hasBanks = <?= $hasBanks ? 'true' : 'false' ?>;
    </script>
    <script src="assets/graph.js?v=<?= filemtime(__DIR__ . '/assets/graph.js') ?>"></script>

<?php layoutFooter(); ?>
