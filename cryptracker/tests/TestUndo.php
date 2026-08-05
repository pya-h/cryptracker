<?php
/**
 * Undo Last Action Tests
 *
 * Exercises undoRecordAction() / getUndoableAction() / undoLastAction():
 * the single-step undo that removes the most recent buy, sell, or swap and
 * refuses anything else.
 */

function _undoSetup(string $suffix): array
{
    dbPurgeAll();
    $uid = dbInsertUser('undo' . $suffix, "undo$suffix@test.com", password_hash('p', PASSWORD_BCRYPT));
    $a   = dbInsertUserToken($uid, 1, 'BTC', 'Bitcoin', 'bitcoin');
    $b   = dbInsertUserToken($uid, 2, 'ETH', 'Ethereum', 'ethereum');
    return [$uid, $a, $b];
}

function _holdings(int $tokenId): float
{
    $s = dbGetTokenStats($tokenId);
    return $s['bought'] - $s['sold'];
}

function test_undo_none_for_fresh_user(): void
{
    [$uid] = _undoSetup('1');
    assert_true(getUndoableAction($uid) === null, 'no undoable action for a fresh user');

    $res = undoLastAction($uid);
    assert_false($res['ok'], 'undo fails when there is nothing to undo');
    dbPurgeAll();
}

function test_undo_buy_removes_transaction(): void
{
    [$uid, $a] = _undoSetup('2');
    $txId = dbInsertTransaction($a, 'buy', 2.0, 100.0, 200.0, 0.0);
    undoRecordAction($uid, 'buy', [$txId], 'Buy of 2 BTC');

    $u = getUndoableAction($uid);
    assert_equals('buy', $u['kind'], 'recorded kind is buy');
    assert_equals(1, count($u['tx_ids']), 'buy records one transaction');

    $res = undoLastAction($uid);
    assert_true($res['ok'], 'buy undo succeeds');
    assert_equals(1, $res['deleted'], 'one row deleted');
    assert_equals(0, count(dbGetTransactions($a)), 'buy transaction removed');
    assert_equals(0.0, _holdings($a), 'holdings back to zero');
    dbPurgeAll();
}

function test_undo_sell_restores_holdings_and_pl(): void
{
    [$uid, $a] = _undoSetup('3');
    dbInsertTransaction($a, 'buy', 5.0, 100.0, 500.0, 0.0);
    $sellId = dbInsertTransaction($a, 'sell', 2.0, 150.0, 300.0, 100.0);
    undoRecordAction($uid, 'sell', [$sellId], 'Sell of 2 BTC');

    assert_equals(3.0, _holdings($a), 'holdings are 3 before undo');
    assert_equals(100.0, round(dbGetTokenStats($a)['realized_pl'], 6), 'realized P/L 100 before undo');

    $res = undoLastAction($uid);
    assert_true($res['ok'], 'sell undo succeeds');
    assert_equals(5.0, _holdings($a), 'holdings restored to 5');
    assert_equals(0.0, round(dbGetTokenStats($a)['realized_pl'], 6), 'realized P/L reverted to 0');
    dbPurgeAll();
}

function test_undo_swap_removes_both_legs(): void
{
    [$uid, $a, $b] = _undoSetup('4');
    dbInsertTransaction($a, 'buy', 2.0, 100.0, 200.0, 0.0);

    $res = swapTokens($uid, $a, $b, 1.0, 2.0, 120.0, 60.0, 'avg');
    assert_true($res['ok'], 'swap succeeds');
    undoRecordAction($uid, 'swap', [$res['sell_id'], $res['buy_id']], 'Swap 1 BTC → 2 ETH');

    assert_equals(1.0, _holdings($a), 'BTC holdings 1 after swap');
    assert_equals(2.0, _holdings($b), 'ETH holdings 2 after swap');

    $undo = undoLastAction($uid);
    assert_true($undo['ok'], 'swap undo succeeds');
    assert_equals(2, $undo['deleted'], 'both swap legs deleted');
    assert_equals(2.0, _holdings($a), 'BTC holdings restored to 2');
    assert_equals(0.0, _holdings($b), 'ETH holdings back to 0');
    assert_equals(1, count(dbGetTransactions($a)), 'only the original BTC buy remains');
    assert_equals(0, count(dbGetTransactions($b)), 'ETH has no transactions again');
    dbPurgeAll();
}

