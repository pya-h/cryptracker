<?php
/**
 * AJAX endpoint – search coins.
 * GET ?q=bitcoin
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/api.php';

header('Content-Type: application/json');

if (!currentUser()) { http_response_code(401); echo '[]'; exit; }

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 1) { echo '[]'; exit; }

echo json_encode(apiSearchCoins($q));
