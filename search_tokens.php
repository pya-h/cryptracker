<?php
/**
 * AJAX endpoint – search coins.
 * GET ?q=bitcoin
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/api.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (!currentUser()) { http_response_code(401); echo '[]'; exit; }

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) { echo '[]'; exit; }
if (strlen($q) > 64) {
	$q = substr($q, 0, 64);
}

echo json_encode(apiSearchCoins($q));
