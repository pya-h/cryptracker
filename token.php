<?php
/**
 * Single Token Page — detailed P/L, market data, analytics, and trade forms.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/helpers.php';

$user = requireAuth();

$tokenId = (int) ($_GET['id'] ?? 0);
$token   = dbGetUserToken($tokenId, $user['id']);

if (!$token) { header('Location: index.php'); exit; }

$quotes   = apiGetQuotes([$token['cmc_id']]);
$quote    = $quotes[$token['cmc_id']] ?? [];
$price    = $quote['price'] ?? 0;
$change1h = $quote['percent_change_1h'] ?? 0;
$change24 = $quote['percent_change_24h'] ?? 0;
$change7d = $quote['percent_change_7d'] ?? 0;
$marketCap = $quote['market_cap'] ?? 0;
$volume24  = $quote['volume_24h'] ?? 0;
$csupply   = $quote['csupply'] ?? 0;
$tsupply   = $quote['tsupply'] ?? 0;
$msupply   = $quote['msupply'] ?? 0;
$rank      = $quote['rank'] ?? 0;

$allTxAsc = dbGetTransactions($tokenId);
$allTx    = array_reverse($allTxAsc);

$mode = plMode();
$pl   = ($mode === 'fifo') ? _calcFifo($allTxAsc, $price) : _calcAvg($allTxAsc, $price);

$holdings     = $pl['holdings'];
$avgBuy       = $pl['avg_buy'];
$costBasis    = $pl['cost_basis'];
$currentValue = $pl['current_value'];
$realizedPL   = $pl['realized_pl'];
$unrealizedPL = $pl['unrealized_pl'];
$totalPL      = $pl['total_pl'];
$totalSpent   = $pl['total_spent'];
$plTimeline   = $pl['timeline'];

$plPercent    = ($costBasis > 0) ? (($unrealizedPL / $costBasis) * 100) : 0;
$totalPercent = ($totalSpent > 0) ? (($totalPL / $totalSpent) * 100) : 0;

$lastCumRealized = !empty($plTimeline) ? end($plTimeline)['cum_realized'] : 0;

$graphPoints = array_map(fn($p) => [
    'date'         => $p['date'],
    'total_pl'     => $p['total_pl'],
    'unrealized'   => $p['unrealized'],
    'cum_realized' => $p['cum_realized'],
], $plTimeline);

$graphPoints[] = [
    'date'         => date('Y-m-d H:i:s'),
    'total_pl'     => $totalPL,
    'unrealized'   => $unrealizedPL,
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
          data-transactions="<?= e(json_encode($allTxAsc)) ?>">
        <a href="index.php" class="back-link">&larr; Back to Dashboard</a>

        <section class="token-header">
            <div>
                <h1>
                    <?php if ($rank > 0): ?><span class="rank-badge">#<?= $rank ?></span><?php endif; ?>
                    <?= e($token['name']) ?>
                    <span class="symbol-badge"><?= e($token['symbol']) ?></span>
                </h1>
                <p class="live-price">
                    Current Price: <strong data-live="price"><?= formatUSD($price) ?></strong>
                    <span class="price-badge <?= plClass($change24) ?>" data-live="change24"><?= formatPercent($change24) ?></span>
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
                <p class="pl-value <?= plClass($unrealizedPL) ?>" data-countup="<?= $unrealizedPL ?>" data-pl="1" data-live="unrealizedPL">
                    <?= formatPL($unrealizedPL) ?>
                    <span class="pl-percent" data-live="unrealizedPercent">(<?= formatPercent($plPercent) ?>)</span>
                </p>
                <small>If you sell <?= formatCrypto($holdings) ?> <?= e($token['symbol']) ?> now</small>
            </div>
            <div class="pl-card highlight">
                <h3>Total P/L</h3>
                <p class="pl-value <?= plClass($totalPL) ?>" data-countup="<?= $totalPL ?>" data-pl="1" data-live="totalPL">
                    <?= formatPL($totalPL) ?>
                    <span class="pl-percent" data-live="totalPercent">(<?= formatPercent($totalPercent) ?>)</span>
                </p>
                <small>Realized + Unrealized</small>
            </div>
        </section>

        <section class="holdings-info">
            <div class="info-grid">
                <div><span class="label">Holdings</span><span class="val"><?= formatCrypto($holdings) ?> <?= e($token['symbol']) ?></span></div>
                <div><span class="label">Avg Buy Price</span><span class="val"><?= formatUSD($avgBuy) ?></span></div>
                <div><span class="label">Cost Basis</span><span class="val"><?= formatUSD($costBasis) ?></span></div>
                <div><span class="label">Current Value</span><span class="val" data-live="currentVal"><?= formatUSD($currentValue) ?></span></div>
            </div>
        </section>

        <section class="market-data-section animate-fade-in-up">
            <h2>Market Data</h2>
            <div class="market-grid">
                <div class="market-item">
                    <span class="label">1h Change</span>
                    <span class="val <?= plClass($change1h) ?>"><?= formatPercent($change1h) ?></span>
                </div>
                <div class="market-item">
                    <span class="label">24h Change</span>
                    <span class="val <?= plClass($change24) ?>"><?= formatPercent($change24) ?></span>
                </div>
                <div class="market-item">
                    <span class="label">7d Change</span>
                    <span class="val <?= plClass($change7d) ?>"><?= formatPercent($change7d) ?></span>
                </div>
                <div class="market-item">
                    <span class="label">Market Cap</span>
                    <span class="val"><?= formatBigNum($marketCap) ?></span>
                </div>
                <div class="market-item">
                    <span class="label">24h Volume</span>
                    <span class="val"><?= formatBigNum($volume24) ?></span>
                </div>
                <div class="market-item">
                    <span class="label">Circulating Supply</span>
                    <span class="val"><?= formatSupply($csupply) ?> <?= e($token['symbol']) ?></span>
                </div>
                <?php if ($tsupply > 0): ?>
                <div class="market-item">
                    <span class="label">Total Supply</span>
                    <span class="val"><?= formatSupply($tsupply) ?> <?= e($token['symbol']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($msupply > 0): ?>
                <div class="market-item">
                    <span class="label">Max Supply</span>
                    <span class="val"><?= formatSupply($msupply) ?> <?= e($token['symbol']) ?></span>
                </div>
                <?php endif; ?>
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
                           value="<?= formatFormValue($price) ?>" placeholder="0.00" id="buyPrice">

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
                           value="<?= formatFormValue($price) ?>" placeholder="0.00" id="sellPrice">

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

            <div class="graph-container">
                <canvas id="plGraph" width="800" height="280"></canvas>
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
                            <td data-live-now="price"><?= formatUSD($price) ?></td>
                            <td data-live-now="holdingVal"><?= formatUSD($currentValue) ?></td>
                            <td><?= formatCrypto($holdings) ?></td>
                            <td><?= formatUSD($avgBuy) ?></td>
                            <td>–</td>
                            <td class="<?= plClass($lastCumRealized) ?>"><?= formatPL($lastCumRealized) ?></td>
                            <td class="<?= plClass($unrealizedPL) ?>" data-live-now="unrealized"><?= formatPL($unrealizedPL) ?></td>
                            <td class="<?= plClass($totalPL) ?>" data-live-now="totalPL"><?= formatPL($totalPL) ?></td>
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
    (function() {
        window._plGraphData = <?= $graphData ?>;
        window._plHiddenSeries = new Set();

        const canvas = document.getElementById('plGraph');
        if (!canvas || !canvas.getContext || !window._plGraphData.length) return;

        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;

        const COLORS = {
            totalPL:     { line: '#6c5ce7', fill: 'rgba(108,92,231,0.12)', dot: '#6c5ce7' },
            unrealized:  { line: '#fdcb6e', fill: 'rgba(253,203,110,0.08)', dot: '#fdcb6e' },
            cumRealized: { line: '#00cec9', fill: 'rgba(0,206,201,0.08)', dot: '#00cec9' },
        };

        const SERIES = [
            { key: 'total_pl',     label: 'Total P/L',      color: COLORS.totalPL,     lineW: 2.5, dash: [] },
            { key: 'unrealized',   label: 'Unrealized P/L', color: COLORS.unrealized,  lineW: 2,   dash: [3, 3] },
            { key: 'cum_realized', label: 'Cum. Realized',  color: COLORS.cumRealized, lineW: 2,   dash: [6, 3] },
        ];

        // Build HTML legend
        const legendContainer = document.getElementById('graphLegend');
        if (legendContainer) {
            SERIES.forEach(s => {
                const item = document.createElement('span');
                item.className = 'graph-legend-item';
                item.dataset.series = s.key;
                item.innerHTML = '<span class="graph-legend-swatch" style="background:' + s.color.line + '"></span>' + s.label;
                item.addEventListener('click', () => {
                    if (window._plHiddenSeries.has(s.key)) {
                        window._plHiddenSeries.delete(s.key);
                        item.classList.remove('disabled');
                    } else {
                        window._plHiddenSeries.add(s.key);
                        item.classList.add('disabled');
                    }
                    window._drawPLGraph();
                });
                legendContainer.appendChild(item);
            });
        }

        window._drawPLGraph = function() {
            const data = window._plGraphData;
            if (!data.length) return;

            const hidden = window._plHiddenSeries;

            const rect = canvas.parentElement.getBoundingClientRect();
            canvas.width  = rect.width * dpr;
            canvas.height = 320 * dpr;
            canvas.style.width  = rect.width + 'px';
            canvas.style.height = '320px';
            ctx.scale(dpr, dpr);

            const W = rect.width;
            const H = 320;
            const pad = { top: 30, right: 20, bottom: 32, left: 65 };
            const gW = W - pad.left - pad.right;
            const gH = H - pad.top - pad.bottom;

            ctx.clearRect(0, 0, W, H);

            // Compute value range only from visible series
            const visibleKeys = SERIES.filter(s => !hidden.has(s.key)).map(s => s.key);
            let allVals;
            if (visibleKeys.length === 0) {
                allVals = [0];
            } else {
                allVals = data.flatMap(d => visibleKeys.map(k => d[k]));
            }
            let minV = Math.min(0, ...allVals);
            let maxV = Math.max(0, ...allVals);
            if (minV === maxV) { minV -= 10; maxV += 10; }
            const range = maxV - minV;
            minV -= range * 0.1;
            maxV += range * 0.1;

            function xPos(i) { return pad.left + (data.length === 1 ? gW / 2 : (i / (data.length - 1)) * gW); }
            function yPos(v) { return pad.top + gH - ((v - minV) / (maxV - minV)) * gH; }

            ctx.strokeStyle = 'rgba(255,255,255,0.06)';
            ctx.lineWidth = 1;
            const gridN = 5;
            for (let i = 0; i <= gridN; i++) {
                const y = pad.top + (gH / gridN) * i;
                ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(W - pad.right, y); ctx.stroke();
                const val = maxV - ((maxV - minV) / gridN) * i;
                ctx.fillStyle = '#7c819a';
                ctx.font = '11px Inter, sans-serif';
                ctx.textAlign = 'right';
                ctx.fillText('$' + val.toFixed(2), pad.left - 8, y + 4);
            }

            const zeroY = yPos(0);
            if (zeroY >= pad.top && zeroY <= pad.top + gH) {
                ctx.strokeStyle = 'rgba(255,255,255,0.15)';
                ctx.setLineDash([4, 4]);
                ctx.beginPath(); ctx.moveTo(pad.left, zeroY); ctx.lineTo(W - pad.right, zeroY); ctx.stroke();
                ctx.setLineDash([]);
            }

            // Gradient fill for total_pl (only when visible)
            if (!hidden.has('total_pl')) {
                const lastTotal = data[data.length - 1].total_pl;
                const grad = ctx.createLinearGradient(0, pad.top, 0, pad.top + gH);
                if (lastTotal >= 0) {
                    grad.addColorStop(0, 'rgba(108,92,231,0.18)');
                    grad.addColorStop(1, 'rgba(108,92,231,0.01)');
                } else {
                    grad.addColorStop(0, 'rgba(108,92,231,0.01)');
                    grad.addColorStop(1, 'rgba(108,92,231,0.18)');
                }
                ctx.beginPath();
                ctx.moveTo(xPos(0), yPos(0));
                for (let i = 0; i < data.length; i++) ctx.lineTo(xPos(i), yPos(data[i].total_pl));
                ctx.lineTo(xPos(data.length - 1), yPos(0));
                ctx.closePath();
                ctx.fillStyle = grad;
                ctx.fill();
            }

            function drawLine(key, color, lineW, dashPattern) {
                if (hidden.has(key)) return;

                // Find the boundary between real and future points
                let lastRealIdx = data.length - 1;
                for (let i = data.length - 1; i >= 0; i--) {
                    if (!data[i].is_future) { lastRealIdx = i; break; }
                }
                const hasFuture = lastRealIdx < data.length - 1;

                // Draw real portion
                ctx.beginPath();
                ctx.setLineDash(dashPattern || []);
                for (let i = 0; i <= lastRealIdx; i++) {
                    const x = xPos(i), y = yPos(data[i][key]);
                    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
                }
                ctx.strokeStyle = color.line;
                ctx.lineWidth = lineW;
                ctx.lineJoin = 'round';
                ctx.stroke();
                ctx.setLineDash([]);

                // Draw future portion (dashed, semi-transparent)
                if (hasFuture) {
                    ctx.save();
                    ctx.globalAlpha = 0.45;
                    ctx.beginPath();
                    ctx.setLineDash([5, 4]);
                    ctx.moveTo(xPos(lastRealIdx), yPos(data[lastRealIdx][key]));
                    for (let i = lastRealIdx + 1; i < data.length; i++) {
                        ctx.lineTo(xPos(i), yPos(data[i][key]));
                    }
                    ctx.strokeStyle = color.line;
                    ctx.lineWidth = lineW;
                    ctx.lineJoin = 'round';
                    ctx.stroke();
                    ctx.setLineDash([]);
                    ctx.restore();
                }

                // Draw dots
                data.forEach((d, i) => {
                    const isNow = d.is_now;
                    const isFuture = d.is_future;
                    const r = isNow ? 5 : isFuture ? 4 : 3;

                    ctx.save();
                    if (isFuture) ctx.globalAlpha = 0.45;

                    ctx.beginPath();
                    ctx.arc(xPos(i), yPos(d[key]), r, 0, Math.PI * 2);
                    ctx.fillStyle = color.dot;
                    ctx.fill();
                    ctx.strokeStyle = isNow ? '#fff' : isFuture ? color.line : '#0b0d14';
                    ctx.lineWidth = isNow ? 2 : isFuture ? 1.5 : 1.5;
                    ctx.setLineDash(isFuture ? [2, 2] : []);
                    ctx.stroke();
                    ctx.setLineDash([]);

                    ctx.restore();
                });
            }

            SERIES.forEach(s => drawLine(s.key, s.color, s.lineW, s.dash));

            // "Now" & "Future" labels — only if total_pl visible
            if (!hidden.has('total_pl')) {
                data.forEach((d, i) => {
                    if (d.is_now) {
                        const nx = xPos(i);
                        const ny = yPos(d.total_pl);
                        ctx.fillStyle = '#6c5ce7';
                        ctx.font = '600 10px Inter, sans-serif';
                        ctx.textAlign = 'center';
                        ctx.fillText('NOW', nx, ny - 10);
                    }
                    if (d.is_future) {
                        const fx = xPos(i);
                        const fy = yPos(d.total_pl);
                        ctx.save();
                        ctx.globalAlpha = 0.55;
                        ctx.fillStyle = '#a29bfe';
                        ctx.font = '600 9px Inter, sans-serif';
                        ctx.textAlign = 'center';
                        ctx.fillText('FUTURE', fx, fy - 10);
                        ctx.restore();
                    }
                });
            }

            ctx.fillStyle = '#7c819a';
            ctx.font = '10px Inter, sans-serif';
            ctx.textAlign = 'center';
            const maxLabels = Math.min(data.length, Math.floor(gW / 80));
            const step = Math.max(1, Math.floor(data.length / maxLabels));
            for (let i = 0; i < data.length; i += step) {
                const d = data[i];
                ctx.save();
                if (d.is_future) ctx.globalAlpha = 0.5;
                const label = d.is_now ? 'Now' : d.is_future ? 'Future' : new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                ctx.fillText(label, xPos(i), H - pad.bottom + 18);
                ctx.restore();
            }
            // Always label the last point if not already labelled
            if ((data.length - 1) % step !== 0) {
                const d = data[data.length - 1];
                ctx.save();
                if (d.is_future) ctx.globalAlpha = 0.5;
                const label = d.is_now ? 'Now' : d.is_future ? 'Future' : new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                ctx.fillText(label, xPos(data.length - 1), H - pad.bottom + 18);
                ctx.restore();
            }

            ctx.fillStyle = '#e4e7ef';
            ctx.font = '600 12px Inter, sans-serif';
            ctx.textAlign = 'left';
            ctx.fillText('P/L Over Time', pad.left, 18);
        };

        window._drawPLGraph();
        window.addEventListener('resize', window._drawPLGraph);
    })();
    </script>

<?php layoutFooter(); ?>