function test_undo_only_once_per_action(): void
{
    [$uid, $a] = _undoSetup('5');
    dbInsertTransaction($a, 'buy', 1.0, 10.0, 10.0, 0.0);
    $txId = dbInsertTransaction($a, 'buy', 2.0, 20.0, 40.0, 0.0);
    undoRecordAction($uid, 'buy', [$txId], 'Buy of 2 BTC');

    $first = undoLastAction($uid);
    assert_true($first['ok'], 'first undo succeeds');

    // Nothing is undoable now, even though an earlier buy still exists.
    assert_true(getUndoableAction($uid) === null, 'undo consumed after use');
    $second = undoLastAction($uid);
    assert_false($second['ok'], 'second consecutive undo is refused');
    assert_equals(1, count(dbGetTransactions($a)), 'the earlier buy is untouched');
    dbPurgeAll();
}

function test_undo_refuses_when_not_latest(): void
{
    [$uid, $a] = _undoSetup('6');
    $oldId = dbInsertTransaction($a, 'buy', 1.0, 10.0, 10.0, 0.0);
    undoRecordAction($uid, 'buy', [$oldId], 'Buy of 1 BTC');

    // A newer transaction appears without updating the undo record (rogue path).
    dbInsertTransaction($a, 'buy', 3.0, 30.0, 90.0, 0.0);

    $res = undoLastAction($uid);
    assert_false($res['ok'], 'stale (non-latest) undo is refused');
    assert_contains($res['error'], 'most recent', 'error explains only latest is undoable');
    assert_equals(2, count(dbGetTransactions($a)), 'no rows deleted on refusal');
    assert_true(getUndoableAction($uid) === null, 'stale record is cleared');
    dbPurgeAll();
}

function test_undo_refuses_missing_transaction(): void
{
    [$uid, $a] = _undoSetup('7');
    dbInsertTransaction($a, 'buy', 1.0, 10.0, 10.0, 0.0);
    undoRecordAction($uid, 'buy', [999999], 'Buy of 1 BTC');

    $res = undoLastAction($uid);
    assert_false($res['ok'], 'undo referencing a missing tx is refused');
    assert_equals(1, count(dbGetTransactions($a)), 'existing rows untouched');
    assert_true(getUndoableAction($uid) === null, 'stale record cleared');
    dbPurgeAll();
}

function test_undo_refuses_foreign_transaction(): void
{
    [$uid, $a] = _undoSetup('8');
    dbInsertTransaction($a, 'buy', 1.0, 10.0, 10.0, 0.0);

    // Another user's transaction must never be undoable by this user.
    $other  = dbInsertUser('undoother', 'undoother@test.com', password_hash('p', PASSWORD_BCRYPT));
    $ot     = dbInsertUserToken($other, 3, 'SOL', 'Solana', 'solana');
    $foreign = dbInsertTransaction($ot, 'buy', 5.0, 5.0, 25.0, 0.0);

    // Force a crafted record pointing at the foreign transaction.
    dbSetUserUndo($uid, ['tx_ids' => [$foreign], 'kind' => 'buy', 'label' => 'x']);

    $res = undoLastAction($uid);
    assert_false($res['ok'], 'cross-user undo refused');
    assert_equals(1, count(dbGetTransactions($ot)), 'foreign transaction untouched');
    dbPurgeAll();
}

function test_undo_record_overwrites_previous(): void
{
    [$uid, $a] = _undoSetup('9');
    $first  = dbInsertTransaction($a, 'buy', 1.0, 10.0, 10.0, 0.0);
    undoRecordAction($uid, 'buy', [$first], 'Buy of 1 BTC');
    $second = dbInsertTransaction($a, 'buy', 2.0, 20.0, 40.0, 0.0);
    undoRecordAction($uid, 'buy', [$second], 'Buy of 2 BTC');

    $u = getUndoableAction($uid);
    assert_equals([$second], $u['tx_ids'], 'only the latest action is undoable');

    $res = undoLastAction($uid);
    assert_true($res['ok'], 'latest undo succeeds');
    assert_equals(1, count(dbGetTransactions($a)), 'earlier buy remains after undoing the later one');
    dbPurgeAll();
}
