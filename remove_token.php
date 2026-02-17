<?php
/**
 * Remove a token and all its transactions.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

csrfGuard();

$id = (int) ($_POST['id'] ?? 0);

$token = dbGetUserToken($id, $user['id']);

if (!$token) { header('Location: index.php'); exit; }

dbDeleteUserToken($id);

flash('success', $token['symbol'] . ' removed from your portfolio.');
header('Location: index.php');
exit;
