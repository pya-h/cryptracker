<?php
/**
 * Banks (wallet segmentation) Tests
 *
 * Exercises the global-wallet model in helpers/bank.php: the auto-created
 * default wallet, the "default = remainder" balance derivation, create/delete/
 * move validation, the per-trade balance effects (and their reversal on undo),
 * and the swap source/target routing. The core invariant under test throughout
 * is: for every token, Σ(wallet balances) == total holdings.
 */

function _bankSetup(string $suffix): array
{
    dbPurgeAll();
    $uid = dbInsertUser('bank' . $suffix, "bank$suffix@test.com", password_hash('p', PASSWORD_BCRYPT));
    $a   = dbInsertUserToken($uid, 1, 'BTC', 'Bitcoin', 'bitcoin');
    $b   = dbInsertUserToken($uid, 2, 'ETH', 'Ethereum', 'ethereum');
    return [$uid, $a, $b];
}

function _bankHoldings(int $tokenId): float
{
    $s = dbGetTokenStats($tokenId);
    return $s['bought'] - $s['sold'];
}

/** Σ of every wallet's balance of a token, via the display breakdown. */
function _bankSum(int $uid, int $tokenId): float
{
    $sum = 0.0;
    foreach (bankBreakdownForToken($uid, $tokenId, _bankHoldings($tokenId)) as $b) {
        $sum += $b['amount'];
    }
    return $sum;
}

/**
 * Mirror the buy/sell handler core so bank effects are exercised end-to-end:
 * insert the transaction, then apply the wallet delta. Returns [txId, bankOps].
 */
function _bankTrade(int $uid, int $tokenId, int $bankId, string $type, float $amount, float $ppu): array
{
    $holdings = _bankHoldings($tokenId);
    $sel = bankResolveForTrade($uid, $tokenId, $bankId, $holdings);
    $txId = dbInsertTransaction($tokenId, $type, $amount, $ppu, $amount * $ppu, 0.0);
    $ops = bankApplyDelta($uid, (int) $sel['bank']['id'], $tokenId, $type === 'buy' ? $amount : -$amount);
    return [$txId, $ops];
}

/* ── Default wallet & basics ─────────────────────────────────── */

function test_bank_default_auto_created(): void
{
    [$uid, $a] = _bankSetup('1');

    $banks = banksList($uid);
    assert_equals(1, count($banks), 'fresh user has exactly one wallet');
    assert_equals(1, (int) $banks[0]['is_default'], 'the sole wallet is the default');
    assert_equals('Main', $banks[0]['name'], 'default wallet is named Main');
    assert_equals(1, bankCount($uid), 'bankCount is 1');

    dbPurgeAll();
}

function test_bank_default_holds_all_by_remainder(): void
{
    [$uid, $a] = _bankSetup('2');
    dbInsertTransaction($a, 'buy', 10.0, 100.0, 1000.0, 0.0);

    // With no explicit wallets, the default remainder == total holdings.
    $def = bankDefault($uid);
    assert_equals(10.0, bankTokenBalance($uid, (int) $def['id'], $a, 10.0), 'default holds all 10');
    assert_equals(10.0, _bankSum($uid, $a), 'Σ wallets == holdings');

    dbPurgeAll();
}

function test_bank_resolve_single_wallet_ignores_request(): void
{
    [$uid, $a] = _bankSetup('3');
    dbInsertTransaction($a, 'buy', 5.0, 10.0, 50.0, 0.0);

    // Single wallet: any requested id routes to the default, balance = holdings.
    $sel = bankResolveForTrade($uid, $a, 99999, 5.0);
    assert_true($sel['ok'], 'resolves ok with a single wallet');
    assert_equals(1, (int) $sel['bank']['is_default'], 'routes to default wallet');
    assert_equals(5.0, $sel['balance'], 'balance equals total holdings');

    dbPurgeAll();
}

/* ── Create / list ───────────────────────────────────────────── */

function test_bank_create_and_ordering(): void
{
    [$uid] = _bankSetup('4');
    $res = bankCreate($uid, 'Ledger');
    assert_true($res['ok'], 'create Ledger ok');

    $banks = banksList($uid);
    assert_equals(2, count($banks), 'now two wallets');
    assert_equals(1, (int) $banks[0]['is_default'], 'default listed first');
    assert_equals('Ledger', $banks[1]['name'], 'new wallet listed after default');
    assert_equals(2, bankCount($uid), 'bankCount is 2');

    dbPurgeAll();
}

