<?php
/**
 * AJAX endpoint — fetch live prices for tracked tokens.
 * GET ?ids=90,80,48543  (comma-separated CoinLore IDs)
 * Returns JSON: { "90": { "price": 95432.10, "percent_change_24h": 1.23 }, ... }
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/api.php';

header('Content-Type: application/json');

if (!currentUser()) { http_response_code(401); echo '{}'; exit; }

$raw = trim($_GET['ids'] ?? '');
if ($raw === '') { echo '{}'; exit; }

$ids = array_filter(array_map('intval', explode(',', $raw)), fn($id) => $id > 0);
if (empty($ids)) { echo '{}'; exit; }

$quotes = coinloreGetQuotes($ids);

$out = [];
foreach ($quotes as $cmcId => $q) {
    $out[$cmcId] = [
        'price'              => (float) $q['price'],
        'percent_change_24h' => (float) ($q['percent_change_24h'] ?? 0),
    ];
}

echo json_encode($out);
