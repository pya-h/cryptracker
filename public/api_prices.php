<?php
/**
 * AJAX endpoint — fetch live prices for tracked tokens.
 * GET ?ids=1,1027,825  (comma-separated CMC IDs)
 * Returns JSON: { quotes: { "<cmcId>": { price, percent_change_24h, … } }, meta: {…} }
 */

require_once __DIR__ . '/../cryptracker/includes/auth.php';
require_once __DIR__ . '/../cryptracker/includes/api.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (!currentUser()) { http_response_code(401); echo '{}'; exit; }

$raw = trim($_GET['ids'] ?? '');
if ($raw === '') { echo '{}'; exit; }

$rawParts = explode(',', $raw);
if (count($rawParts) > 200) { echo '{}'; exit; }

$ids = array_filter(array_map('intval', $rawParts), fn($id) => $id > 0);
$ids = array_values(array_unique($ids));
if (count($ids) > 100) {
    $ids = array_slice($ids, 0, 100);
}

if (empty($ids)) { echo '{}'; exit; }

$result = apiGetQuotesDetailed($ids, true);
$quotes = $result['quotes'] ?? [];
$meta = $result['meta'] ?? [];

$out = [];
foreach ($quotes as $cmcId => $q) {
    $out[$cmcId] = [
        'price'              => (float) ($q['price'] ?? 0),
        'percent_change_1h'  => (float) ($q['percent_change_1h'] ?? 0),
        'percent_change_24h' => (float) ($q['percent_change_24h'] ?? 0),
        'percent_change_7d'  => (float) ($q['percent_change_7d'] ?? 0),
        'market_cap'         => (float) ($q['market_cap'] ?? 0),
        'volume_24h'         => (float) ($q['volume_24h'] ?? 0),
        'rank'               => (int) ($q['rank'] ?? 0),
        'csupply'            => (float) ($q['csupply'] ?? 0),
        'tsupply'            => (float) ($q['tsupply'] ?? 0),
        'msupply'            => (float) ($q['msupply'] ?? 0),
    ];
}

echo json_encode([
    'quotes' => $out,
    'meta' => [
        'preferred_source_before' => $meta['preferred_source_before'] ?? selectedPriceSource(),
        'preferred_source_after' => $meta['preferred_source_after'] ?? selectedPriceSource(),
        'used_source' => $meta['used_source'] ?? '',
        'fallback_used' => (bool) ($meta['fallback_used'] ?? false),
        'auto_switched' => (bool) ($meta['auto_switched'] ?? false),
        'auto_switched_to' => $meta['auto_switched_to'] ?? '',
        'toast_message' => $meta['toast_message'] ?? '',
    ],
]);