function test_bank_create_rejects_duplicate_name(): void
{
    [$uid] = _bankSetup('5');
    assert_true(bankCreate($uid, 'Ledger')['ok'], 'first Ledger ok');

    $dup = bankCreate($uid, 'ledger'); // case-insensitive clash
    assert_false($dup['ok'], 'duplicate (case-insensitive) rejected');
    assert_contains($dup['error'], 'already exists', 'duplicate error mentions existence');

    $dupDefault = bankCreate($uid, 'Main');
    assert_false($dupDefault['ok'], 'clashing with the default name rejected');

    dbPurgeAll();
}

function test_bank_create_rejects_bad_name(): void
{
    [$uid] = _bankSetup('6');
    assert_false(bankCreate($uid, '')['ok'], 'empty name rejected');
    assert_false(bankCreate($uid, '   ')['ok'], 'whitespace-only name rejected');
    assert_false(bankCreate($uid, str_repeat('x', 41))['ok'], 'over-long name rejected');
    assert_false(bankCreate($uid, 'bad/name')['ok'], 'illegal character rejected');
    assert_true(bankCreate($uid, 'Cold Wallet #1 (2024)')['ok'], 'allowed punctuation accepted');
    dbPurgeAll();
}

function test_bank_create_enforces_max(): void
{
    [$uid] = _bankSetup('7');
    // Default already exists; add up to the cap.
    for ($i = 1; $i < BANK_MAX; $i++) {
        assert_true(bankCreate($uid, 'W' . $i)['ok'], "create W$i under cap");
    }
    assert_equals(BANK_MAX, bankCount($uid), 'at the wallet cap');
    $over = bankCreate($uid, 'OneTooMany');
    assert_false($over['ok'], 'creating beyond the cap is rejected');
    assert_contains($over['error'], 'maximum', 'cap error mentions maximum');
    dbPurgeAll();
}

/* ── Move ────────────────────────────────────────────────────── */

function test_bank_move_shifts_balances(): void
{
    [$uid, $a] = _bankSetup('8');
    dbInsertTransaction($a, 'buy', 10.0, 100.0, 1000.0, 0.0);
    $ledger = bankCreate($uid, 'Ledger')['id'];
    $def    = (int) bankDefault($uid)['id'];

    $res = bankMove($uid, $def, $ledger, $a, 4.0, 10.0);
    assert_true($res['ok'], 'move 4 from default to Ledger ok');

    assert_equals(6.0, bankTokenBalance($uid, $def, $a, 10.0), 'default remainder now 6');
    assert_equals(4.0, bankTokenBalance($uid, $ledger, $a, 10.0), 'Ledger now 4');
    assert_equals(10.0, _bankSum($uid, $a), 'Σ wallets still == holdings after move');

    // Move part back to the default (remainder grows automatically).
    assert_true(bankMove($uid, $ledger, $def, $a, 1.5, 10.0)['ok'], 'move back ok');
    assert_equals(2.5, bankTokenBalance($uid, $ledger, $a, 10.0), 'Ledger now 2.5');
    assert_equals(7.5, bankTokenBalance($uid, $def, $a, 10.0), 'default now 7.5');
    assert_equals(10.0, _bankSum($uid, $a), 'invariant holds after moving back');

    dbPurgeAll();
}

function test_bank_move_validation(): void
{
    [$uid, $a] = _bankSetup('9');
    dbInsertTransaction($a, 'buy', 5.0, 10.0, 50.0, 0.0);
    $ledger = bankCreate($uid, 'Ledger')['id'];
    $def    = (int) bankDefault($uid)['id'];

    assert_false(bankMove($uid, $def, $def, $a, 1.0, 5.0)['ok'], 'same source/dest rejected');
    assert_false(bankMove($uid, $def, $ledger, $a, 0.0, 5.0)['ok'], 'zero amount rejected');
    assert_false(bankMove($uid, $def, $ledger, $a, -2.0, 5.0)['ok'], 'negative amount rejected');
    assert_false(bankMove($uid, $def, $ledger, $a, 6.0, 5.0)['ok'], 'over-source-balance rejected');
    assert_false(bankMove($uid, $def, 99999, $a, 1.0, 5.0)['ok'], 'unknown destination rejected');

    // Ledger is empty, so it cannot be the source of a move.
    assert_false(bankMove($uid, $ledger, $def, $a, 1.0, 5.0)['ok'], 'moving from empty wallet rejected');

    dbPurgeAll();
}

