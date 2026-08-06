<?php
/**
 * Banks — user-defined wallets that segment holdings across storage locations.
 *
 * A "bank" is a GLOBAL (per-user) wallet: it exists across every token and
 * holds a per-token balance. Banks are purely a bookkeeping overlay for the
 * user's own reference — they never touch cost-basis, realized/unrealized P/L,
 * charts, or transaction history. A bank only ever influences the *source* or
 * *destination* of a buy / sell / swap (to keep each wallet's balance current)
 * and the maximum a sell/swap may take out of a chosen wallet.
 *
 * Default bank = remainder
 * ────────────────────────
 * Every user has exactly one auto-created default wallet ("Main"). Only
 * NON-default wallets store explicit per-token balances; the default wallet's
 * balance for a token is DERIVED as:
 *     default = totalHoldings(token) − Σ(explicit non-default balances)
 * This keeps the invariant "Σ wallets = total holdings" true by construction:
 *   • pre-existing assets need no migration (they sit in the remainder),
 *   • a buy/sell into the default wallet needs no bookkeeping,
 *   • undo of a default-wallet trade is automatic.
 * Only non-default effects are persisted (and reversed on undo).
 *
 * Because a create/delete/move mutates wallet state OUTSIDE the transaction
 * log, performing one CLEARS the single-step undo (the same "most recent
 * action only" guarantee the trade undo relies on) so a later undo can never
 * apply a stale balance reversal.
 */

const BANK_DEFAULT_NAME = 'Main';
const BANK_MAX          = 12;    // sane ceiling on wallets per user
const BANK_EPS          = 1e-9;  // holdings comparison tolerance

/** Create the default wallet if missing; return its id. */
function bankEnsureDefault(int $userId): int
{
    return dbEnsureDefaultBank($userId);
}

/** All wallets for the user (default first), guaranteeing the default exists. */
function banksList(int $userId): array
{
    dbEnsureDefaultBank($userId);
    return dbGetBanks($userId);
}

function bankCount(int $userId): int
{
    return count(banksList($userId));
}

/** The user's default wallet row. */
function bankDefault(int $userId): array
{
    $id = dbEnsureDefaultBank($userId);
    return dbGetBank($id, $userId);
}

/**
 * The default wallet's balance of a token: the remainder of total holdings
 * after every explicit (non-default) balance. Clamped at zero to absorb
 * floating-point dust.
 */
function bankDefaultRemainder(int $tokenId, float $totalHoldings): float
{
    $sum = 0.0;
    foreach (dbGetBankBalancesForToken($tokenId) as $amt) {
        $sum += (float) $amt;
    }
    $rem = $totalHoldings - $sum;
    return $rem > BANK_EPS ? $rem : 0.0;
}

/** This token's amount held in a given wallet (default = remainder). */
function bankTokenBalance(int $userId, int $bankId, int $tokenId, float $totalHoldings): float
{
    $bank = dbGetBank($bankId, $userId);
    if ($bank === null) return 0.0;
    if (!empty($bank['is_default'])) {
        return bankDefaultRemainder($tokenId, $totalHoldings);
    }
    return dbGetBankBalance($bankId, $tokenId);
}

/**
 * Per-wallet breakdown of THIS token for display (default first):
 *   [ ['id','name','is_default','amount'], ... ]
 */
function bankBreakdownForToken(int $userId, int $tokenId, float $totalHoldings): array
{
    $explicit = dbGetBankBalancesForToken($tokenId);
    $out = [];
    foreach (banksList($userId) as $b) {
        $bid = (int) $b['id'];
        $amt = !empty($b['is_default'])
            ? bankDefaultRemainder($tokenId, $totalHoldings)
            : (float) ($explicit[$bid] ?? 0.0);
        $out[] = [
            'id'         => $bid,
            'name'       => $b['name'],
            'is_default' => !empty($b['is_default']) ? 1 : 0,
            'amount'     => $amt,
        ];
    }
    return $out;
}

/** Valid wallet name: 1–40 chars, ASCII letters/digits/space and . _ - # ( ). */
function bankNameValid(string $name): bool
{
    // ASCII-only by the pattern below, so byte length == character length.
    return $name !== ''
        && strlen($name) <= 40
        && preg_match('/^[A-Za-z0-9 ._\-#()]+$/', $name) === 1;
}

/**
 * Apply a signed change to a wallet's balance of a token. The default wallet is
 * the remainder, so it is never stored — only non-default wallets persist.
 * Returns the undo op(s) that reverse this (empty for the default wallet).
 */
function bankApplyDelta(int $userId, int $bankId, int $tokenId, float $delta): array
{
    $bank = dbGetBank($bankId, $userId);
    if ($bank === null || !empty($bank['is_default'])) {
        return [];
    }
    $new = dbGetBankBalance($bankId, $tokenId) + $delta;
    if ($new < 0 && $new > -BANK_EPS) $new = 0.0; // clamp dust
    dbSetBankBalance($bankId, $tokenId, $new);
    return [['bank_id' => $bankId, 'token_id' => $tokenId, 'delta' => $delta]];
}

