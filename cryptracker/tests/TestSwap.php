<?php
/**
 * Token Swap / Convert Tests
 *
 * Exercises swapTokens(): the value-conserving conversion that records a
 * sell of the source token and a buy of the target token, plus the shared
 * realizedPLForSell() engine.
 */

function _swapSetup(string $suffix): array
{
    dbPurgeAll();
    $uid = dbInsertUser('swap' . $suffix, "swap$suffix@test.com", password_hash('p', PASSWORD_BCRYPT));
    $a   = dbInsertUserToken($uid, 1, 'BTC', 'Bitcoin', 'bitcoin');
    $b   = dbInsertUserToken($uid, 2, 'ETH', 'Ethereum', 'ethereum');
    return [$uid, $a, $b];
}

function test_swap_realized_pl_helper_avg(): void
{
    // Buy 10 @ $2000 and 5 @ $3000 → avg 2333.33; sell 3 @ 2800.
    $txs = [
        ['type' => 'buy', 'amount' => 10.0, 'price_per_unit' => 2000.0, 'total_value' => 20000.0],
        ['type' => 'buy', 'amount' => 5.0,  'price_per_unit' => 3000.0, 'total_value' => 15000.0],
    ];
    $avg = 35000.0 / 15.0;
    $expected = 3.0 * (2800.0 - $avg);
    assert_equals(round($expected, 6), round(realizedPLForSell($txs, 3.0, 2800.0, 'avg'), 6), 'avg realized helper');
}

function test_swap_realized_pl_helper_avg_moving_after_sell(): void
{
    // Moving average: buy 10@1, sell 10@2, buy 10@10 leaves the pool at $10 avg.
    // A prospective sell of 5@20 must realize 5×(20-10)=50, NOT the lifetime
    // blend 5×(20-5.5)=72.5 that ignoring the sell would give.
    $txs = [
        ['type' => 'buy',  'amount' => 10.0, 'price_per_unit' => 1.0,  'total_value' => 10.0],
        ['type' => 'sell', 'amount' => 10.0, 'price_per_unit' => 2.0,  'total_value' => 20.0],
        ['type' => 'buy',  'amount' => 10.0, 'price_per_unit' => 10.0, 'total_value' => 100.0],
    ];
    assert_equals(50.0, round(realizedPLForSell($txs, 5.0, 20.0, 'avg'), 6), 'avg moving realized helper');
}

function test_swap_realized_pl_helper_fifo(): void
{
    // buy 1@85, buy 0.5@86, sell 1@84 → FIFO consumes first lot: 84 - 85 = -1.
    $txs = [
        ['type' => 'buy', 'amount' => 1.0, 'price_per_unit' => 85.0, 'total_value' => 85.0],
        ['type' => 'buy', 'amount' => 0.5, 'price_per_unit' => 86.0, 'total_value' => 43.0],
    ];
    assert_equals(-1.0, round(realizedPLForSell($txs, 1.0, 84.0, 'fifo'), 6), 'fifo realized helper');
}

function test_swap_basic_avg(): void
{
    [$uid, $a, $b] = _swapSetup('1');

    // Hold 2 BTC bought at $100 each.
    dbInsertTransaction($a, 'buy', 2.0, 100.0, 200.0, 0.0);

    // Swap 1 BTC (@ $120) → ETH (@ $60): amountB = 1 * 120 / 60 = 2 ETH.
    $res = swapTokens($uid, $a, $b, 1.0, 2.0, 120.0, 60.0, 'avg');

    assert_true($res['ok'], 'swap should succeed');
    // avg buy = 100, realized = 1 * (120 - 100) = 20
    assert_equals(20.0, round($res['realized_pl'], 6), 'swap realized P/L = 20');

    // Source: sold 1, holdings now 1.
    $statsA = dbGetTokenStats($a);
    assert_equals(1.0, $statsA['bought'] - $statsA['sold'], 'BTC holdings after swap = 1');
    assert_equals(20.0, round($statsA['realized_pl'], 6), 'BTC realized recorded = 20');

    // Target: bought 2 ETH, cost basis = value received = $120.
    $plB = calcTokenPL($b, 0.0, 'avg');
    assert_equals(2.0, round($plB['holdings'], 6), 'ETH holdings after swap = 2');
    assert_equals(120.0, round($plB['cost_basis'], 6), 'ETH cost basis = 120');
    assert_equals(60.0, round($plB['avg_buy'], 6), 'ETH avg buy = 60');

    dbPurgeAll();
}