function test_bank_move_foreign_wallet_rejected(): void
{
    [$uid, $a]  = _bankSetup('10');
    dbInsertTransaction($a, 'buy', 5.0, 10.0, 50.0, 0.0);
    $def = (int) bankDefault($uid)['id'];

    $other  = dbInsertUser('bankother', 'bankother@test.com', password_hash('p', PASSWORD_BCRYPT));
    $foreign = bankCreate($other, 'Theirs')['id'];

    $res = bankMove($uid, $def, $foreign, $a, 1.0, 5.0);
    assert_false($res['ok'], 'cannot move into another user\'s wallet');

    dbPurgeAll();
}

/* ── Delete ──────────────────────────────────────────────────── */

function test_bank_delete_rules(): void
{
    [$uid, $a] = _bankSetup('11');
    dbInsertTransaction($a, 'buy', 8.0, 10.0, 80.0, 0.0);
    $ledger = bankCreate($uid, 'Ledger')['id'];
    $def    = (int) bankDefault($uid)['id'];

    assert_false(bankDelete($uid, $def)['ok'], 'default wallet cannot be deleted');

    bankMove($uid, $def, $ledger, $a, 3.0, 8.0);
    $nonEmpty = bankDelete($uid, $ledger);
    assert_false($nonEmpty['ok'], 'non-empty wallet cannot be deleted');
    assert_contains($nonEmpty['error'], 'still holds', 'delete error explains why');

    // Empty it, then delete succeeds and the default reabsorbs everything.
    bankMove($uid, $ledger, $def, $a, 3.0, 8.0);
    assert_true(bankDelete($uid, $ledger)['ok'], 'empty wallet deletes ok');
    assert_equals(1, bankCount($uid), 'back to one wallet');
    assert_equals(8.0, bankTokenBalance($uid, $def, $a, 8.0), 'default holds all again');

    dbPurgeAll();
}

/* ── Per-trade balance effects ───────────────────────────────── */

function test_bank_buy_into_nondefault_keeps_invariant(): void
{
    [$uid, $a] = _bankSetup('12');
    // Seed some holdings in the default, then add a second wallet.
    dbInsertTransaction($a, 'buy', 2.0, 100.0, 200.0, 0.0);
    $ledger = bankCreate($uid, 'Ledger')['id'];
    $def    = (int) bankDefault($uid)['id'];

    // Buy 3 more, routed into Ledger.
    [, $ops] = _bankTrade($uid, $a, $ledger, 'buy', 3.0, 120.0);
    assert_equals(1, count($ops), 'a non-default buy records one bank op');
    assert_equals(5.0, _bankHoldings($a), 'total holdings 5');
    assert_equals(3.0, bankTokenBalance($uid, $ledger, $a, 5.0), 'Ledger holds the 3 bought');
    assert_equals(2.0, bankTokenBalance($uid, $def, $a, 5.0), 'default still holds its original 2');
    assert_equals(5.0, _bankSum($uid, $a), 'Σ wallets == holdings after bank buy');

    dbPurgeAll();
}

function test_bank_sell_from_nondefault_keeps_invariant(): void
{
    [$uid, $a] = _bankSetup('13');
    dbInsertTransaction($a, 'buy', 10.0, 100.0, 1000.0, 0.0);
    $ledger = bankCreate($uid, 'Ledger')['id'];
    $def    = (int) bankDefault($uid)['id'];
    bankMove($uid, $def, $ledger, $a, 6.0, 10.0); // default 4, ledger 6

    [, $ops] = _bankTrade($uid, $a, $ledger, 'sell', 2.0, 150.0);
    assert_equals(8.0, _bankHoldings($a), 'holdings 8 after selling 2');
    assert_equals(4.0, bankTokenBalance($uid, $ledger, $a, 8.0), 'Ledger 6→4 after sell');
    assert_equals(4.0, bankTokenBalance($uid, $def, $a, 8.0), 'default unchanged at 4');
    assert_equals(8.0, _bankSum($uid, $a), 'invariant holds after bank sell');

    dbPurgeAll();
}

