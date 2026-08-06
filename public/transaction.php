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

$row      = dbGetTokenStats($userTokenId);
$holdings = $row['bought'] - $row['sold'];

// Which wallet does this action touch? When the user has a single (default)
// wallet the selector is hidden and everything routes to it.
$requestedBankId = (int) ($_POST['bank_id'] ?? 0);
$bankSel = bankResolveForTrade($user['id'], $userTokenId, $requestedBankId, $holdings);
if (!$bankSel['ok']) {
    flash('error', $bankSel['error']);
    header('Location: token.php?id=' . $token['id']);
    exit;
}
$bank = $bankSel['bank'];

if ($type === 'sell') {
    // Cap the sell by the chosen wallet's balance. For the sole default wallet
    // this equals total holdings; for a specific wallet it prevents overdrawing
    // it (which would break the "Σ wallets = holdings" invariant).
    if ($amount > $bankSel['balance'] + BANK_EPS) {
        $where = (bankCount($user['id']) >= 2) ? (' held in ' . $bank['name']) : '';
        flash('error', "Cannot sell more than " . formatCrypto($bankSel['balance']) . " {$token['symbol']}{$where}.");
        header('Location: token.php?id=' . $token['id']);
        exit;
    }

    $mode = plMode();
    $realizedPL = realizedPLForSell(dbGetTransactions($userTokenId), $amount, $ppu, $mode);
}
$txId = dbInsertTransaction($userTokenId, $type, $amount, $ppu, $totalValue, $realizedPL);

// Keep the chosen wallet's balance current (default wallet self-adjusts as the
// remainder, so this is a no-op for it). bank_ops let undo reverse the change.
$bankOps = bankApplyDelta($user['id'], (int) $bank['id'], $userTokenId, ($type === 'buy') ? $amount : -$amount);

undoRecordAction($user['id'], $type, [$txId],
    ucfirst($type) . ' of ' . formatCrypto($amount) . ' ' . $token['symbol'], $bankOps);

$label = ucfirst($type);
$plMsg = ($type === 'sell') ? ' | Realized P/L: ' . formatPL($realizedPL) : '';
flash('success', "{$label} of " . formatCrypto($amount) . " {$token['symbol']} at \${$ppu} recorded.{$plMsg}");

header('Location: token.php?id=' . $token['id']);
exit;
