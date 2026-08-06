<?php
/**
 * Handle a direct token-to-token conversion (swap).
 * Records a SELL of the source token and a BUY of the target token.
 */

require_once __DIR__ . '/../cryptracker/includes/auth.php';
require_once __DIR__ . '/../cryptracker/includes/helpers.php';

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

csrfGuard();

$sourceId = (int)   ($_POST['user_token_id']   ?? 0);
$targetId = (int)   ($_POST['target_token_id'] ?? 0);
$amountA  = (float) ($_POST['amount_a']         ?? 0);
$amountB  = (float) ($_POST['amount_b']         ?? 0);
$priceA   = (float) ($_POST['price_a']          ?? 0);
$priceB   = (float) ($_POST['price_b']          ?? 0);
$srcBank  = (int)   ($_POST['source_bank_id']   ?? 0);
$tgtBank  = (int)   ($_POST['target_bank_id']   ?? 0);

$backId = $sourceId > 0 ? $sourceId : 0;
$back   = fn(): string => $backId > 0 ? 'token.php?id=' . $backId : 'index.php';

$result = swapTokens($user['id'], $sourceId, $targetId, $amountA, $amountB, $priceA, $priceB, plMode(), $srcBank, $tgtBank);

if (!$result['ok']) {
    flash('error', $result['error']);
    header('Location: ' . $back());
    exit;
}

$a = $result['token_a'];
$b = $result['token_b'];

undoRecordAction($user['id'], 'swap',
    [(int) $result['sell_id'], (int) $result['buy_id']],
    'Swap ' . formatCrypto($result['amount_a']) . ' ' . $a['symbol'] .
    ' → ' . formatCrypto($result['amount_b']) . ' ' . $b['symbol'],
    $result['bank_ops'] ?? []);

flash('success',
    'Swapped ' . formatCrypto($result['amount_a']) . ' ' . $a['symbol'] .
    ' → ' . formatCrypto($result['amount_b']) . ' ' . $b['symbol'] .
    ' | Realized P/L: ' . formatPL($result['realized_pl'])
);

header('Location: token.php?id=' . $a['id']);
exit;
