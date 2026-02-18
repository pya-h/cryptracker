<?php
/**
 * Export to JSON.
 * GET ?page=dashboard                — exports "Your Tokens" data
 * GET ?page=token&id=N&part=transactions — exports token transactions
 * GET ?page=token&id=N&part=analytics    — exports token analytics timeline
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/helpers.php';

$user = requireAuth();

function jsonDownload(string $filename, array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$page = $_GET['page'] ?? '';

if ($page === 'dashboard') {
    $tokens = dbGetUserTokens($user['id']);
    $cmcIds = array_column($tokens, 'cmc_id');
    $quotes = $cmcIds ? apiGetQuotes($cmcIds) : [];
    $mode = plMode();

    $rows = [];
    foreach ($tokens as $tk) {
        $price = (float) ($quotes[$tk['cmc_id']]['price'] ?? 0);
        $change24 = (float) ($quotes[$tk['cmc_id']]['percent_change_24h'] ?? 0);
        $pl = calcTokenPL((int) $tk['id'], $price, $mode);

        $rows[] = [
            'token_id' => (int) $tk['id'],
            'cmc_id' => (int) $tk['cmc_id'],
            'name' => (string) $tk['name'],
            'symbol' => (string) $tk['symbol'],
            'price' => round($price, 8),
            'change_24h_percent' => round($change24, 4),
            'holdings' => round((float) $pl['holdings'], 8),
            'avg_buy' => round((float) $pl['avg_buy'], 8),
            'current_value' => round((float) $pl['current_value'], 8),
            'realized_pl' => round((float) $pl['realized_pl'], 8),
            'unrealized_pl' => round((float) $pl['unrealized_pl'], 8),
            'total_pl' => round((float) $pl['total_pl'], 8),
        ];
    }

    jsonDownload('portfolio_' . date('Y-m-d') . '.json', [
        'exported_at' => date('c'),
        'user' => [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
        ],
        'pl_mode' => $mode,
        'rows' => $rows,
    ]);
}

if ($page === 'token') {
    $tokenId = (int) ($_GET['id'] ?? 0);
    $part = $_GET['part'] ?? 'transactions';

    $token = dbGetUserToken($tokenId, $user['id']);
    if (!$token) {
        header('Location: index.php');
        exit;
    }

    if ($part === 'transactions') {
        $allTx = dbGetTransactionsDesc($tokenId);

        jsonDownload($token['symbol'] . '_transactions_' . date('Y-m-d') . '.json', [
            'exported_at' => date('c'),
            'token' => [
                'id' => (int) $token['id'],
                'cmc_id' => (int) $token['cmc_id'],
                'name' => (string) $token['name'],
                'symbol' => (string) $token['symbol'],
            ],
            'rows' => array_map(function (array $tx): array {
                return [
                    'id' => (int) $tx['id'],
                    'created_at' => (string) $tx['created_at'],
                    'type' => (string) $tx['type'],
                    'amount' => round((float) $tx['amount'], 8),
                    'price_per_unit' => round((float) $tx['price_per_unit'], 8),
                    'total_value' => round((float) $tx['total_value'], 8),
                    'realized_pl' => round((float) $tx['realized_pl'], 8),
                ];
            }, $allTx),
        ]);
    }

    if ($part === 'analytics') {
        $quotes = apiGetQuotes([(int) $token['cmc_id']]);
        $price = (float) ($quotes[$token['cmc_id']]['price'] ?? 0);
        $mode = plMode();
        $pl = calcTokenPL($tokenId, $price, $mode);
        $timeline = $pl['timeline'];
        $lastCumRealized = !empty($timeline) ? (float) end($timeline)['cum_realized'] : 0.0;

        $rows = array_map(function (array $row): array {
            return [
                'date' => (string) $row['date'],
                'type' => (string) $row['type'],
                'amount' => round((float) $row['amount'], 8),
                'price_per_unit' => round((float) $row['ppu'], 8),
                'total' => round((float) $row['total'], 8),
                'holdings' => round((float) $row['holdings'], 8),
                'avg_cost' => round((float) $row['avg_cost'], 8),
                'realized_pl' => round((float) $row['realized'], 8),
                'cum_realized' => round((float) $row['cum_realized'], 8),
                'unrealized_pl' => round((float) $row['unrealized'], 8),
                'total_pl' => round((float) $row['total_pl'], 8),
            ];
        }, $timeline);

        $rows[] = [
            'date' => date('Y-m-d H:i:s'),
            'type' => 'now',
            'amount' => round((float) $pl['holdings'], 8),
            'price_per_unit' => round($price, 8),
            'total' => round((float) $pl['current_value'], 8),
            'holdings' => round((float) $pl['holdings'], 8),
            'avg_cost' => round((float) $pl['avg_buy'], 8),
            'realized_pl' => 0.0,
            'cum_realized' => round($lastCumRealized, 8),
            'unrealized_pl' => round((float) $pl['unrealized_pl'], 8),
            'total_pl' => round((float) $pl['total_pl'], 8),
        ];

        jsonDownload($token['symbol'] . '_analytics_' . date('Y-m-d') . '.json', [
            'exported_at' => date('c'),
            'pl_mode' => $mode,
            'token' => [
                'id' => (int) $token['id'],
                'cmc_id' => (int) $token['cmc_id'],
                'name' => (string) $token['name'],
                'symbol' => (string) $token['symbol'],
                'current_price' => round($price, 8),
            ],
            'rows' => $rows,
        ]);
    }
}

header('Location: index.php');
exit;
