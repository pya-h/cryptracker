<?php
/**
 * Handle Buy / Sell transactions.
 * P/L on sell uses the active mode (avg or fifo).
 */

require_once __DIR__ . '/../cryptracker/includes/auth.php';
require_once __DIR__ . '/../cryptracker/includes/helpers.php';

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

csrfGuard();

$userTokenId = (int) ($_POST['user_token_id'] ?? 0);
$type        = $_POST['type'] ?? '';
$amount      = (float) ($_POST['amount'] ?? 0);
$ppu         = (float) ($_POST['price_per_unit'] ?? 0);
$token = dbGetUserToken($userTokenId, $user['id']);

if (!$token || !in_array($type, ['buy', 'sell']) || $amount <= 0 || $ppu <= 0) {
    flash('error', 'Invalid transaction data.');
    header('Location: ' . ($token ? 'token.php?id=' . $token['id'] : 'index.php'));
    exit;
}

$totalValue = $amount * $ppu;
$realizedPL = 0;
if ($type === 'sell') {
    $row = dbGetTokenStats($userTokenId);
    $holdings = $row['bought'] - $row['sold'];

    if ($amount > $holdings + 0.000000001) {
        flash('error', "Cannot sell more than current holdings (" . formatCrypto($holdings) . ").");
        header('Location: token.php?id=' . $token['id']);
        exit;
    }

    $mode = plMode();
    if ($mode === 'fifo') {
        $allTx = dbGetTransactions($userTokenId);
        $lots  = [];
        foreach ($allTx as $t) {
            if ($t['type'] === 'buy') {
                $lots[] = ['amount' => $t['amount'], 'price' => $t['price_per_unit']];
            } else {
                $rem = $t['amount'];
                while ($rem > 1e-10 && !empty($lots)) {
                    $take = min($rem, $lots[0]['amount']);
                    $lots[0]['amount'] -= $take;
                    $rem -= $take;
                    if ($lots[0]['amount'] < 1e-10) array_shift($lots);
                }
            }
        }
        $sellCost = 0.0;
        $rem = $amount;
        while ($rem > 1e-10 && !empty($lots)) {
            $take = min($rem, $lots[0]['amount']);
            $sellCost += $take * $lots[0]['price'];
            $lots[0]['amount'] -= $take;
            $rem -= $take;
            if ($lots[0]['amount'] < 1e-10) array_shift($lots);
        }
        $realizedPL = ($amount * $ppu) - $sellCost;
    } else {
        $avgBuy     = ($row['bought'] > 0) ? ($row['spent'] / $row['bought']) : 0;
        $realizedPL = $amount * ($ppu - $avgBuy);
    }
}
dbInsertTransaction($userTokenId, $type, $amount, $ppu, $totalValue, $realizedPL);

$label = ucfirst($type);
$plMsg = ($type === 'sell') ? ' | Realized P/L: ' . formatPL($realizedPL) : '';
flash('success', "{$label} of " . formatCrypto($amount) . " {$token['symbol']} at \${$ppu} recorded.{$plMsg}");

header('Location: token.php?id=' . $token['id']);
exit;
