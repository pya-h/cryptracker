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
    $realizedPL = realizedPLForSell(dbGetTransactions($userTokenId), $amount, $ppu, $mode);
}
$txId = dbInsertTransaction($userTokenId, $type, $amount, $ppu, $totalValue, $realizedPL);
undoRecordAction($user['id'], $type, [$txId],
    ucfirst($type) . ' of ' . formatCrypto($amount) . ' ' . $token['symbol']);

$label = ucfirst($type);
$plMsg = ($type === 'sell') ? ' | Realized P/L: ' . formatPL($realizedPL) : '';
flash('success', "{$label} of " . formatCrypto($amount) . " {$token['symbol']} at \${$ppu} recorded.{$plMsg}");

header('Location: token.php?id=' . $token['id']);
exit;