function test_swap_basic_fifo(): void
{
    [$uid, $a, $b] = _swapSetup('2');

    dbInsertTransaction($a, 'buy', 1.0, 85.0, 85.0, 0.0);
    dbInsertTransaction($a, 'buy', 0.5, 86.0, 43.0, 0.0);

    // Swap 1 BTC (@84) → ETH (@42): amountB = 84/42 = 2.
    $res = swapTokens($uid, $a, $b, 1.0, 2.0, 84.0, 42.0, 'fifo');

    assert_true($res['ok'], 'fifo swap should succeed');
    assert_equals(-1.0, round($res['realized_pl'], 6), 'fifo swap realized = -1');

    $statsA = dbGetTokenStats($a);
    assert_equals(0.5, round($statsA['bought'] - $statsA['sold'], 6), 'remaining BTC = 0.5');

    dbPurgeAll();
}

function test_swap_notes_recorded(): void
{
    [$uid, $a, $b] = _swapSetup('3');
    dbInsertTransaction($a, 'buy', 5.0, 10.0, 50.0, 0.0);

    $res = swapTokens($uid, $a, $b, 2.0, 4.0, 10.0, 5.0, 'avg');
    assert_true($res['ok'], 'swap ok');

    $sellTx = dbGetTransactions($a);
    $lastSell = end($sellTx);
    assert_equals('sell', $lastSell['type'], 'source leg is a sell');
    assert_contains($lastSell['note'] ?? '', 'Swapped to', 'sell note present');
    assert_contains($lastSell['note'] ?? '', 'ETH', 'sell note names target symbol');

    $buyTx = dbGetTransactions($b);
    $lastBuy = end($buyTx);
    assert_equals('buy', $lastBuy['type'], 'target leg is a buy');
    assert_contains($lastBuy['note'] ?? '', 'Swapped from', 'buy note present');
    assert_contains($lastBuy['note'] ?? '', 'BTC', 'buy note names source symbol');

    dbPurgeAll();
}

function test_swap_rejects_over_holdings(): void
{
    [$uid, $a, $b] = _swapSetup('4');
    dbInsertTransaction($a, 'buy', 1.0, 100.0, 100.0, 0.0);

    // Try to swap 2 BTC while holding only 1.
    $res = swapTokens($uid, $a, $b, 2.0, 4.0, 100.0, 50.0, 'avg');
    assert_false($res['ok'], 'over-holdings swap rejected');
    assert_contains($res['error'], 'holdings', 'error mentions holdings');

    // No new transactions should have been written.
    assert_equals(1, count(dbGetTransactions($a)), 'no sell leg written on failure');
    assert_equals(0, count(dbGetTransactions($b)), 'no buy leg written on failure');

    dbPurgeAll();
}

function test_swap_rejects_value_mismatch(): void
{
    [$uid, $a, $b] = _swapSetup('5');
    dbInsertTransaction($a, 'buy', 10.0, 10.0, 100.0, 0.0);

    // valueA = 2*10 = 20, valueB = 10*5 = 50 → mismatch.
    $res = swapTokens($uid, $a, $b, 2.0, 10.0, 10.0, 5.0, 'avg');
    assert_false($res['ok'], 'value mismatch rejected');

    dbPurgeAll();
}

function test_swap_rejects_self_and_zero(): void
{
    [$uid, $a, $b] = _swapSetup('6');
    dbInsertTransaction($a, 'buy', 10.0, 10.0, 100.0, 0.0);

    $self = swapTokens($uid, $a, $a, 1.0, 1.0, 10.0, 10.0, 'avg');
    assert_false($self['ok'], 'self-swap rejected');

    $zero = swapTokens($uid, $a, $b, 0.0, 1.0, 10.0, 10.0, 'avg');
    assert_false($zero['ok'], 'zero amount rejected');

    $badToken = swapTokens($uid, $a, 99999, 1.0, 1.0, 10.0, 10.0, 'avg');
    assert_false($badToken['ok'], 'unknown target rejected');

    dbPurgeAll();
}

function test_swap_foreign_token_rejected(): void
{
    [$uid, $a, $b] = _swapSetup('7');
    dbInsertTransaction($a, 'buy', 10.0, 10.0, 100.0, 0.0);

    // A second user owns a different token; user 1 must not swap into it.
    $other = dbInsertUser('swapother', 'swapother@test.com', password_hash('p', PASSWORD_BCRYPT));
    $foreign = dbInsertUserToken($other, 3, 'SOL', 'Solana', 'solana');

    $res = swapTokens($uid, $a, $foreign, 1.0, 1.0, 10.0, 10.0, 'avg');
    assert_false($res['ok'], 'cross-user target rejected');

    dbPurgeAll();
}
