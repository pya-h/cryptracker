<?php
/**
 * Add a token to the user's tracked list.
 * POST: cmc_id, symbol, name, slug
 */

require_once __DIR__ . '/../cryptracker/includes/auth.php';
require_once __DIR__ . '/../cryptracker/includes/helpers.php';
require_once __DIR__ . '/../cryptracker/includes/api.php';

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

csrfGuard();

$cmcId  = (int) ($_POST['cmc_id'] ?? 0);
$symbol = trim($_POST['symbol'] ?? '');
$name   = trim($_POST['name']   ?? '');
$slug   = trim($_POST['slug']   ?? '');
$coinloreId = (int) ($_POST['coinlore_id'] ?? 0);
$coingeckoId = strtolower(trim((string) ($_POST['coingecko_id'] ?? '')));

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
    || ($coinloreId < 0)
    || ($coingeckoId !== '' && !preg_match('/^[a-z0-9\-]+$/', $coingeckoId))
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

$resolved = resolveProviderIdsForToken(
    $cmcId,
    $symbol,
    $name,
    $slug,
    $coinloreId > 0 ? $coinloreId : null,
    $coingeckoId !== '' ? $coingeckoId : null
);

$id = dbInsertUserToken(
    $user['id'],
    $cmcId,
    $symbol,
    $name,
    $slug,
    $resolved['coinlore_id'] ?? null,
    $resolved['coingecko_id'] ?? null
);

flash('success', "$symbol added to your portfolio!");
header('Location: token.php?id=' . $id);
exit;