function test_bank_apply_delta_default_is_noop(): void
{
    [$uid, $a] = _bankSetup('14');
    dbInsertTransaction($a, 'buy', 5.0, 10.0, 50.0, 0.0);
    $def = (int) bankDefault($uid)['id'];

    $ops = bankApplyDelta($uid, $def, $a, 3.0);
    assert_equals(0, count($ops), 'default wallet delta records no op');
    assert_equals(0.0, dbGetBankBalance($def, $a), 'default wallet never stores a balance row');

    dbPurgeAll();
}

function test_bank_resolve_requires_valid_choice_with_multiple(): void
{
    [$uid, $a] = _bankSetup('15');
    dbInsertTransaction($a, 'buy', 5.0, 10.0, 50.0, 0.0);
    bankCreate($uid, 'Ledger');

    $sel = bankResolveForTrade($uid, $a, 0, 5.0);
    assert_false($sel['ok'], 'with 2+ wallets a valid bank must be chosen');

    dbPurgeAll();
}

/* ── Undo integration ────────────────────────────────────────── */

function test_bank_undo_reverses_buy_effect(): void
{
    [$uid, $a] = _bankSetup('16');
    dbInsertTransaction($a, 'buy', 2.0, 100.0, 200.0, 0.0);
    $ledger = bankCreate($uid, 'Ledger')['id'];

    [$txId, $ops] = _bankTrade($uid, $a, $ledger, 'buy', 3.0, 120.0);
    undoRecordAction($uid, 'buy', [$txId], 'Buy of 3 BTC', $ops);
    assert_equals(3.0, bankTokenBalance($uid, $ledger, $a, 5.0), 'Ledger holds 3 before undo');

    $res = undoLastAction($uid);
    assert_true($res['ok'], 'undo succeeds');
    assert_equals(2.0, _bankHoldings($a), 'holdings back to 2');
    assert_equals(0.0, bankTokenBalance($uid, $ledger, $a, 2.0), 'Ledger balance reversed to 0');
    assert_equals(2.0, _bankSum($uid, $a), 'invariant restored after undo');

    dbPurgeAll();
}

function test_bank_move_clears_undo(): void
{
    [$uid, $a] = _bankSetup('17');
    $buyId = dbInsertTransaction($a, 'buy', 10.0, 10.0, 100.0, 0.0);
    undoRecordAction($uid, 'buy', [$buyId], 'Buy of 10 BTC');
    $ledger = bankCreate($uid, 'Ledger')['id'];
    // Creating a wallet already clears undo; re-arm it to test move explicitly.
    undoRecordAction($uid, 'buy', [$buyId], 'Buy of 10 BTC');
    assert_not_null(getUndoableAction($uid), 're-armed undo present');

    $def = (int) bankDefault($uid)['id'];
    bankMove($uid, $def, $ledger, $a, 2.0, 10.0);
    assert_null(getUndoableAction($uid), 'a move consumes the single-step undo');

    dbPurgeAll();
}

function test_bank_create_and_delete_clear_undo(): void
{
    [$uid, $a] = _bankSetup('18');
    $buyId = dbInsertTransaction($a, 'buy', 4.0, 10.0, 40.0, 0.0);

    undoRecordAction($uid, 'buy', [$buyId], 'Buy');
    bankCreate($uid, 'Ledger');
    assert_null(getUndoableAction($uid), 'creating a wallet clears undo');

    $ledger = banksList($uid)[1]['id'];
    undoRecordAction($uid, 'buy', [$buyId], 'Buy');
    bankDelete($uid, (int) $ledger);
    assert_null(getUndoableAction($uid), 'deleting a wallet clears undo');

    dbPurgeAll();
}

/* ── Swap routing & undo ─────────────────────────────────────── */

