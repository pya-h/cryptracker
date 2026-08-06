<?php
/**
 * Bank (wallet) management: create, delete, and move assets between wallets.
 * POST + CSRF only. Banks are a display-only segmentation of holdings — this
 * endpoint never records a transaction and never touches P/L, charts, or
 * history. A successful action clears the single-step undo (see helpers/bank.php).
 */

require_once __DIR__ . '/../cryptracker/includes/auth.php';
require_once __DIR__ . '/../cryptracker/includes/helpers.php';

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

csrfGuard();

$action   = $_POST['action'] ?? '';
$returnId = (int) ($_POST['return_id'] ?? 0);
$back = ($returnId > 0 && dbGetUserToken($returnId, $user['id']))
    ? 'token.php?id=' . $returnId
    : 'index.php';

switch ($action) {
    case 'create':
        $res = bankCreate($user['id'], (string) ($_POST['bank_name'] ?? ''));
        flash($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Bank created.' : $res['error']);
        break;

    case 'delete':
        $res = bankDelete($user['id'], (int) ($_POST['bank_id'] ?? 0));
        flash($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Bank removed.' : $res['error']);
        break;

    case 'move':
        $tokenId = (int) ($_POST['token_id'] ?? 0);
        $token   = dbGetUserToken($tokenId, $user['id']);
        if (!$token) {
            flash('error', 'Invalid token for this move.');
            break;
        }
        $stats    = dbGetTokenStats($tokenId);
        $holdings = $stats['bought'] - $stats['sold'];
        $res = bankMove(
            $user['id'],
            (int) ($_POST['from_bank_id'] ?? 0),
            (int) ($_POST['to_bank_id'] ?? 0),
            $tokenId,
            (float) ($_POST['amount'] ?? 0),
            $holdings
        );
        flash($res['ok'] ? 'success' : 'error',
            $res['ok'] ? 'Assets moved.' : $res['error']);
        break;

    default:
        flash('error', 'Unknown bank action.');
}

header('Location: ' . $back);
exit;
