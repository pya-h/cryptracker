<?php
/**
 * Single Token Page — detailed P/L, market data, analytics, and trade forms.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

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
    (function() {
        window._plGraphData = <?= $graphData ?>;
        window._plHiddenSeries = new Set();

        const canvas = document.getElementById('plGraph');
        if (!canvas || !canvas.getContext || !window._plGraphData.length) return;

        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;
        const container = document.getElementById('graphContainer');
        const tooltip = document.getElementById('graphTooltip');

        const COLORS = {
            totalPL:     { line: '#6c5ce7', fill: 'rgba(108,92,231,0.12)', dot: '#6c5ce7', glow: 'rgba(108,92,231,0.5)' },
            unrealized:  { line: '#fdcb6e', fill: 'rgba(253,203,110,0.08)', dot: '#fdcb6e', glow: 'rgba(253,203,110,0.5)' },
            cumRealized: { line: '#00cec9', fill: 'rgba(0,206,201,0.08)', dot: '#00cec9', glow: 'rgba(0,206,201,0.5)' },
        };

        const SERIES = [
            { key: 'total_pl',     label: 'Total P/L',      color: COLORS.totalPL,     lineW: 2.5, dash: [] },
            { key: 'unrealized',   label: 'Unrealized P/L', color: COLORS.unrealized,  lineW: 2,   dash: [3, 3] },
            { key: 'cum_realized', label: 'Cum. Realized',  color: COLORS.cumRealized, lineW: 2,   dash: [6, 3] },
        ];

        /* Hover state */
        let hoveredIdx = -1;
        let _lastW = 0, _lastH = 0;
        let _pad, _gW, _gH, _minV, _maxV;

        /* Helpers for coordinate mapping (cached after each draw) */
        function xPos(i) {
            const data = window._plGraphData;
            return _pad.left + (data.length === 1 ? _gW / 2 : (i / (data.length - 1)) * _gW);
        }
        function yPos(v) {
            return _pad.top + _gH - ((v - _minV) / (_maxV - _minV)) * _gH;
        }

        /* Build HTML legend */
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

        /* Format value for tooltip */
        function fmtVal(v) {
            const abs = Math.abs(v);
            const s = abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return (v >= 0 ? '+$' : '-$') + s;
        }

        function fmtDate(d) {
            if (d.is_now) return 'Now';
            if (d.is_future) return 'Future';
            const dt = new Date(d.date);
            return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                + ' ' + dt.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });
        }

        function graphTheme() {
            const rootStyle = getComputedStyle(document.body);
            const isLight = document.body.classList.contains('theme-light');
            const cssBg = (rootStyle.getPropertyValue('--bg') || '').trim();
            const cssCardSolid = (rootStyle.getPropertyValue('--bg-card-solid') || '').trim();
            const cssCard = (rootStyle.getPropertyValue('--bg-card') || '').trim();
            const cssText = (rootStyle.getPropertyValue('--text') || '').trim();
            const cssMuted = (rootStyle.getPropertyValue('--text-muted') || '').trim();
            const canvasBg = cssCardSolid || cssCard || cssBg;

            if (isLight) {
                return {
                    canvasBg: canvasBg || '#ffffff',
                    grid: 'rgba(26,29,46,0.10)',
                    zero: 'rgba(26,29,46,0.22)',
                    crosshair: 'rgba(26,29,46,0.16)',
                    label: cssMuted || '#6b7084',
                    labelActive: cssText || '#1a1d2e',
                    title: cssText || '#1a1d2e',
                    dotStroke: '#ffffff',
                };
            }

            return {
                canvasBg: canvasBg || '#181a27',
                grid: 'rgba(255,255,255,0.06)',
                zero: 'rgba(255,255,255,0.15)',
                crosshair: 'rgba(255,255,255,0.13)',
                label: cssMuted || '#7c819a',
                labelActive: cssText || '#e4e7ef',
                title: cssText || '#e4e7ef',
                dotStroke: '#0b0d14',
            };
        }

        window._drawPLGraph = function(animProgress) {
            const data = window._plGraphData;
            if (!data.length) return;

            const hidden = window._plHiddenSeries;
            const anim = typeof animProgress === 'number' ? animProgress : 1;
            const theme = graphTheme();

            const rect = canvas.getBoundingClientRect();
            const W = rect.width;
            const H = 320;
            _lastW = W; _lastH = H;

            canvas.width  = W * dpr;
            canvas.height = H * dpr;
            canvas.style.width  = W + 'px';
            canvas.style.height = H + 'px';
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

            const pad = { top: 30, right: 20, bottom: 32, left: 65 };
            const gW = W - pad.left - pad.right;
            const gH = H - pad.top - pad.bottom;
            _pad = pad; _gW = gW; _gH = gH;

            ctx.clearRect(0, 0, W, H);
            ctx.fillStyle = theme.canvasBg;
            ctx.fillRect(0, 0, W, H);

            /* Compute value range */
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
            _minV = minV; _maxV = maxV;

            /* Grid */
            ctx.strokeStyle = theme.grid;
            ctx.lineWidth = 1;
            const gridN = 5;
            for (let i = 0; i <= gridN; i++) {
                const y = pad.top + (gH / gridN) * i;
                ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(W - pad.right, y); ctx.stroke();
                const val = maxV - ((maxV - minV) / gridN) * i;
                ctx.fillStyle = theme.label;
                ctx.font = '11px Inter, sans-serif';
                ctx.textAlign = 'right';
                ctx.fillText('$' + val.toFixed(2), pad.left - 8, y + 4);
            }

            const vGridN = Math.min(8, Math.max(3, Math.floor(gW / 110)));
            for (let i = 0; i <= vGridN; i++) {
                const x = pad.left + (gW / vGridN) * i;
                ctx.beginPath();
                ctx.moveTo(x, pad.top);
                ctx.lineTo(x, pad.top + gH);
                ctx.stroke();
            }

            /* Zero line */
            const zeroY = yPos(0);
            if (zeroY >= pad.top && zeroY <= pad.top + gH) {
                ctx.strokeStyle = theme.zero;
                ctx.setLineDash([4, 4]);
                ctx.beginPath(); ctx.moveTo(pad.left, zeroY); ctx.lineTo(W - pad.right, zeroY); ctx.stroke();
                ctx.setLineDash([]);
            }

            /* Hover crosshair */
            if (hoveredIdx >= 0 && hoveredIdx < data.length) {
                const hx = xPos(hoveredIdx);
                ctx.save();
                ctx.strokeStyle = theme.crosshair;
                ctx.lineWidth = 1;
                ctx.setLineDash([3, 3]);
                ctx.beginPath();
                ctx.moveTo(hx, pad.top);
                ctx.lineTo(hx, pad.top + gH);
                ctx.stroke();
                ctx.setLineDash([]);
                ctx.restore();
            }

            /* Animated clip for line reveal */
            const animClipX = pad.left + gW * anim;

            /* Gradient fill for total_pl */
            if (!hidden.has('total_pl')) {
                ctx.save();
                ctx.beginPath();
                ctx.rect(pad.left, pad.top, gW * anim, gH);
                ctx.clip();

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
                ctx.restore();
            }

            function drawLine(key, color, lineW, dashPattern) {
                if (hidden.has(key)) return;

                let lastRealIdx = data.length - 1;
                for (let i = data.length - 1; i >= 0; i--) {
                    if (!data[i].is_future) { lastRealIdx = i; break; }
                }
                const hasFuture = lastRealIdx < data.length - 1;

                /* Clip to animation progress */
                ctx.save();
                ctx.beginPath();
                ctx.rect(pad.left - 5, 0, gW * anim + 10, H);
                ctx.clip();

                /* Real portion */
                ctx.beginPath();
                ctx.setLineDash(dashPattern || []);
                for (let i = 0; i <= lastRealIdx; i++) {
                    const x = xPos(i), y = yPos(data[i][key]);
                    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
                }
                ctx.strokeStyle = color.line;
                ctx.lineWidth = lineW;
                ctx.lineJoin = 'round';
                ctx.lineCap = 'round';
                ctx.stroke();
                ctx.setLineDash([]);

                /* Future portion */
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
                    ctx.lineCap = 'round';
                    ctx.stroke();
                    ctx.setLineDash([]);
                    ctx.restore();
                }

                /* Dots */
                data.forEach((d, i) => {
                    if (xPos(i) > animClipX + 2) return;
                    const isNow = d.is_now;
                    const isFuture = d.is_future;
                    const isHovered = (i === hoveredIdx);

                    let r = isNow ? 5 : isFuture ? 4 : 3;
                    if (isHovered) r += 2.5;

                    ctx.save();
                    if (isFuture && !isHovered) ctx.globalAlpha = 0.45;

                    /* Glow ring on hover */
                    if (isHovered) {
                        ctx.beginPath();
                        ctx.arc(xPos(i), yPos(d[key]), r + 4, 0, Math.PI * 2);
                        ctx.fillStyle = color.glow;
                        ctx.globalAlpha = 0.25;
                        ctx.fill();
                        ctx.globalAlpha = isFuture ? 0.7 : 1;
                    }

                    ctx.beginPath();
                    ctx.arc(xPos(i), yPos(d[key]), r, 0, Math.PI * 2);
                    ctx.fillStyle = isHovered ? '#fff' : color.dot;
                    ctx.fill();
                    ctx.strokeStyle = isHovered ? color.line : (isNow ? '#fff' : isFuture ? color.line : theme.dotStroke);
                    ctx.lineWidth = isHovered ? 2.5 : (isNow ? 2 : 1.5);
                    ctx.setLineDash(isFuture && !isHovered ? [2, 2] : []);
                    ctx.stroke();
                    ctx.setLineDash([]);

                    ctx.restore();
                });

                ctx.restore(); /* pop anim clip */
            }

            SERIES.forEach(s => drawLine(s.key, s.color, s.lineW, s.dash));

            /* "Now" & "Future" labels */
            if (!hidden.has('total_pl') && anim >= 1) {
                data.forEach((d, i) => {
                    if (hoveredIdx >= 0) return; /* hide labels when hovering */
                    if (d.is_now) {
                        const nx = xPos(i), ny = yPos(d.total_pl);
                        ctx.fillStyle = '#6c5ce7';
                        ctx.font = '600 10px Inter, sans-serif';
                        ctx.textAlign = 'center';
                        ctx.fillText('NOW', nx, ny - 12);
                    }
                    if (d.is_future) {
                        const fx = xPos(i), fy = yPos(d.total_pl);
                        ctx.save();
                        ctx.globalAlpha = 0.55;
                        ctx.fillStyle = '#a29bfe';
                        ctx.font = '600 9px Inter, sans-serif';
                        ctx.textAlign = 'center';
                        ctx.fillText('FUTURE', fx, fy - 12);
                        ctx.restore();
                    }
                });
            }

            /* X-axis labels */
            if (anim >= 1) {
                ctx.fillStyle = theme.label;
                ctx.font = '10px Inter, sans-serif';
                ctx.textAlign = 'center';
                const maxLabels = Math.min(data.length, Math.floor(gW / 80));
                const step = Math.max(1, Math.floor(data.length / maxLabels));
                for (let i = 0; i < data.length; i += step) {
                    const d = data[i];
                    ctx.save();
                    if (d.is_future) ctx.globalAlpha = 0.5;
                    if (i === hoveredIdx) { ctx.fillStyle = theme.labelActive; ctx.font = '600 10px Inter, sans-serif'; }
                    const label = d.is_now ? 'Now' : d.is_future ? 'Future' : new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    ctx.fillText(label, xPos(i), H - pad.bottom + 18);
                    ctx.restore();
                }
                if ((data.length - 1) % step !== 0) {
                    const d = data[data.length - 1];
                    ctx.save();
                    if (d.is_future) ctx.globalAlpha = 0.5;
                    const label = d.is_now ? 'Now' : d.is_future ? 'Future' : new Date(d.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    ctx.fillText(label, xPos(data.length - 1), H - pad.bottom + 18);
                    ctx.restore();
                }
            }

            /* Title */
            ctx.fillStyle = theme.title;
            ctx.font = '600 12px Inter, sans-serif';
            ctx.textAlign = 'left';
            ctx.fillText('P/L Over Time', pad.left, 18);
        };

        /* ── Entry animation ─────────────────────────────── */
        let _animDone = false;
        function animateEntry() {
            const dur = 900;
            const start = performance.now();
            function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }
            function tick(now) {
                const t = Math.min((now - start) / dur, 1);
                window._drawPLGraph(easeOutCubic(t));
                if (t < 1) requestAnimationFrame(tick);
                else _animDone = true;
            }
            requestAnimationFrame(tick);
        }

        /* ── Hover handling ──────────────────────────────── */
        function getMouseIdx(e) {
            const data = window._plGraphData;
            if (!data.length || !_pad) return -1;
            const rect = canvas.getBoundingClientRect();
            const mx = e.clientX - rect.left;
            const my = e.clientY - rect.top;

            /* Outside graph area */
            if (mx < _pad.left || mx > _pad.left + _gW || my < _pad.top || my > _pad.top + _gH) return -1;

            /* Snap to nearest data point */
            let bestIdx = 0, bestDist = Infinity;
            for (let i = 0; i < data.length; i++) {
                const dx = Math.abs(xPos(i) - mx);
                if (dx < bestDist) { bestDist = dx; bestIdx = i; }
            }
            return bestIdx;
        }

        function showTooltip(idx, e) {
            const data = window._plGraphData;
            if (idx < 0 || idx >= data.length || !tooltip) return;
            const d = data[idx];
            const hidden = window._plHiddenSeries;
            const visibleSeries = SERIES.filter(s => !hidden.has(s.key));

            let html = '<div class="graph-tooltip-date">' + fmtDate(d) + '</div>';
            html += '<div class="graph-tooltip-rows">';
            visibleSeries.forEach(s => {
                const v = d[s.key];
                const cls = v >= 0 ? 'profit' : 'loss';
                html += '<div class="graph-tooltip-row">'
                    + '<span class="graph-tooltip-swatch" style="background:' + s.color.line + '"></span>'
                    + '<span class="graph-tooltip-label">' + s.label + '</span>'
                    + '<span class="graph-tooltip-val ' + cls + '">' + fmtVal(v) + '</span>'
                    + '</div>';
            });
            html += '</div>';
            if (d.is_now) html += '<div class="graph-tooltip-badge badge-now-tip">NOW</div>';
            else if (d.is_future) html += '<div class="graph-tooltip-badge badge-future-tip">FUTURE</div>';

            tooltip.innerHTML = html;
            tooltip.classList.add('visible');

            /* Position tooltip: prefer right of cursor, flip if overflowing */
            const rect = container.getBoundingClientRect();
            const cx = e.clientX - rect.left;
            const cy = e.clientY - rect.top;
            const tw = tooltip.offsetWidth;
            const th = tooltip.offsetHeight;
            let tx = cx + 16;
            let ty = cy - th / 2;
            if (tx + tw > rect.width - 8) tx = cx - tw - 16;
            if (ty < 4) ty = 4;
            if (ty + th > rect.height - 4) ty = rect.height - th - 4;

            tooltip.style.left = tx + 'px';
            tooltip.style.top = ty + 'px';
        }

        function hideTooltip() {
            if (tooltip) tooltip.classList.remove('visible');
        }

        canvas.addEventListener('mousemove', e => {
            if (!_animDone) return;
            const idx = getMouseIdx(e);
            if (idx !== hoveredIdx) {
                hoveredIdx = idx;
                window._drawPLGraph();
            }
            if (idx >= 0) {
                canvas.style.cursor = 'crosshair';
                showTooltip(idx, e);
            } else {
                canvas.style.cursor = 'default';
                hideTooltip();
            }
        });

        canvas.addEventListener('mouseleave', () => {
            if (hoveredIdx !== -1) {
                hoveredIdx = -1;
                window._drawPLGraph();
            }
            canvas.style.cursor = 'default';
            hideTooltip();
        });

        /* Touch support for mobile */
        canvas.addEventListener('touchstart', e => {
            if (!_animDone || !e.touches.length) return;
            const touch = e.touches[0];
            const fakeE = { clientX: touch.clientX, clientY: touch.clientY };
            const idx = getMouseIdx(fakeE);
            if (idx >= 0) {
                e.preventDefault();
                hoveredIdx = idx;
                window._drawPLGraph();
                showTooltip(idx, fakeE);
            }
        }, { passive: false });

        canvas.addEventListener('touchmove', e => {
            if (!_animDone || !e.touches.length) return;
            const touch = e.touches[0];
            const fakeE = { clientX: touch.clientX, clientY: touch.clientY };
            const idx = getMouseIdx(fakeE);
            if (idx !== hoveredIdx) {
                hoveredIdx = idx;
                window._drawPLGraph();
            }
            if (idx >= 0) showTooltip(idx, fakeE);
            else hideTooltip();
        }, { passive: true });

        canvas.addEventListener('touchend', () => {
            hoveredIdx = -1;
            window._drawPLGraph();
            hideTooltip();
        });

        /* ── Drag-to-select screenshot ───────────────────── */
        const selRect = document.getElementById('graphSelRect');
        let _dragging = false, _dragStart = null, _dragCurrent = null;
        const DRAG_THRESHOLD = 6;

        function containerPos(e) {
            const r = container.getBoundingClientRect();
            return { x: e.clientX - r.left, y: e.clientY - r.top };
        }

        canvas.addEventListener('mousedown', e => {
            if (!_animDone || e.button !== 0) return;
            _dragStart = containerPos(e);
            _dragCurrent = _dragStart;
            _dragging = false;
        });

        window.addEventListener('mousemove', e => {
            if (!_dragStart) return;
            _dragCurrent = containerPos(e);
            const dx = _dragCurrent.x - _dragStart.x;
            const dy = _dragCurrent.y - _dragStart.y;
            if (!_dragging && Math.hypot(dx, dy) >= DRAG_THRESHOLD) {
                _dragging = true;
                container.classList.add('selecting');
                hideTooltip();
                hoveredIdx = -1;
                window._drawPLGraph();
            }
            if (_dragging) {
                const sx = Math.min(_dragStart.x, _dragCurrent.x);
                const sy = Math.min(_dragStart.y, _dragCurrent.y);
                const sw = Math.abs(dx);
                const sh = Math.abs(dy);
                selRect.style.left = sx + 'px';
                selRect.style.top = sy + 'px';
                selRect.style.width = sw + 'px';
                selRect.style.height = sh + 'px';
                selRect.classList.add('active');
            }
        });

        window.addEventListener('mouseup', e => {
            if (!_dragStart) return;
            const wasReal = _dragging;
            const startPt = _dragStart;
            const endPt = containerPos(e);
            _dragStart = null;
            _dragging = false;
            container.classList.remove('selecting');
            selRect.classList.remove('active');

            if (wasReal) {
                captureSelection(startPt, endPt);
            }
        });

        function captureSelection(p1, p2) {
            const cRect = canvas.getBoundingClientRect();
            const contRect = container.getBoundingClientRect();
            const offX = cRect.left - contRect.left;
            const offY = cRect.top - contRect.top;

            /* Selection coords relative to the canvas element */
            let sx = Math.min(p1.x, p2.x) - offX;
            let sy = Math.min(p1.y, p2.y) - offY;
            let sw = Math.abs(p2.x - p1.x);
            let sh = Math.abs(p2.y - p1.y);

            /* Clamp to canvas bounds */
            if (sx < 0) { sw += sx; sx = 0; }
            if (sy < 0) { sh += sy; sy = 0; }
            sw = Math.min(sw, cRect.width - sx);
            sh = Math.min(sh, cRect.height - sy);
            if (sw < 10 || sh < 10) return;

            /* Scale to actual canvas pixels (DPR) */
            const cropCanvas = document.createElement('canvas');
            cropCanvas.width  = sw * dpr;
            cropCanvas.height = sh * dpr;
            const cropCtx = cropCanvas.getContext('2d');
            cropCtx.drawImage(canvas,
                sx * dpr, sy * dpr, sw * dpr, sh * dpr,
                0, 0, sw * dpr, sh * dpr
            );
            const dataUrl = cropCanvas.toDataURL('image/png');
            openScreenshotModal(dataUrl);
        }

        /* ── Screenshot Modal ────────────────────────────── */
        function openScreenshotModal(imgSrc) {
            /* Remove any existing modal */
            const old = document.getElementById('graphScreenshotOverlay');
            if (old) old.remove();

            let zoom = 1;
            const ZOOM_STEP = 0.25;
            const ZOOM_MIN = 0.25;
            const ZOOM_MAX = 5;

            const overlay = document.createElement('div');
            overlay.className = 'graph-screenshot-overlay';
            overlay.id = 'graphScreenshotOverlay';

            const modal = document.createElement('div');
            modal.className = 'graph-screenshot-modal';

            /* Toolbar */
            const toolbar = document.createElement('div');
            toolbar.className = 'graph-screenshot-toolbar';
            toolbar.innerHTML =
                '<span class="ss-title">Graph Screenshot</span>'
              + '<button class="graph-screenshot-btn" id="ssZoomOut" title="Zoom Out">&#x2212;</button>'
              + '<span class="graph-screenshot-zoom-info" id="ssZoomLbl">100%</span>'
              + '<button class="graph-screenshot-btn" id="ssZoomIn" title="Zoom In">&#x2b;</button>'
              + '<button class="graph-screenshot-btn" id="ssZoomFit" title="Fit">Fit</button>'
              + '<button class="graph-screenshot-btn primary" id="ssDownload" title="Download PNG">&#x21E9; Download</button>'
              + '<button class="graph-screenshot-btn close-btn" id="ssClose" title="Close">&times;</button>';

            /* Viewport */
            const viewport = document.createElement('div');
            viewport.className = 'graph-screenshot-viewport';
            const img = document.createElement('img');
            img.src = imgSrc;
            img.draggable = false;
            viewport.appendChild(img);

            modal.appendChild(toolbar);
            modal.appendChild(viewport);
            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            /* Animate in */
            requestAnimationFrame(() => overlay.classList.add('visible'));

            const zoomLbl = toolbar.querySelector('#ssZoomLbl');
            let panX = 0;
            let panY = 0;
            let panning = false;
            let panLastX = 0;
            let panLastY = 0;
            const PAN_SLACK = 56;

            function getPanBounds() {
                const scaledW = img.getBoundingClientRect().width;
                const scaledH = img.getBoundingClientRect().height;
                const maxPanX = Math.max(PAN_SLACK, (scaledW - viewport.clientWidth) / 2 + PAN_SLACK);
                const maxPanY = Math.max(PAN_SLACK, (scaledH - viewport.clientHeight) / 2 + PAN_SLACK);
                return { maxPanX, maxPanY };
            }

            function clampPan() {
                const { maxPanX, maxPanY } = getPanBounds();
                panX = Math.min(maxPanX, Math.max(-maxPanX, panX));
                panY = Math.min(maxPanY, Math.max(-maxPanY, panY));
            }

            function applyTransform() {
                img.style.transform = 'translate3d(' + panX + 'px,' + panY + 'px,0) scale(' + zoom + ')';
            }

            function resetPan() {
                panX = 0;
                panY = 0;
            }

            function updateZoom() {
                clampPan();
                applyTransform();
                zoomLbl.textContent = Math.round(zoom * 100) + '%';
            }

            toolbar.querySelector('#ssZoomIn').addEventListener('click', () => {
                zoom = Math.min(zoom + ZOOM_STEP, ZOOM_MAX);
                updateZoom();
            });
            toolbar.querySelector('#ssZoomOut').addEventListener('click', () => {
                zoom = Math.max(zoom - ZOOM_STEP, ZOOM_MIN);
                updateZoom();
            });
            toolbar.querySelector('#ssZoomFit').addEventListener('click', () => {
                zoom = 1;
                resetPan();
                updateZoom();
            });
            toolbar.querySelector('#ssDownload').addEventListener('click', () => {
                const a = document.createElement('a');
                a.href = imgSrc;
                a.download = 'graph-screenshot-' + Date.now() + '.png';
                a.click();
            });

            /* Zoom with mouse wheel inside viewport */
            viewport.addEventListener('wheel', e => {
                e.preventDefault();
                if (e.deltaY < 0) zoom = Math.min(zoom + ZOOM_STEP, ZOOM_MAX);
                else zoom = Math.max(zoom - ZOOM_STEP, ZOOM_MIN);
                updateZoom();
            }, { passive: false });

            img.addEventListener('dragstart', e => e.preventDefault());

            function stopPanning(e) {
                if (!panning) return;
                panning = false;
                viewport.classList.remove('panning');
                if (e && typeof e.pointerId === 'number' && viewport.hasPointerCapture && viewport.hasPointerCapture(e.pointerId)) {
                    viewport.releasePointerCapture(e.pointerId);
                }
            }

            viewport.addEventListener('pointerdown', e => {
                if (e.button !== 0 && e.pointerType !== 'touch') return;
                panning = true;
                panLastX = e.clientX;
                panLastY = e.clientY;
                viewport.classList.add('panning');
                if (viewport.setPointerCapture) viewport.setPointerCapture(e.pointerId);
                e.preventDefault();
            });

            viewport.addEventListener('pointermove', e => {
                if (!panning) return;
                const dx = e.clientX - panLastX;
                const dy = e.clientY - panLastY;
                panLastX = e.clientX;
                panLastY = e.clientY;
                panX += dx;
                panY += dy;
                clampPan();
                applyTransform();
                e.preventDefault();
            });

            viewport.addEventListener('pointerup', stopPanning);
            viewport.addEventListener('pointercancel', stopPanning);
            viewport.addEventListener('lostpointercapture', stopPanning);

            /* Close */
            function closeModal() {
                stopPanning();
                overlay.classList.remove('visible');
                setTimeout(() => overlay.remove(), 200);
            }
            toolbar.querySelector('#ssClose').addEventListener('click', closeModal);
            overlay.addEventListener('click', e => {
                if (e.target === overlay) closeModal();
            });
            document.addEventListener('keydown', function escHandler(e) {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escHandler);
                }
            });
        }

        /* Initial draw with animation */
        animateEntry();

        /* Re-animate on resize */
        let _resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(_resizeTimer);
            _resizeTimer = setTimeout(() => {
                _animDone = false;
                animateEntry();
            }, 150);
        });
    })();
    </script>

<?php layoutFooter(); ?>
