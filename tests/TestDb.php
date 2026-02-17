<?php
/**
 * Database Layer Tests
 */

function test_db_user_crud(): void
{
    dbPurgeAll();

    // Insert user
    $id = dbInsertUser('testuser', 'test@example.com', password_hash('secret', PASSWORD_BCRYPT));
    assert_greater_than($id, 0, 'User ID should be positive');

    // Retrieve by ID
    $user = dbGetUserById($id);
    assert_not_null($user, 'User should be found by ID');
    assert_equals('testuser', $user['username'], 'Username should match');
    assert_equals('test@example.com', $user['email'], 'Email should match');

    // Retrieve by field
    $byName = dbGetUserByField('username', 'testuser');
    assert_not_null($byName, 'User should be found by username');
    assert_equals($id, $byName['id'], 'IDs should match');

    $byEmail = dbGetUserByField('email', 'test@example.com');
    assert_not_null($byEmail, 'User should be found by email');

    // Case-insensitive lookup
    $byUpper = dbGetUserByField('username', 'TESTUSER');
    assert_not_null($byUpper, 'Username lookup should be case-insensitive');

    // Non-existent user
    $none = dbGetUserByField('username', 'nonexistent');
    assert_null($none, 'Non-existent user should return null');

    dbPurgeAll();
}

function test_db_field_whitelist(): void
{
    dbPurgeAll();
    dbInsertUser('alice', 'alice@example.com', password_hash('pass', PASSWORD_BCRYPT));

    // Disallowed field should return null (security: prevents arbitrary field access)
    $result = dbGetUserByField('password_hash', 'anything');
    assert_null($result, 'Should not allow lookup by password_hash field');

    dbPurgeAll();
}

function test_db_user_token_crud(): void
{
    dbPurgeAll();

    $uid = dbInsertUser('bob', 'bob@example.com', password_hash('pass', PASSWORD_BCRYPT));
    $tokenId = dbInsertUserToken($uid, 1, 'BTC', 'Bitcoin', 'bitcoin');
    assert_greater_than($tokenId, 0, 'Token ID should be positive');

    // Get tokens for user
    $tokens = dbGetUserTokens($uid);
    assert_equals(1, count($tokens), 'Should have 1 token');
    assert_equals('BTC', $tokens[0]['symbol'], 'Symbol should be BTC');

    // Get single token
    $t = dbGetUserToken($tokenId, $uid);
    assert_not_null($t, 'Token should be found');
    assert_equals('Bitcoin', $t['name'], 'Name should match');

    // Wrong user shouldn't access token
    $wrong = dbGetUserToken($tokenId, 999);
    assert_null($wrong, 'Wrong user should not access token');

    // Get by CMC ID
    $byCmc = dbGetUserTokenByCmc($uid, 1);
    assert_not_null($byCmc, 'Should find token by CMC ID');

    dbPurgeAll();
}

function test_db_transaction_crud(): void
{
    dbPurgeAll();

    $uid     = dbInsertUser('charlie', 'charlie@example.com', password_hash('p', PASSWORD_BCRYPT));
    $tokenId = dbInsertUserToken($uid, 1, 'BTC', 'Bitcoin', 'bitcoin');

    // Insert buy
    $txId = dbInsertTransaction($tokenId, 'buy', 1.5, 40000.0, 60000.0, 0.0);
    assert_greater_than($txId, 0, 'Transaction ID should be positive');

    // Retrieve buy transactions
    $buys = dbGetTransactions($tokenId, 'buy');
    assert_equals(1, count($buys), 'Should have 1 buy');
    assert_equals(1.5, $buys[0]['amount'], 'Buy amount should match');
    assert_equals(60000.0, $buys[0]['total_value'], 'Total value should match');

    // Insert sell
    $txId2 = dbInsertTransaction($tokenId, 'sell', 0.5, 50000.0, 25000.0, 5000.0);
    assert_greater_than($txId2, $txId, 'Second tx ID should be larger');

    $sells = dbGetTransactions($tokenId, 'sell');
    assert_equals(1, count($sells), 'Should have 1 sell');
    assert_equals(5000.0, $sells[0]['realized_pl'], 'Realized P/L should be 5000');

    // All transactions (desc order)
    $all = dbGetTransactionsDesc($tokenId);
    assert_equals(2, count($all), 'Should have 2 total transactions');
    assert_equals('sell', $all[0]['type'], 'Most recent should be sell');

    dbPurgeAll();
}

