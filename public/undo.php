<?php
/**
 * Undo the last portfolio action (buy, sell, or swap).
 * Destructive: removes the recorded transaction(s). POST + CSRF only.
 *
 * The action to undo is determined entirely server-side from the user's stored
 * undo record — no client-supplied transaction id is trusted. `return_id` only
 * controls where the user is sent back to.
 */

require_once __DIR__ . '/../cryptracker/includes/auth.php';
require_once __DIR__ . '/../cryptracker/includes/helpers.php';

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

csrfGuard();

$returnId = (int) ($_POST['return_id'] ?? 0);
$back = ($returnId > 0 && dbGetUserToken($returnId, $user['id']))
    ? 'token.php?id=' . $returnId
    : 'index.php';

$result = undoLastAction($user['id']);

if ($result['ok']) {
    $label = $result['label'] !== '' ? $result['label'] : 'the last action';
    flash('success', 'Undone — removed ' . $label . '.');
} else {
    flash('error', $result['error']);
}

header('Location: ' . $back);
exit;
