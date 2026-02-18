<?php
/**
 * Crypto price API wrapper.
 * Primary: CoinLore (free, no key). Fallback: CoinMarketCap.
 */

require_once __DIR__ . '/config.php';

function httpGet(string $url, array $extraHeaders = [], int $timeout = 15): ?string
{
    $headers = "Accept: application/json\r\n";
    foreach ($extraHeaders as $h) $headers .= $h . "\r\n";

    $sslOpts = ['verify_peer' => true, 'verify_peer_name' => true];
    $caBundle = '/etc/ssl/certs/ca-certificates.crt';
    if (file_exists($caBundle)) {
        $sslOpts['cafile'] = $caBundle;
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => $headers,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => $sslOpts,
    ]);

    $res = @file_get_contents($url, false, $context);
    return ($res !== false) ? $res : null;
}

function coinloreCacheFile(): string
{
    $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : dirname(__DIR__);
    return $base . '/database/coinlore_cache.json';
}

function coinloreGetAll(): array
{
    static $mem = null;
    if ($mem !== null) return $mem;

    $cacheFile = coinloreCacheFile();
    $cacheDir  = dirname($cacheFile);
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $mem = json_decode(file_get_contents($cacheFile), true) ?: [];
        if (!empty($mem)) return $mem;
    }

    $all   = [];
    $start = 0;
    $limit = 100;

    for ($i = 0; $i < 50; $i++) {
        $raw = httpGet("https://api.coinlore.net/api/tickers/?start=$start&limit=$limit");
        if (!$raw) break;
        $json = json_decode($raw, true);
        $coins = $json['data'] ?? [];
        if (empty($coins)) break;
        foreach ($coins as $c) $all[] = $c;
        if (count($coins) < $limit) break;
        $start += $limit;
    }

    if (!empty($all)) {
        file_put_contents($cacheFile, json_encode($all), LOCK_EX);
    }

    $mem = $all;
    return $all;
}

function coinloreSearch(string $query): array
{
    $coins = coinloreGetAll();
    $query = strtolower(trim($query));
    if ($query === '') return [];

    $result = [];
    foreach ($coins as $c) {
        if (str_contains(strtolower($c['name'] ?? ''), $query) ||
            str_contains(strtolower($c['symbol'] ?? ''), $query)) {
            $result[] = [
                'id'     => (int)$c['id'],
                'name'   => $c['name'],
                'symbol' => $c['symbol'],
                'slug'   => $c['nameid'] ?? '',
            ];
        }
        if (count($result) >= 20) break;
    }
    return $result;
}

function coinloreGetQuotes(array $ids): array
{
    if (empty($ids)) return [];

    $ids = array_map('intval', $ids);
    $raw = httpGet("https://api.coinlore.net/api/ticker/?id=" . implode(',', $ids));
    if (!$raw) return [];

    $coins = json_decode($raw, true);
    if (!is_array($coins)) return [];

    $out = [];
    foreach ($coins as $c) {
        $out[(int)$c['id']] = [
            'price'              => (float)($c['price_usd'] ?? 0),
            'percent_change_1h'  => (float)($c['percent_change_1h'] ?? 0),
            'percent_change_24h' => (float)($c['percent_change_24h'] ?? 0),
            'percent_change_7d'  => (float)($c['percent_change_7d'] ?? 0),
            'market_cap'         => (float)($c['market_cap_usd'] ?? 0),
            'volume_24h'         => (float)($c['volume24'] ?? 0),
            'volume_24h_native'  => (float)($c['volume24a'] ?? 0),
            'csupply'            => (float)($c['csupply'] ?? 0),
            'tsupply'            => (float)($c['tsupply'] ?? 0),
            'msupply'            => (float)($c['msupply'] ?? 0),
            'rank'               => (int)($c['rank'] ?? 0),
        ];
    }
    return $out;
}

function cmcRequest(string $endpoint, array $params = []): ?array
{
    if (CMC_API_KEY === '') return null;

    $url = 'https://pro-api.coinmarketcap.com' . $endpoint;
    if ($params) $url .= '?' . http_build_query($params);

    $raw = httpGet($url, ['X-CMC_PRO_API_KEY: ' . CMC_API_KEY]);
    if (!$raw) return null;

    $data = json_decode($raw, true);
    return $data['data'] ?? null;
}

function cmcSearchCoins(string $query): array
{
    $data = cmcRequest('/v1/cryptocurrency/map', ['sort' => 'cmc_rank', 'limit' => 5000]);
    if (!$data) return [];

    $query  = strtolower(trim($query));
    $result = [];
    foreach ($data as $coin) {
        if (str_contains(strtolower($coin['name'] ?? ''), $query) ||
            str_contains(strtolower($coin['symbol'] ?? ''), $query)) {
            $result[] = [
                'id'     => $coin['id'],
                'name'   => $coin['name'],
                'symbol' => $coin['symbol'],
                'slug'   => $coin['slug'] ?? '',
            ];
        }
        if (count($result) >= 20) break;
    }
    return $result;
}

function cmcGetQuotes(array $cmcIds): array
{
    if (empty($cmcIds)) return [];
    $data = cmcRequest('/v2/cryptocurrency/quotes/latest', [
        'id' => implode(',', $cmcIds), 'convert' => 'USD',
    ]);
    if (!$data) return [];

    $out = [];
    foreach ($data as $id => $coin) {
        $q = $coin['quote']['USD'] ?? [];
        $out[(int)$id] = [
            'price'              => $q['price'] ?? 0,
            'percent_change_1h'  => $q['percent_change_1h'] ?? 0,
            'percent_change_24h' => $q['percent_change_24h'] ?? 0,
            'percent_change_7d'  => $q['percent_change_7d'] ?? 0,
            'market_cap'         => $q['market_cap'] ?? 0,
            'volume_24h'         => $q['volume_24h'] ?? 0,
            'volume_24h_native'  => 0,
            'csupply'            => $coin['circulating_supply'] ?? 0,
            'tsupply'            => $coin['total_supply'] ?? 0,
            'msupply'            => $coin['max_supply'] ?? 0,
            'rank'               => $coin['cmc_rank'] ?? 0,
        ];
    }
    return $out;
}

function apiSearchCoins(string $query): array
{
    $results = coinloreSearch($query);
    if (!empty($results)) return $results;
    return cmcSearchCoins($query);
}

function apiGetQuotes(array $ids): array
{
    $quotes = coinloreGetQuotes($ids);
    if (!empty($quotes)) return $quotes;
    return cmcGetQuotes($ids);
}

function apiGetPrice(int $id): float
{
    $q = apiGetQuotes([$id]);
    return $q[$id]['price'] ?? 0;
}
