<?php
/**
 * Undo the last portfolio action (a buy, a sell, or a swap).
 *
 * Design & safety
 * ───────────────
 * Only ONE action can be undone at a time: the user's most recent one. After an
 * undo it is consumed — nothing else can be undone until a new action is made.
 *
 * "Most recent only" is not merely a UX rule; it is what makes deletion safe.
 * Realized P/L is computed and stored at insert time from the transactions that
 * existed then. A transaction can only ever influence the stored P/L of a LATER
 * same-token sell (FIFO lots / average cost). The newest action has, by
 * definition, no later transaction depending on it — so removing it can never
 * corrupt another record's stored P/L. Undoing an older, middle transaction
 * could, which is exactly why it is refused.
 *
 * The single source of truth for "what is undoable right now" is a small record
 * stored on the user row (see dbGetUserUndo / dbSetUserUndo):
 *   ['tx_ids' => int[], 'kind' => 'buy'|'sell'|'swap', 'label' => string]
 * A buy/sell records one transaction id; a swap records both legs (sell + buy),
 * so undoing a swap atomically removes both. Every action-recording endpoint
 * overwrites this record, and undoLastAction() clears it, guaranteeing the
 * one-undo-per-action semantics.
 */

const UNDO_KINDS = ['buy', 'sell', 'swap'];

/**
 * Mark an action as the currently undoable one, replacing any previous record.
 * Called by the buy/sell and swap endpoints right after their transactions are
 * written. Silently ignores unknown kinds or empty id lists.
 */
function undoRecordAction(int $userId, string $kind, array $txIds, string $label, array $bankOps = []): void
{
    if (!in_array($kind, UNDO_KINDS, true)) return;

    $txIds = array_values(array_filter(array_map('intval', $txIds), fn($id) => $id > 0));
    if (empty($txIds)) return;

    $record = [
        'tx_ids' => $txIds,
        'kind'   => $kind,
        'label'  => $label,
    ];
    // Non-default wallet balance changes to reverse if this action is undone.
    if (!empty($bankOps)) {
        $record['bank_ops'] = array_values($bankOps);
    }

    dbSetUserUndo($userId, $record);
}

/**
 * Return the currently undoable action (normalised) for rendering the undo
 * control, or null if there is none. Cheap — no existence/ownership checks;
 * the authoritative verification happens in undoLastAction().
 */
function getUndoableAction(int $userId): ?array
{
    $undo = dbGetUserUndo($userId);
    if (!is_array($undo)) return null;

    $txIds = array_values(array_filter(array_map('intval', $undo['tx_ids'] ?? []), fn($id) => $id > 0));
    if (empty($txIds)) return null;

    return [
        'tx_ids'   => $txIds,
        'kind'     => in_array(($undo['kind'] ?? ''), UNDO_KINDS, true) ? $undo['kind'] : 'action',
        'label'    => (string) ($undo['label'] ?? ''),
        'bank_ops' => (isset($undo['bank_ops']) && is_array($undo['bank_ops'])) ? $undo['bank_ops'] : [],
    ];
}

/**
 * Perform the undo. Returns:
 *   ['ok' => true,  'kind' => string, 'label' => string, 'deleted' => int]
 *   ['ok' => false, 'error' => string]
 *
 * Refuses (and clears the stale record) unless the recorded action still exists,
 * is owned by the user, and is still the user's most recent transaction.
 */
function undoLastAction(int $userId): array
{
    $fail = fn(string $msg): array => ['ok' => false, 'error' => $msg];

    $undo = getUndoableAction($userId);
    if ($undo === null) {
        return $fail('There is no recent action to undo.');
    }

    $txIds  = $undo['tx_ids'];
    $maxRef = 0;

    // Every referenced transaction must still exist and belong to this user.
    foreach ($txIds as $id) {
        $tx = dbGetTransactionById($id);
        if ($tx === null || dbGetUserToken((int) $tx['user_token_id'], $userId) === null) {
            dbSetUserUndo($userId, null);
            return $fail('The last action can no longer be undone.');
        }
        if ((int) $tx['id'] > $maxRef) $maxRef = (int) $tx['id'];
    }

    // Defense-in-depth: only the user's most recent action may be undone, so an
    // undo can never delete a transaction a later record's stored P/L relies on.
    $currentMax = dbGetMaxTransactionIdForUser($userId);
    if ($currentMax === null || $currentMax !== $maxRef) {
        dbSetUserUndo($userId, null);
        return $fail('Only the most recent action can be undone.');
    }

    $deleted = dbDeleteTransactions($txIds);
    // Reverse any non-default wallet balance changes this action made. Safe
    // because any intervening bank op would have cleared this undo record.
    bankReverseOps($undo['bank_ops'] ?? []);
    dbSetUserUndo($userId, null);

    return [
        'ok'      => true,
        'error'   => null,
        'kind'    => $undo['kind'],
        'label'   => $undo['label'],
        'deleted' => $deleted,
    ];
}
