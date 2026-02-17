<?php
/**
 * Profit/Loss Calculation Tests
 *
 * Tests the weighted-average cost basis P/L math that
 * transaction.php uses, replicated here for unit testing.
 */

/**
 * Calculate P/L the same way transaction.php does.
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

    // Record the sell
    dbInsertTransaction($tid, 'sell', 30.0, 25.0, 750.0, 150.0);

    // Verify remaining holdings
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