function test_swap_routes_source_and_target_wallets(): void
{
    [$uid, $a, $b] = _bankSetup('19');
    dbInsertTransaction($a, 'buy', 10.0, 100.0, 1000.0, 0.0);
    $ledger = bankCreate($uid, 'Ledger')['id'];
    $cold   = bankCreate($uid, 'Cold')['id'];
    $defA   = (int) bankDefault($uid)['id'];
    bankMove($uid, $defA, $ledger, $a, 3.0, 10.0); // 3 BTC in Ledger

    // Swap 3 BTC (from Ledger) → 6 ETH (into Cold): 3*100 == 6*50.
    $res = swapTokens($uid, $a, $b, 3.0, 6.0, 100.0, 50.0, 'avg', $ledger, $cold);
    assert_true($res['ok'], 'bank-routed swap succeeds');

    assert_equals(0.0, bankTokenBalance($uid, $ledger, $a, _bankHoldings($a)), 'Ledger BTC drained to 0');
    assert_equals(7.0, bankTokenBalance($uid, $defA, $a, _bankHoldings($a)), 'default BTC untouched at 7');
    assert_equals(6.0, dbGetBankBalance($cold, $b), 'Cold received the 6 ETH');
    assert_equals(6.0, _bankSum($uid, $b), 'ETH invariant holds');
    assert_equals(7.0, _bankSum($uid, $a), 'BTC invariant holds after swap');

    dbPurgeAll();
}

function test_swap_capped_by_source_wallet(): void
{
    [$uid, $a, $b] = _bankSetup('20');
    dbInsertTransaction($a, 'buy', 10.0, 100.0, 1000.0, 0.0);
    $ledger = bankCreate($uid, 'Ledger')['id'];
    $cold   = bankCreate($uid, 'Cold')['id'];
    $defA   = (int) bankDefault($uid)['id'];
    bankMove($uid, $defA, $ledger, $a, 3.0, 10.0); // only 3 BTC in Ledger

    // Try to swap 5 BTC out of Ledger which holds only 3 — must fail even though
    // total holdings (10) would allow it.
    $res = swapTokens($uid, $a, $b, 5.0, 10.0, 100.0, 50.0, 'avg', $ledger, $cold);
    assert_false($res['ok'], 'swap exceeding source wallet balance rejected');
    assert_contains($res['error'], 'Ledger', 'error names the source wallet');
    assert_equals(0, count(dbGetTransactions($b)), 'no target buy leg written on failure');

    dbPurgeAll();
}

function test_swap_bank_ops_reverse_on_undo(): void
{
    [$uid, $a, $b] = _bankSetup('21');
    dbInsertTransaction($a, 'buy', 10.0, 100.0, 1000.0, 0.0);
    $ledger = bankCreate($uid, 'Ledger')['id'];
    $cold   = bankCreate($uid, 'Cold')['id'];
    $defA   = (int) bankDefault($uid)['id'];
    bankMove($uid, $defA, $ledger, $a, 3.0, 10.0);

    $res = swapTokens($uid, $a, $b, 3.0, 6.0, 100.0, 50.0, 'avg', $ledger, $cold);
    assert_true($res['ok'], 'swap ok');
    undoRecordAction($uid, 'swap', [(int) $res['sell_id'], (int) $res['buy_id']], 'Swap', $res['bank_ops']);

    $undo = undoLastAction($uid);
    assert_true($undo['ok'], 'swap undo ok');
    assert_equals(3.0, bankTokenBalance($uid, $ledger, $a, _bankHoldings($a)), 'Ledger BTC restored to 3');
    assert_equals(0.0, dbGetBankBalance($cold, $b), 'Cold ETH reversed to 0');
    assert_equals(10.0, _bankSum($uid, $a), 'BTC invariant restored');
    assert_equals(0.0, _bankHoldings($b), 'ETH holdings back to 0');

    dbPurgeAll();
}

function test_bank_token_removal_cleans_balances(): void
{
    [$uid, $a, $b] = _bankSetup('22');
    dbInsertTransaction($a, 'buy', 10.0, 100.0, 1000.0, 0.0);
    $ledger = bankCreate($uid, 'Ledger')['id'];
    $def    = (int) bankDefault($uid)['id'];
    bankMove($uid, $def, $ledger, $a, 4.0, 10.0);
    assert_equals(4.0, dbGetBankBalance($ledger, $a), 'Ledger holds 4 BTC');

    dbDeleteUserToken($a);
    assert_equals(0.0, dbGetBankBalance($ledger, $a), 'balances for a removed token are cleared');
    assert_false(dbBankHasAnyBalance($ledger), 'Ledger reports empty after token removal');

    dbPurgeAll();
}
