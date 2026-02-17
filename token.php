<?php
/**
 * Single Token Page – shows detailed P/L and buy/sell forms.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/helpers.php';

$user = requireAuth();

$tokenId = (int) ($_GET['id'] ?? 0);
$token   = dbGetUserToken($tokenId, $user['id']);

if (!$token) { header('Location: index.php'); exit; }

/* ── Live price ─────────────────────────────────────────────── */

$quotes  = apiGetQuotes([$token['cmc_id']]);
$quote   = $quotes[$token['cmc_id']] ?? [];
$price   = $quote['price'] ?? 0;
$change24 = $quote['percent_change_24h'] ?? 0;

/* ── Transactions ───────────────────────────────────────────── */

$buys  = dbGetTransactions($tokenId, 'buy');
$sells = dbGetTransactions($tokenId, 'sell');
$allTx = dbGetTransactionsDesc($tokenId);

/* ── P/L Calculation ────────────────────────────────────────── */

$totalBought = 0;
$totalSpent  = 0;
$totalSold   = 0;
$realizedPL  = 0;

foreach ($buys  as $b) { $totalBought += $b['amount']; $totalSpent += $b['total_value']; }
foreach ($sells as $s) { $totalSold   += $s['amount']; $realizedPL += $s['realized_pl']; }

$holdings  = max(0, $totalBought - $totalSold);
$avgBuy    = ($totalBought > 0) ? ($totalSpent / $totalBought) : 0;
$costBasis = $holdings * $avgBuy;

$currentValue = $holdings * $price;
$unrealizedPL = $currentValue - $costBasis;
$totalPL      = $realizedPL + $unrealizedPL;

$plPercent    = ($costBasis > 0) ? (($unrealizedPL / $costBasis) * 100) : 0;
$totalPercent = ($totalSpent > 0) ? (($totalPL / $totalSpent) * 100) : 0;

layoutHead(e($token['symbol']));
layoutNav($user);
?>

    <main class="container">
        <a href="index.php" class="back-link">&larr; Back to Dashboard</a>

        <!-- Token Header -->
        <section class="token-header">
            <div>
                <h1><?= e($token['name']) ?> <span class="symbol-badge"><?= e($token['symbol']) ?></span></h1>
                <p class="live-price">
                    Current Price: <strong>$<?= number_format($price, 6) ?></strong>
                    <span class="price-badge <?= plClass($change24) ?>"><?= formatPercent($change24) ?></span>
                </p>
            </div>
        </section>

        <!-- Flash messages -->
        <?= renderFlashes() ?>

        <!-- P/L Cards -->
        <section class="pl-cards">
            <div class="pl-card">
                <h3>Realized P/L</h3>
                <p class="pl-value <?= plClass($realizedPL) ?>">
                    <?= formatPL($realizedPL) ?>
                </p>
                <small>From completed sells vs buy cost</small>
            </div>
            <div class="pl-card">
                <h3>Unrealized P/L</h3>
                <p class="pl-value <?= plClass($unrealizedPL) ?>">
                    <?= formatPL($unrealizedPL) ?>
                    <span class="pl-percent">(<?= formatPercent($plPercent) ?>)</span>
                </p>
                <small>If you sell <?= formatCrypto($holdings) ?> <?= e($token['symbol']) ?> now</small>
            </div>
            <div class="pl-card highlight">
                <h3>Total P/L</h3>
                <p class="pl-value <?= plClass($totalPL) ?>">
                    <?= formatPL($totalPL) ?>
                    <span class="pl-percent">(<?= formatPercent($totalPercent) ?>)</span>
                </p>
                <small>Realized + Unrealized</small>
            </div>
        </section>

        <!-- Holdings info -->
        <section class="holdings-info">
            <div class="info-grid">
                <div><span class="label">Holdings</span><span class="val"><?= formatCrypto($holdings) ?> <?= e($token['symbol']) ?></span></div>
                <div><span class="label">Avg Buy Price</span><span class="val">$<?= number_format($avgBuy, 6) ?></span></div>
                <div><span class="label">Cost Basis</span><span class="val"><?= formatUSD($costBasis) ?></span></div>
                <div><span class="label">Current Value</span><span class="val"><?= formatUSD($currentValue) ?></span></div>
            </div>
        </section>

        <!-- Buy / Sell Forms -->
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

        <!-- Transaction History -->
        <section class="tx-history">
            <h2>Transaction History</h2>
            <?php if (empty($allTx)): ?>
                <p class="empty-state">No transactions yet. Record a buy above!</p>
            <?php else: ?>
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
                        <tr>
                            <td><?= date('M d, Y H:i', strtotime($tx['created_at'])) ?></td>
                            <td><span class="badge badge-<?= $tx['type'] ?>"><?= ucfirst($tx['type']) ?></span></td>
                            <td><?= formatCrypto($tx['amount']) ?></td>
                            <td>$<?= number_format($tx['price_per_unit'], 6) ?></td>
                            <td><?= formatUSD($tx['total_value']) ?></td>
                            <td class="<?= plClass($tx['realized_pl']) ?>">
                                <?php if ($tx['type'] === 'sell'): ?>
                                    <?= formatPL($tx['realized_pl']) ?>
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

        <!-- Remove Token -->
        <section class="danger-zone">
            <form method="POST" action="remove_token.php" onsubmit="return confirm('Remove this token and all its transactions?');">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= (int)$token['id'] ?>">
                <button type="submit" class="btn btn-danger">Remove Token</button>
            </form>
        </section>
    </main>

<?php layoutFooter(); ?>
