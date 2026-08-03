<?php
/**
 * Toggle P/L calculation mode between 'avg' (weighted average) and 'fifo' (exact/FIFO).
 * POST endpoint — stores preference in session.
 */

require_once __DIR__ . '/../cryptracker/includes/auth.php';
require_once __DIR__ . '/../cryptracker/includes/helpers.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

csrfGuard();

$_SESSION['pl_mode'] = (plMode() === 'avg') ? 'fifo' : 'avg';

$redirect = $_POST['redirect'] ?? 'index.php';
$allowed  = ['index.php'];
if (preg_match('/^token\.php\?id=\d+$/', $redirect)) {
    $allowed[] = $redirect;
}
$target = in_array($redirect, $allowed) ? $redirect : 'index.php';

header('Location: ' . $target);
exit;
