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

$plPercent    = 0;
$totalPercent = 0;

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

layoutHead(e($token['symbol']));
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
          data-current-price="<?= $price ?>"
          data-precision="<?= precision() ?>"
          data-worthless-zeros="<?= worthlessZeros() ? '1' : '0' ?>"
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
                    <?= formatPL($realizedPL) ?>
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
                <div><span class="label">Avg Buy Price</span><span class="val"><?= formatUSD($avgBuy) ?></span></div>
                <div><span class="label">Cost Basis</span><span class="val"><?= formatUSD($costBasis) ?></span></div>
                <div><span class="label">Current Value</span><span class="val" data-live="currentVal"><span class="loading-skeleton loading-inline">&mdash;</span></span></div>
            </div>
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
                           max="<?= $holdings ?>" required placeholder="0.00" id="sellAmount">

                    <label>Price per unit (USD)</label>
                    <input type="number" name="price_per_unit" step="any" min="0.00000001" required
                           value="" placeholder="Loading price…" id="sellPrice">

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
                        <tr>
                            <td><?= date('M d, Y H:i', strtotime($row['date'])) ?></td>
                            <td>
                                <?php $isBuy = $row['type'] === 'buy'; ?>
                                <span class="badge badge-<?= $row['type'] ?>">
                                    <?= $isBuy ? '+' : '-' ?><?= formatCrypto($row['amount']) ?>
                                </span>
                            </td>
                            <td><?= formatUSD($row['ppu']) ?></td>
                            <td><?= formatUSD($row['total']) ?></td>
                            <td><?= formatCrypto($row['holdings']) ?></td>
                            <td><?= formatUSD($row['avg_cost']) ?></td>
                            <td class="<?= plClass($row['realized']) ?>">
                                <?php if ($row['type'] === 'sell'): ?>
                                    <?= formatPL($row['realized']) ?>
                                <?php else: ?>
                                    –
                                <?php endif; ?>
                            </td>
                            <td class="<?= plClass($row['cum_realized']) ?>"><?= formatPL($row['cum_realized']) ?></td>
                            <td class="<?= plClass($row['unrealized']) ?>"><?= formatPL($row['unrealized']) ?></td>
                            <td class="<?= plClass($row['total_pl']) ?>"><?= formatPL($row['total_pl']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="now-row" data-live-now="1"
                            data-cum-realized="<?= $lastCumRealized ?>">
                            <td data-live-now="date"><?= date('M d, Y H:i') ?></td>
                            <td><span class="badge badge-now">Now</span></td>
                            <td data-live-now="price"><span class="loading-skeleton loading-inline">&mdash;</span></td>
                            <td data-live-now="holdingVal"><span class="loading-skeleton loading-inline">&mdash;</span></td>
                            <td><?= formatCrypto($holdings) ?></td>
                            <td><?= formatUSD($avgBuy) ?></td>
                            <td>–</td>
                            <td class="<?= plClass($lastCumRealized) ?>"><?= formatPL($lastCumRealized) ?></td>
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allTx as $tx): ?>
                        <?php
                            $txKey = $tx['created_at'] . '|' . $tx['type'] . '|' . $tx['amount'];
                            $txPL  = $txPLMap[$txKey] ?? $tx['realized_pl'];
                        ?>
                        <tr>
                            <td><?= date('M d, Y H:i', strtotime($tx['created_at'])) ?></td>
                            <td><span class="badge badge-<?= $tx['type'] ?>"><?= ucfirst($tx['type']) ?></span></td>
                            <td><?= formatCrypto($tx['amount']) ?></td>
                            <td><?= formatUSD($tx['price_per_unit']) ?></td>
                            <td><?= formatUSD($tx['total_value']) ?></td>
                            <td class="<?= plClass($txPL) ?>">
                                <?php if ($tx['type'] === 'sell'): ?>
                                    <?= formatPL($txPL) ?>
                                <?php else: ?>
                                    –
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
            <form method="POST" action="remove_token.php" onsubmit="return confirm('Remove this token and all its transactions?');">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int)$token['id'] ?>">
                <button type="submit" class="btn btn-danger">Remove Token</button>
            </form>
        </section>
    </main>

    <script>
    window._plGraphData = <?= $graphData ?>;
    window._plHiddenSeries = new Set();
    </script>
    <script src="assets/graph.js?v=<?= filemtime(__DIR__ . '/assets/graph.js') ?>"></script>

<?php layoutFooter(); ?>
