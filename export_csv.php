<?php
/**
 * Export to CSV.
 * GET ?page=dashboard  — exports "Your Tokens" table
 * GET ?page=token&id=N — exports that token's Transaction History
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/helpers.php';

$user = requireAuth();

function csvSafeCell($value): string
{
    $str = (string) $value;
    if ($str === '') return $str;

    $first = $str[0];
    if (in_array($first, ['=', '+', '-', '@'], true)) {
        return "'" . $str;
    }
    return $str;
}

$page = $_GET['page'] ?? '';

if ($page === 'dashboard') {

    $tokens  = dbGetUserTokens($user['id']);
    $cmcIds  = array_column($tokens, 'cmc_id');
    $quotes  = $cmcIds ? apiGetQuotes($cmcIds) : [];
    $mode    = plMode();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="portfolio_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Token', 'Symbol', 'Price', '24h Change %', 'Holdings', 'Avg Buy', 'Current Value', 'Realized P/L', 'Unrealized P/L', 'Total P/L']);

    foreach ($tokens as $tk) {
        $price    = $quotes[$tk['cmc_id']]['price'] ?? 0;
        $change24 = $quotes[$tk['cmc_id']]['percent_change_24h'] ?? 0;
        $pl       = calcTokenPL($tk['id'], $price, $mode);

        fputcsv($out, [
            csvSafeCell($tk['name']),
            csvSafeCell($tk['symbol']),
            round($price, 6),
            round($change24, 2),
            round($pl['holdings'], 8),
            round($pl['avg_buy'], 6),
            round($pl['current_value'], 2),
            round($pl['realized_pl'], 2),
            round($pl['unrealized_pl'], 2),
            round($pl['total_pl'], 2),
        ]);
    }

    fclose($out);
    exit;
}

if ($page === 'token') {
    $tokenId = (int) ($_GET['id'] ?? 0);
    $token   = dbGetUserToken($tokenId, $user['id']);
    if (!$token) { header('Location: index.php'); exit; }

    $allTx = dbGetTransactionsDesc($tokenId);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $token['symbol'] . '_transactions_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Type', 'Amount', 'Price/Unit', 'Total', 'Realized P/L']);

    foreach ($allTx as $tx) {
        fputcsv($out, [
            $tx['created_at'],
            csvSafeCell(ucfirst($tx['type'])),
            round($tx['amount'], 8),
            round($tx['price_per_unit'], 6),
            round($tx['total_value'], 2),
            $tx['type'] === 'sell' ? round($tx['realized_pl'], 2) : '',
        ]);
    }

    fclose($out);
    exit;
}

header('Location: index.php');
exit;
