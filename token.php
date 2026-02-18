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
$allTx    = dbGetTransactionsDesc($tokenId);

$mode = plMode();
$pl   = calcTokenPL($tokenId, $price, $mode);

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

$graphData = json_encode(array_map(fn($p) => [
    'date'         => $p['date'],
    'total_pl'     => round($p['total_pl'], 2),
    'unrealized'   => round($p['unrealized'], 2),
    'cum_realized' => round($p['cum_realized'], 2),
], $plTimeline));

layoutHead(e($token['symbol']));
layoutNav($user);
?>

    <main class="container" data-page="token" data-token-id="<?= (int)$tokenId ?>"
          data-cmc-id="<?= (int)$token['cmc_id'] ?>"
          data-holdings="<?= $holdings ?>"
          data-cost-basis="<?= $costBasis ?>">
        <a href="index.php" class="back-link">&larr; Back to Dashboard</a>

        <section class="token-header">
            <div>
                <h1>
                    <?php if ($rank > 0): ?><span class="rank-badge">#<?= $rank ?></span><?php endif; ?>
                    <?= e($token['name']) ?>
                    <span class="symbol-badge"><?= e($token['symbol']) ?></span>
                </h1>
                <p class="live-price">
                    Current Price: <strong data-live="price">$<?= number_format($price, 6) ?></strong>
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
                <div><span class="label">Avg Buy Price</span><span class="val">$<?= number_format($avgBuy, 6) ?></span></div>
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
                <form method="POST" action="transaction.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="user_token_id" value="<?= (int)$token['id'] ?>">
                    <input type="hidden" name="type" value="buy">

                    <label>Amount (<?= e($token['symbol']) ?>)</label>
                    <input type="number" name="amount" step="any" min="0.00000001" required placeholder="0.00">

                    <label>Price per unit (USD)</label>
                    <input type="number" name="price_per_unit" step="any" min="0.00000001" required
                           value="<?= number_format($price, 6, '.', '') ?>" placeholder="0.00">

                    <button type="submit" class="btn btn-buy">Buy</button>
                </form>
            </div>

            <div class="trade-card sell-card">
                <h3>Sell <?= e($token['symbol']) ?></h3>
                <form method="POST" action="transaction.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="user_token_id" value="<?= (int)$token['id'] ?>">
                    <input type="hidden" name="type" value="sell">

                    <label>Amount (<?= e($token['symbol']) ?>)</label>
                    <input type="number" name="amount" step="any" min="0.00000001"
                           max="<?= $holdings ?>" required placeholder="0.00">

                    <label>Price per unit (USD)</label>
                    <input type="number" name="price_per_unit" step="any" min="0.00000001" required
                           value="<?= number_format($price, 6, '.', '') ?>" placeholder="0.00">

                    <button type="submit" class="btn btn-sell" <?= $holdings <= 0 ? 'disabled' : '' ?>>Sell</button>
                </form>
            </div>
        </section>

        <?php if (!empty($plTimeline)): ?>
        <section class="pl-analytics animate-fade-in-up">
            <h2>P/L Analytics</h2>

            <div class="graph-container">
                <canvas id="plGraph" width="800" height="280"></canvas>
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
                            <td>$<?= number_format($row['ppu'], 6) ?></td>
                            <td><?= formatUSD($row['total']) ?></td>
                            <td><?= formatCrypto($row['holdings']) ?></td>
                            <td>$<?= number_format($row['avg_cost'], 6) ?></td>
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
                            <td>$<?= number_format($tx['price_per_unit'], 6) ?></td>
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
        const data = <?= $graphData ?>;
        if (!data.length) return;

        const canvas = document.getElementById('plGraph');
        if (!canvas || !canvas.getContext) return;

        const ctx = canvas.getContext('2d');
        const dpr = window.devicePixelRatio || 1;

        const COLORS = {
            totalPL:     { line: '#6c5ce7', fill: 'rgba(108,92,231,0.12)', dot: '#6c5ce7' },
            unrealized:  { line: '#fdcb6e', fill: 'rgba(253,203,110,0.08)', dot: '#fdcb6e' },
            cumRealized: { line: '#00cec9', fill: 'rgba(0,206,201,0.08)', dot: '#00cec9' },
        };

        function draw() {
            const rect = canvas.parentElement.getBoundingClientRect();
            canvas.width  = rect.width * dpr;
            canvas.height = 320 * dpr;
            canvas.style.width  = rect.width + 'px';
            canvas.style.height = '320px';
            ctx.scale(dpr, dpr);

            const W = rect.width;
            const H = 320;
            const pad = { top: 30, right: 20, bottom: 50, left: 65 };
            const gW = W - pad.left - pad.right;
            const gH = H - pad.top - pad.bottom;

            ctx.clearRect(0, 0, W, H);

            const allVals = data.flatMap(d => [d.total_pl, d.unrealized, d.cum_realized]);
            let minV = Math.min(0, ...allVals);
            let maxV = Math.max(0, ...allVals);
            if (minV === maxV) { minV -= 10; maxV += 10; }
            const range = maxV - minV;
            minV -= range * 0.1;
            maxV += range * 0.1;

            function xPos(i) { return pad.left + (data.length === 1 ? gW / 2 : (i / (data.length - 1)) * gW); }
            function yPos(v) { return pad.top + gH - ((v - minV) / (maxV - minV)) * gH; }

            // Grid lines & Y labels
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

            // Zero line
            const zeroY = yPos(0);
            if (zeroY >= pad.top && zeroY <= pad.top + gH) {
                ctx.strokeStyle = 'rgba(255,255,255,0.15)';
                ctx.setLineDash([4, 4]);
                ctx.beginPath(); ctx.moveTo(pad.left, zeroY); ctx.lineTo(W - pad.right, zeroY); ctx.stroke();
                ctx.setLineDash([]);
            }

            // Gradient fill under Total P/L line
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

            // Draw helper: line + dots
            function drawLine(key, color, lineW, dashPattern) {
                ctx.beginPath();
                ctx.setLineDash(dashPattern || []);
                for (let i = 0; i < data.length; i++) {
                    const x = xPos(i), y = yPos(data[i][key]);
                    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
                }
                ctx.strokeStyle = color.line;
                ctx.lineWidth = lineW;
                ctx.lineJoin = 'round';
                ctx.stroke();
                ctx.setLineDash([]);

                data.forEach((d, i) => {
                    ctx.beginPath();
                    ctx.arc(xPos(i), yPos(d[key]), 3, 0, Math.PI * 2);
                    ctx.fillStyle = color.dot;
                    ctx.fill();
                    ctx.strokeStyle = '#0b0d14';
                    ctx.lineWidth = 1.5;
                    ctx.stroke();
                });
            }

            drawLine('cum_realized', COLORS.cumRealized, 2, [6, 3]);
            drawLine('unrealized',   COLORS.unrealized,  2, [3, 3]);
            drawLine('total_pl',     COLORS.totalPL,     2.5);

            // X labels
            ctx.fillStyle = '#7c819a';
            ctx.font = '10px Inter, sans-serif';
            ctx.textAlign = 'center';
            const maxLabels = Math.min(data.length, Math.floor(gW / 80));
            const step = Math.max(1, Math.floor(data.length / maxLabels));
            for (let i = 0; i < data.length; i += step) {
                const d = new Date(data[i].date);
                ctx.fillText(d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }), xPos(i), H - pad.bottom + 18);
            }

            // Title
            ctx.fillStyle = '#e4e7ef';
            ctx.font = '600 12px Inter, sans-serif';
            ctx.textAlign = 'left';
            ctx.fillText('P/L Over Time', pad.left, 18);

            // Legend
            const legendY = H - 10;
            const legends = [
                { label: 'Total P/L',       color: COLORS.totalPL.line },
                { label: 'Unrealized P/L',  color: COLORS.unrealized.line },
                { label: 'Cum. Realized',   color: COLORS.cumRealized.line },
            ];
            ctx.font = '11px Inter, sans-serif';
            let lx = pad.left;
            legends.forEach(lg => {
                ctx.fillStyle = lg.color;
                ctx.fillRect(lx, legendY - 8, 14, 3);
                lx += 18;
                ctx.fillStyle = '#9ca3b8';
                ctx.textAlign = 'left';
                ctx.fillText(lg.label, lx, legendY);
                lx += ctx.measureText(lg.label).width + 20;
            });
        }

        draw();
        window.addEventListener('resize', draw);
    })();
    </script>

<?php layoutFooter(); ?>