/**
 * Reverse the bank ops recorded on an undone trade. Applies the opposite delta
 * directly (ops are only ever recorded for still-existing non-default wallets,
 * because any intervening bank change clears the undo record).
 */
function bankReverseOps(array $bankOps): void
{
    foreach ($bankOps as $op) {
        $bankId  = (int) ($op['bank_id'] ?? 0);
        $tokenId = (int) ($op['token_id'] ?? 0);
        $delta   = (float) ($op['delta'] ?? 0);
        if ($bankId <= 0 || $tokenId <= 0 || $delta === 0.0) continue;
        $new = dbGetBankBalance($bankId, $tokenId) - $delta;
        if ($new < 0 && $new > -BANK_EPS) $new = 0.0;
        dbSetBankBalance($bankId, $tokenId, $new);
    }
}

/**
 * Resolve which wallet a trade should hit. When the user has a single (default)
 * wallet the selector is hidden client-side and everything routes to the
 * default. Returns ['ok','error','bank'=>row,'balance'=>float(this token)].
 */
function bankResolveForTrade(int $userId, int $tokenId, int $requestedBankId, float $totalHoldings): array
{
    if (count(banksList($userId)) < 2) {
        $def = bankDefault($userId);
        return [
            'ok'      => true,
            'error'   => null,
            'bank'    => $def,
            'balance' => bankDefaultRemainder($tokenId, $totalHoldings),
        ];
    }

    $bank = $requestedBankId > 0 ? dbGetBank($requestedBankId, $userId) : null;
    if ($bank === null) {
        return ['ok' => false, 'error' => 'Please choose a valid bank for this action.'];
    }
    return [
        'ok'      => true,
        'error'   => null,
        'bank'    => $bank,
        'balance' => bankTokenBalance($userId, (int) $bank['id'], $tokenId, $totalHoldings),
    ];
}

/**
 * Create a new (non-default) wallet. Returns ['ok','error','id'?].
 */
function bankCreate(int $userId, string $name): array
{
    $name = trim($name);
    if (!bankNameValid($name)) {
        return ['ok' => false, 'error' => 'Bank name must be 1–40 characters (letters, numbers, spaces, . _ - # ( )).'];
    }

    dbEnsureDefaultBank($userId);
    $banks = dbGetBanks($userId);

    foreach ($banks as $b) {
        if (strcasecmp(trim((string) $b['name']), $name) === 0) {
            return ['ok' => false, 'error' => 'A bank with that name already exists.'];
        }
    }
    if (count($banks) >= BANK_MAX) {
        return ['ok' => false, 'error' => 'You have reached the maximum of ' . BANK_MAX . ' banks.'];
    }

    $id = dbCreateBank($userId, $name);
    dbSetUserUndo($userId, null); // a bank change consumes the single-step undo
    return ['ok' => true, 'error' => null, 'id' => $id];
}

/**
 * Delete a non-default wallet. Refuses while it still holds any asset.
 */
function bankDelete(int $userId, int $bankId): array
{
    $bank = dbGetBank($bankId, $userId);
    if ($bank === null) {
        return ['ok' => false, 'error' => 'Bank not found.'];
    }
    if (!empty($bank['is_default'])) {
        return ['ok' => false, 'error' => 'The default bank cannot be removed.'];
    }
    if (dbBankHasAnyBalance($bankId)) {
        return ['ok' => false, 'error' => 'This bank still holds assets. Move them out before removing it.'];
    }

    dbDeleteBank($bankId);
    dbSetUserUndo($userId, null);
    return ['ok' => true, 'error' => null];
}

/**
 * Move an amount of one token between two of the user's wallets.
 */
function bankMove(int $userId, int $fromId, int $toId, int $tokenId, float $amount, float $totalHoldings): array
{
    if ($fromId === $toId) {
        return ['ok' => false, 'error' => 'Source and destination banks must differ.'];
    }
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Move amount must be greater than zero.'];
    }

    $from = dbGetBank($fromId, $userId);
    $to   = dbGetBank($toId, $userId);
    if ($from === null || $to === null) {
        return ['ok' => false, 'error' => 'Invalid bank selection.'];
    }

    $fromBal = bankTokenBalance($userId, $fromId, $tokenId, $totalHoldings);
    if ($amount > $fromBal + BANK_EPS) {
        return ['ok' => false, 'error' => 'Cannot move more than ' . formatCrypto($fromBal) . ' held in ' . $from['name'] . '.'];
    }

    // Default wallets are the remainder, so their deltas are no-ops (the
    // remainder shifts automatically as the explicit side changes).
    bankApplyDelta($userId, $fromId, $tokenId, -$amount);
    bankApplyDelta($userId, $toId,   $tokenId,  $amount);
    dbSetUserUndo($userId, null);

    return ['ok' => true, 'error' => null];
}
