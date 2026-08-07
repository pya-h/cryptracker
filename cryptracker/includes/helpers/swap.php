<?php
/**
 * Direct token-to-token conversion (swap).
 *
 * A swap is modelled as two ordinary transactions that the rest of the app
 * already understands:
 *   • a SELL of the source token (Token A) — realized P/L computed via the
 *     active cost-basis mode, exactly like a normal sell.
 *   • a BUY of the target token (Token B) — its cost basis becomes the USD
 *     value received.
 *
 * Both legs carry a human-readable note linking them ("⇄ Swapped to/from …"),
 * so each shows up in its own token's history/chart with clear context and no
 * new table columns.
 *
 * The swap is strictly value-conserving: amountA × priceA must equal
 * amountB × priceB (within a small tolerance for floating-point / rounding),
 * so the proceeds of the sell equal the cost basis of the buy.
 */

const SWAP_VALUE_TOLERANCE = 0.01; // 1% relative mismatch allowed between legs.

/**
 * Perform a swap. Returns:
 *   ['ok' => true,  'realized_pl' => float, 'sell_id' => int, 'buy_id' => int,
 *    'token_a' => array, 'token_b' => array, 'amount_a' => float, 'amount_b' => float]
 *   ['ok' => false, 'error' => string]
 */
function swapTokens(int $userId, int $sourceId, int $targetId, float $amountA, float $amountB, float $priceA, float $priceB, string $mode, int $sourceBankId = 0, int $targetBankId = 0): array
{
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg];

    if ($sourceId === $targetId) {
        return $fail('Cannot swap a token into itself.');
    }

    $tokenA = dbGetUserToken($sourceId, $userId);
    $tokenB = dbGetUserToken($targetId, $userId);
    if (!$tokenA || !$tokenB) {
        return $fail('Invalid token selection.');
    }

    if ($amountA <= 0 || $amountB <= 0 || $priceA <= 0 || $priceB <= 0) {
        return $fail('Amounts and prices must be greater than zero.');
    }

    // Can't convert more of Token A than you currently hold.
    $stats    = dbGetTokenStats($sourceId);
    $holdings = $stats['bought'] - $stats['sold'];
    if ($amountA > $holdings + 1e-9) {
        return $fail('Cannot swap more than current holdings (' . formatCrypto($holdings) . ' ' . $tokenA['symbol'] . ').');
    }

    // Resolve the source wallet (caps how much we can give up) and the target
    // wallet (receives the bought amount). With a single wallet both route to
    // the default, preserving pre-banks behaviour.
    $srcSel = bankResolveForTrade($userId, $sourceId, $sourceBankId, $holdings);
    if (!$srcSel['ok']) return $fail($srcSel['error']);
    if ($amountA > $srcSel['balance'] + BANK_EPS) {
        $where = (bankCount($userId) >= 2) ? (' in ' . $srcSel['bank']['name']) : '';
        return $fail('Cannot swap more than ' . formatCrypto($srcSel['balance']) . ' ' . $tokenA['symbol'] . $where . '.');
    }

    $tStats = dbGetTokenStats($targetId);
    $tHold  = $tStats['bought'] - $tStats['sold'];
    $tgtSel = bankResolveForTrade($userId, $targetId, $targetBankId, $tHold);
    if (!$tgtSel['ok']) return $fail($tgtSel['error']);

    // Enforce value conservation between the two legs.
    $valueA = $amountA * $priceA;
    $valueB = $amountB * $priceB;
    $maxVal = max($valueA, $valueB);
    if ($maxVal > 0 && abs($valueA - $valueB) / $maxVal > SWAP_VALUE_TOLERANCE) {
        return $fail('Swap values do not match. The value given up and received must be equal.');
    }

    // Realized P/L on the sell leg, using the active cost-basis mode.
    $realizedPL = realizedPLForSell(dbGetTransactions($sourceId), $amountA, $priceA, $mode);

    $noteSell = '⇄ Swapped to '   . $tokenB['symbol'];
    $noteBuy  = '⇄ Swapped from ' . $tokenA['symbol'];

    // Snapshot of the swap at the moment it happened, stored identically on both
    // legs so either token's history can render the full "what was exchanged" view.
    $meta = json_encode(['swap' => [
        'from_symbol' => $tokenA['symbol'],
        'from_amount' => $amountA,
        'from_price'  => $priceA,
        'to_symbol'   => $tokenB['symbol'],
        'to_amount'   => $amountB,
        'to_price'    => $priceB,
        'value_usd'   => $valueA,
        'realized_pl' => $realizedPL,
    ]], JSON_UNESCAPED_UNICODE);

    $sellId = dbInsertTransaction($sourceId, 'sell', $amountA, $priceA, $valueA, $realizedPL, $noteSell, $meta);
    $buyId  = dbInsertTransaction($targetId, 'buy',  $amountB, $priceB, $valueB, 0.0,        $noteBuy,  $meta);

    // Move the source wallet's balance out and the target wallet's balance in.
    $bankOps = array_merge(
        bankApplyDelta($userId, (int) $srcSel['bank']['id'], $sourceId, -$amountA),
        bankApplyDelta($userId, (int) $tgtSel['bank']['id'], $targetId,  $amountB)
    );

    return [
        'ok'          => true,
        'error'       => null,
        'realized_pl' => $realizedPL,
        'sell_id'     => $sellId,
        'buy_id'      => $buyId,
        'token_a'     => $tokenA,
        'token_b'     => $tokenB,
        'amount_a'    => $amountA,
        'amount_b'    => $amountB,
        'bank_ops'    => $bankOps,
    ];
}