function test_db_token_stats(): void
{
    dbPurgeAll();

    $uid     = dbInsertUser('dave', 'dave@example.com', password_hash('p', PASSWORD_BCRYPT));
    $tokenId = dbInsertUserToken($uid, 1, 'ETH', 'Ethereum', 'ethereum');

    dbInsertTransaction($tokenId, 'buy', 10.0, 2000.0, 20000.0, 0.0);
    dbInsertTransaction($tokenId, 'buy', 5.0, 2200.0, 11000.0, 0.0);
    dbInsertTransaction($tokenId, 'sell', 3.0, 2500.0, 7500.0, 1000.0);

    $stats = dbGetTokenStats($tokenId);

    assert_equals(15.0, $stats['bought'], 'Total bought should be 15');
    assert_equals(3.0, $stats['sold'], 'Total sold should be 3');
    assert_equals(31000.0, $stats['spent'], 'Total spent should be 31000');
    assert_equals(1000.0, $stats['realized_pl'], 'Realized P/L should be 1000');

    dbPurgeAll();
}

function test_db_delete_cascade(): void
{
    dbPurgeAll();

    $uid     = dbInsertUser('eve', 'eve@example.com', password_hash('p', PASSWORD_BCRYPT));
    $tokenId = dbInsertUserToken($uid, 1, 'SOL', 'Solana', 'solana');

    dbInsertTransaction($tokenId, 'buy', 100.0, 30.0, 3000.0, 0.0);
    dbInsertTransaction($tokenId, 'buy', 50.0, 35.0, 1750.0, 0.0);

    // Delete token — should cascade delete transactions
    dbDeleteUserToken($tokenId);

    $tokens = dbGetUserTokens($uid);
    assert_equals(0, count($tokens), 'Token should be deleted');

    $txs = dbGetTransactions($tokenId);
    assert_equals(0, count($txs), 'Transactions should be cascade-deleted');

    dbPurgeAll();
}

function test_db_invalid_tx_type(): void
{
    dbPurgeAll();

    $uid     = dbInsertUser('frank', 'frank@example.com', password_hash('p', PASSWORD_BCRYPT));
    $tokenId = dbInsertUserToken($uid, 1, 'ADA', 'Cardano', 'cardano');

    $threw = false;
    try {
        dbInsertTransaction($tokenId, 'steal', 100.0, 1.0, 100.0, 0.0);
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assert_true($threw, 'Should throw on invalid transaction type');

    dbPurgeAll();
}

function test_db_multiple_users_isolation(): void
{
    dbPurgeAll();

    $uid1 = dbInsertUser('user1', 'user1@test.com', password_hash('p', PASSWORD_BCRYPT));
    $uid2 = dbInsertUser('user2', 'user2@test.com', password_hash('p', PASSWORD_BCRYPT));

    $token1 = dbInsertUserToken($uid1, 1, 'BTC', 'Bitcoin', 'bitcoin');
    $token2 = dbInsertUserToken($uid2, 2, 'ETH', 'Ethereum', 'ethereum');

    // User 1 should not see user 2's tokens
    $u1tokens = dbGetUserTokens($uid1);
    assert_equals(1, count($u1tokens), 'User 1 should have 1 token');
    assert_equals('BTC', $u1tokens[0]['symbol'], 'User 1 token should be BTC');

    $u2tokens = dbGetUserTokens($uid2);
    assert_equals(1, count($u2tokens), 'User 2 should have 1 token');
    assert_equals('ETH', $u2tokens[0]['symbol'], 'User 2 token should be ETH');

    // Cross-user access forbidden
    $cross = dbGetUserToken($token1, $uid2);
    assert_null($cross, 'User 2 should not access User 1 token');

    dbPurgeAll();
}
