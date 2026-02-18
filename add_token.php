<?php
/**
 * Add a token to the user's tracked list.
 * POST: cmc_id, symbol, name, slug
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

csrfGuard();

$cmcId  = (int) ($_POST['cmc_id'] ?? 0);
$symbol = trim($_POST['symbol'] ?? '');
$name   = trim($_POST['name']   ?? '');
$slug   = trim($_POST['slug']   ?? '');

$symbol = strtoupper($symbol);

if (
    $cmcId <= 0
    || $symbol === ''
    || $name === ''
    || strlen($symbol) > 20
    || strlen($name) > 120
    || strlen($slug) > 120
    || !preg_match('/^[A-Z0-9.\-]+$/', $symbol)
    || ($slug !== '' && !preg_match('/^[a-z0-9\-]+$/', $slug))
) {
    flash('error', 'Invalid token data.');
    header('Location: index.php');
    exit;
}

$existing = dbGetUserTokenByCmc($user['id'], $cmcId);

if ($existing) {
    header('Location: token.php?id=' . $existing['id']);
    exit;
}

$id = dbInsertUserToken($user['id'], $cmcId, $symbol, $name, $slug);

flash('success', "$symbol added to your portfolio!");
header('Location: token.php?id=' . $id);
exit;
