<?php
/**
 * Profit/Loss Calculation Tests
 *
 * Tests both weighted-average and FIFO (exact) cost basis P/L
 * calculations via the calcTokenPL() helper.
 */

/**
 * Calculate P/L the same way transaction.php does (average mode).
 */
function _calcPL(int $tokenId, float $sellAmount, float $sellPrice): array
{
    $stats    = dbGetTokenStats($tokenId);
    $holdings = $stats['bought'] - $stats['sold'];
    $avgBuy   = ($stats['bought'] > 0) ? ($stats['spent'] / $stats['bought']) : 0;

    $realizedPL = $sellAmount * ($sellPrice - $avgBuy);

    return [
        'holdings'    => $holdings,
        'avgBuy'      => $avgBuy,
        'realizedPL'  => $realizedPL,
    ];
}

function test_pl_simple_profit(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('pltest', 'pl@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'BTC', 'Bitcoin', 'bitcoin');

    // Buy 1 BTC at $40,000
    dbInsertTransaction($tid, 'buy', 1.0, 40000.0, 40000.0, 0.0);

    // Sell 1 BTC at $50,000 → P/L = 1 × (50000 - 40000) = +$10,000
    $pl = _calcPL($tid, 1.0, 50000.0);
    assert_equals(1.0, $pl['holdings'], 'Holdings before sell should be 1');
    assert_equals(40000.0, $pl['avgBuy'], 'Avg buy should be 40000');
    assert_equals(10000.0, $pl['realizedPL'], 'Realized P/L should be +10000');

    dbPurgeAll();
}

function test_pl_simple_loss(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('pltest2', 'pl2@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'BTC', 'Bitcoin', 'bitcoin');

    // Buy 2 BTC at $50,000 each
    dbInsertTransaction($tid, 'buy', 2.0, 50000.0, 100000.0, 0.0);

    // Sell 1 BTC at $30,000 → P/L = 1 × (30000 - 50000) = -$20,000
    $pl = _calcPL($tid, 1.0, 30000.0);
    assert_equals(-20000.0, $pl['realizedPL'], 'Realized P/L should be -20000');

    dbPurgeAll();
}

function test_pl_weighted_average(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('pltest3', 'pl3@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'ETH', 'Ethereum', 'ethereum');

    // Buy 10 ETH at $2,000 = $20,000
    dbInsertTransaction($tid, 'buy', 10.0, 2000.0, 20000.0, 0.0);
    // Buy 5 ETH at $3,000 = $15,000
    dbInsertTransaction($tid, 'buy', 5.0, 3000.0, 15000.0, 0.0);

    // Total: 15 ETH, spent $35,000 → avg buy = $2,333.33...
    $pl = _calcPL($tid, 3.0, 2800.0);
    $expectedAvg = 35000.0 / 15.0;
    assert_equals($expectedAvg, $pl['avgBuy'], 'Avg buy should be weighted average');

    // Sell 3 ETH at $2,800 → P/L = 3 × (2800 - 2333.33) = 3 × 466.67 = $1,400
    $expectedPL = 3.0 * (2800.0 - $expectedAvg);
    assert_equals($expectedPL, $pl['realizedPL'], 'Realized P/L should match weighted avg calc');
    assert_greater_than($pl['realizedPL'], 0.0, 'Should be a profit');

    dbPurgeAll();
}

function test_pl_partial_sell(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('pltest4', 'pl4@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'SOL', 'Solana', 'solana');

    // Buy 100 SOL at $20 = $2,000
    dbInsertTransaction($tid, 'buy', 100.0, 20.0, 2000.0, 0.0);

    // Sell 30 SOL at $25 → P/L = 30 × (25 - 20) = $150
    $pl = _calcPL($tid, 30.0, 25.0);
    assert_equals(100.0, $pl['holdings'], 'Holdings should be 100 before sell');
    assert_equals(150.0, $pl['realizedPL'], 'Realized P/L should be 150');

    dbInsertTransaction($tid, 'sell', 30.0, 25.0, 750.0, 150.0);

    $stats = dbGetTokenStats($tid);
    $remaining = $stats['bought'] - $stats['sold'];
    assert_equals(70.0, $remaining, 'Remaining holdings should be 70');

    dbPurgeAll();
}

function test_pl_multiple_sells(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('pltest5', 'pl5@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'DOT', 'Polkadot', 'polkadot');

    // Buy 50 DOT at $10 = $500
    dbInsertTransaction($tid, 'buy', 50.0, 10.0, 500.0, 0.0);

    // Sell 20 at $15 → P/L = 20 × (15 - 10) = $100
    dbInsertTransaction($tid, 'sell', 20.0, 15.0, 300.0, 100.0);

    // Sell 10 at $8 → P/L = 10 × (8 - 10) = -$20
    dbInsertTransaction($tid, 'sell', 10.0, 8.0, 80.0, -20.0);

    $stats = dbGetTokenStats($tid);
    assert_equals(80.0, $stats['realized_pl'], 'Total realized P/L should be 100 + (-20) = 80');
    assert_equals(20.0, $stats['bought'] - $stats['sold'], 'Remaining should be 20 DOT');

    dbPurgeAll();
}

function test_pl_zero_holdings(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('pltest6', 'pl6@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'XRP', 'XRP', 'xrp');

    // No transactions → zero P/L
    $pl = _calcPL($tid, 0, 0);
    assert_equals(0.0, $pl['holdings'], 'Holdings should be 0');
    assert_equals(0.0, $pl['avgBuy'], 'Avg buy should be 0 with no buys');
    assert_equals(0.0, $pl['realizedPL'], 'P/L should be 0');

    dbPurgeAll();
}

function test_pl_break_even(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('pltest7', 'pl7@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'LTC', 'Litecoin', 'litecoin');

    // Buy 5 LTC at $100
    dbInsertTransaction($tid, 'buy', 5.0, 100.0, 500.0, 0.0);

    // Sell 5 at exactly $100 → P/L should be exactly 0
    $pl = _calcPL($tid, 5.0, 100.0);
    assert_equals(0.0, $pl['realizedPL'], 'Break-even trade should have 0 P/L');

    dbPurgeAll();
}

/**
 * Weighted-average must use a MOVING average: selling draws units out of the
 * cost pool, so a buy after a sell blends against the remaining cost only.
 *
 * Buy 10@$1, sell 10@$2 (realized +10), then buy 10@$10. The first lot is fully
 * gone, so the held 10 units cost $10 each — avg buy $10, not the lifetime
 * blend (110/20 = $5.5). At price $10: unrealized 0, total P/L = realized 10.
 */
function test_pl_avg_moving_average_resets_after_sell(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('avgmove', 'avgmove@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'BTC', 'Bitcoin', 'bitcoin');

    dbInsertTransaction($tid, 'buy',  10.0, 1.0,  10.0,  0.0);
    dbInsertTransaction($tid, 'sell', 10.0, 2.0,  20.0, 10.0);
    dbInsertTransaction($tid, 'buy',  10.0, 10.0, 100.0, 0.0);

    $pl = calcTokenPL($tid, 10.0, 'avg');
    assert_equals(10.0,  round($pl['holdings'], 6),      'AVG: 10 held after sell+rebuy');
    assert_equals(10.0,  round($pl['avg_buy'], 6),       'AVG: moving avg buy = 10 (not lifetime 5.5)');
    assert_equals(100.0, round($pl['cost_basis'], 6),    'AVG: cost basis = 100 (actual paid)');
    assert_equals(10.0,  round($pl['realized_pl'], 6),   'AVG: realized from the sell = +10');
    assert_equals(0.0,   round($pl['unrealized_pl'], 6), 'AVG: unrealized at $10 = 0');
    assert_equals(10.0,  round($pl['total_pl'], 6),      'AVG: total P/L = 10, not overstated');

    dbPurgeAll();
}

/**
 * Interleaved buy-after-sell with a leftover partial lot.
 * Buy 4@$10, sell 2@$20 (avg $10 → realized +20), buy 2@$30.
 * Remaining pool: 2 units left from lot#1 at $10 + 2 units at $30 = $80 / 4 = $20 avg.
 * At price $25: unrealized = 4×25 - 80 = 20, total = 20 + 20 = 40.
 */
function test_pl_avg_interleaved_partial_lot(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('avgint', 'avgint@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'ETH', 'Ethereum', 'ethereum');

    dbInsertTransaction($tid, 'buy',  4.0, 10.0, 40.0,  0.0);
    dbInsertTransaction($tid, 'sell', 2.0, 20.0, 40.0, 20.0);
    dbInsertTransaction($tid, 'buy',  2.0, 30.0, 60.0,  0.0);

    $pl = calcTokenPL($tid, 25.0, 'avg');
    assert_equals(4.0,  round($pl['holdings'], 6),      'AVG: 4 held');
    assert_equals(20.0, round($pl['avg_buy'], 6),       'AVG: moving avg = 20');
    assert_equals(80.0, round($pl['cost_basis'], 6),    'AVG: cost basis = 80');
    assert_equals(20.0, round($pl['realized_pl'], 6),   'AVG: realized = +20');
    assert_equals(20.0, round($pl['unrealized_pl'], 6), 'AVG: unrealized at $25 = 20');
    assert_equals(40.0, round($pl['total_pl'], 6),      'AVG: total = 40');

    dbPurgeAll();
}

/* ── FIFO (Exact) Mode Tests ─────────────────────────────── */

/**
 * User's example 1: buy 1 SOL@85, buy 0.5 SOL@86, sell 1 SOL@84
 * FIFO: sells the first lot (1@85), realized = 1×84 - 1×85 = -1
 * Remaining 0.5 SOL from second lot @86
 */
function test_fifo_user_example_1(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('fifo1', 'fifo1@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'SOL', 'Solana', 'solana');

    dbInsertTransaction($tid, 'buy', 1.0, 85.0, 85.0, 0.0);
    dbInsertTransaction($tid, 'buy', 0.5, 86.0, 43.0, 0.0);
    dbInsertTransaction($tid, 'sell', 1.0, 84.0, 84.0, 0.0);

    $pl = calcTokenPL($tid, 84.0, 'fifo');
    assert_equals(-1.0, round($pl['realized_pl'], 6), 'FIFO: realized = 1×84 - 1×85 = -1');
    assert_equals(0.5, round($pl['holdings'], 6), 'FIFO: 0.5 SOL remaining');

    // Unrealized at $84: 0.5×84 - 0.5×86 = -1
    assert_equals(-1.0, round($pl['unrealized_pl'], 6), 'FIFO: unrealized at 84 = -1');

    // Verify avg_buy of remaining lot = 86
    assert_equals(86.0, round($pl['avg_buy'], 6), 'FIFO: remaining lot bought at 86');

    dbPurgeAll();
}

/**
 * User's example 2: buy 1 SOL@85, buy 2 SOL@88, sell 1.5 SOL@86
 * FIFO: sells 1@85 + 0.5@88, realized = 1.5×86 - (1×85 + 0.5×88) = 129 - 129 = 0
 * Remaining: 1.5 SOL from second lot @88
 */
function test_fifo_user_example_2(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('fifo2', 'fifo2@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'SOL', 'Solana', 'solana');

    dbInsertTransaction($tid, 'buy', 1.0, 85.0, 85.0, 0.0);
    dbInsertTransaction($tid, 'buy', 2.0, 88.0, 176.0, 0.0);
    dbInsertTransaction($tid, 'sell', 1.5, 86.0, 129.0, 0.0);

    $pl = calcTokenPL($tid, 85.0, 'fifo');
    assert_equals(0.0, round($pl['realized_pl'], 6), 'FIFO: realized = 129 - (85 + 44) = 0');
    assert_equals(1.5, round($pl['holdings'], 6), 'FIFO: 1.5 SOL remaining');

    // Unrealized at $85: 1.5×85 - 1.5×88 = 127.5 - 132 = -4.5
    assert_equals(-4.5, round($pl['unrealized_pl'], 6), 'FIFO: unrealized at 85 = -4.5');

    // Cost basis of remaining = 1.5 × 88 = 132
    assert_equals(132.0, round($pl['cost_basis'], 6), 'FIFO: cost basis = 1.5 × 88');
    assert_equals(88.0, round($pl['avg_buy'], 6), 'FIFO: remaining lot cost = 88');

    dbPurgeAll();
}

/**
 * User's example 2 pre-sell state: buy 1@85 + buy 2@88, check unrealized at 86
 * FIFO unrealized before any sell = 3×86 - (1×85 + 2×88) = 258 - 261 = -3
 */
function test_fifo_unrealized_before_sell(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('fifo3', 'fifo3@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'SOL', 'Solana', 'solana');

    dbInsertTransaction($tid, 'buy', 1.0, 85.0, 85.0, 0.0);
    dbInsertTransaction($tid, 'buy', 2.0, 88.0, 176.0, 0.0);

    $pl = calcTokenPL($tid, 86.0, 'fifo');
    assert_equals(3.0, round($pl['holdings'], 6), 'FIFO: 3 SOL held');
    assert_equals(0.0, $pl['realized_pl'], 'FIFO: no sells yet, realized = 0');
    assert_equals(-3.0, round($pl['unrealized_pl'], 6), 'FIFO: unrealized = 3×86 - 261 = -3');

    dbPurgeAll();
}

/** FIFO simple profit: buy 2@40k, sell 1@50k → realized = 50000 - 40000 = 10000 */
function test_fifo_simple_profit(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('fifo4', 'fifo4@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'BTC', 'Bitcoin', 'bitcoin');

    dbInsertTransaction($tid, 'buy', 2.0, 40000.0, 80000.0, 0.0);
    dbInsertTransaction($tid, 'sell', 1.0, 50000.0, 50000.0, 0.0);

    $pl = calcTokenPL($tid, 50000.0, 'fifo');
    assert_equals(10000.0, round($pl['realized_pl'], 2), 'FIFO: realized = 50000 - 40000 = 10000');
    assert_equals(1.0, round($pl['holdings'], 6), 'FIFO: 1 BTC remaining');

    dbPurgeAll();
}

/** FIFO multiple sells consuming across lots */
function test_fifo_cross_lot_sell(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('fifo5', 'fifo5@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'ETH', 'Ethereum', 'ethereum');

    dbInsertTransaction($tid, 'buy', 3.0, 100.0, 300.0, 0.0);
    dbInsertTransaction($tid, 'buy', 2.0, 200.0, 400.0, 0.0);
    dbInsertTransaction($tid, 'buy', 1.0, 300.0, 300.0, 0.0);

    // Sell 4 ETH@250: consumes 3@100 + 1@200 → cost = 300+200 = 500
    // Realized = 4×250 - 500 = 1000 - 500 = 500
    dbInsertTransaction($tid, 'sell', 4.0, 250.0, 1000.0, 0.0);

    $pl = calcTokenPL($tid, 250.0, 'fifo');
    assert_equals(500.0, round($pl['realized_pl'], 2), 'FIFO cross-lot: realized = 500');
    assert_equals(2.0, round($pl['holdings'], 6), 'FIFO: 2 ETH remaining');

    // Remaining lots: 1@200 + 1@300, cost basis = 500, avg = 250
    assert_equals(500.0, round($pl['cost_basis'], 2), 'FIFO: remaining cost = 500');

    dbPurgeAll();
}

/** FIFO vs Average should give different results for mixed-price lots */
function test_fifo_vs_avg_difference(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('fifoavg', 'fifoavg@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'SOL', 'Solana', 'solana');

    dbInsertTransaction($tid, 'buy', 1.0, 85.0, 85.0, 0.0);
    dbInsertTransaction($tid, 'buy', 0.5, 86.0, 43.0, 0.0);
    dbInsertTransaction($tid, 'sell', 1.0, 84.0, 84.0, 0.0);

    $fifo = calcTokenPL($tid, 84.0, 'fifo');
    $avg  = calcTokenPL($tid, 84.0, 'avg');

    // FIFO: realized = 84 - 85 = -1 (sells exact first lot)
    assert_equals(-1.0, round($fifo['realized_pl'], 6), 'FIFO realized = -1');

    // Avg: avg_buy = 128/1.5 ≈ 85.333, realized = 1 × (84 - 85.333) ≈ -1.333
    $expectedAvgPL = 1.0 * (84.0 - (128.0 / 1.5));
    assert_equals(round($expectedAvgPL, 6), round($avg['realized_pl'], 6), 'Avg realized ≈ -1.333');

    // They should differ
    assert_false(
        round($fifo['realized_pl'], 4) === round($avg['realized_pl'], 4),
        'FIFO and avg should give different P/L for mixed-price lots'
    );

    dbPurgeAll();
}

/** FIFO: sell all holdings should leave zero */
function test_fifo_sell_all(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('fifo6', 'fifo6@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'LTC', 'Litecoin', 'litecoin');

    dbInsertTransaction($tid, 'buy', 5.0, 100.0, 500.0, 0.0);
    dbInsertTransaction($tid, 'buy', 3.0, 120.0, 360.0, 0.0);
    // Sell all 8 @ 110: cost = 5×100 + 3×120 = 860, revenue = 880, P/L = 20
    dbInsertTransaction($tid, 'sell', 8.0, 110.0, 880.0, 0.0);

    $pl = calcTokenPL($tid, 110.0, 'fifo');
    assert_equals(20.0, round($pl['realized_pl'], 2), 'FIFO sell-all: realized = 880 - 860 = 20');
    assert_true($pl['holdings'] < 1e-8, 'FIFO: no holdings after selling all');
    assert_equals(0.0, round($pl['unrealized_pl'], 2), 'FIFO: zero unrealized after selling all');

    dbPurgeAll();
}

/** FIFO timeline should produce correct cumulative totals */
function test_fifo_timeline(): void
{
    dbPurgeAll();
    $uid = dbInsertUser('fifo7', 'fifo7@test.com', password_hash('p', PASSWORD_BCRYPT));
    $tid = dbInsertUserToken($uid, 1, 'SOL', 'Solana', 'solana');

    dbInsertTransaction($tid, 'buy', 1.0, 85.0, 85.0, 0.0);
    dbInsertTransaction($tid, 'buy', 2.0, 88.0, 176.0, 0.0);
    dbInsertTransaction($tid, 'sell', 1.5, 86.0, 129.0, 0.0);

    $pl = calcTokenPL($tid, 86.0, 'fifo');
    assert_equals(3, count($pl['timeline']), 'Timeline should have 3 entries');

    $t0 = $pl['timeline'][0];
    assert_equals('buy', $t0['type'], 'Timeline[0] should be buy');
    assert_equals(1.0, $t0['holdings'], 'After first buy: 1 holding');

    $t1 = $pl['timeline'][1];
    assert_equals(3.0, $t1['holdings'], 'After second buy: 3 holdings');

    $t2 = $pl['timeline'][2];
    assert_equals('sell', $t2['type'], 'Timeline[2] should be sell');
    assert_equals(1.5, round($t2['holdings'], 6), 'After sell: 1.5 holdings');
    assert_equals(0.0, round($t2['realized'], 6), 'FIFO sell realized = 0');

    dbPurgeAll();
}
