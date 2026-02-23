<?php
/**
 * CoinLore provider: cache, search, quotes.
 */

function coinloreCacheFile(): string
{
    $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : dirname(__DIR__, 2);
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

function coinloreGetQuotes(array $cmcIds): array
{
    if (empty($cmcIds)) return [];

    $cmcIds = array_values(array_unique(array_map('intval', $cmcIds)));
    $cmcIds = array_values(array_filter($cmcIds, fn($id) => $id > 0));
    if (empty($cmcIds)) return [];

    $mappings = dbSourceMappingsByCmcIds($cmcIds);

    $coinloreToCmc = [];
    foreach ($cmcIds as $cmcId) {
        $coinloreId = $mappings[$cmcId]['coinlore_id'] ?? null;
        if ($coinloreId === null || (int) $coinloreId <= 0) {
            $coinloreId = $cmcId;
        }
        $coinloreToCmc[(int) $coinloreId] = $cmcId;
    }

    $providerIds = array_keys($coinloreToCmc);
    $raw = httpGet("https://api.coinlore.net/api/ticker/?id=" . implode(',', $providerIds));
    if (!$raw) return [];

    $coins = json_decode($raw, true);
    if (!is_array($coins)) return [];

    $out = [];
    foreach ($coins as $c) {
        $coinloreId = (int) ($c['id'] ?? 0);
        $cmcId = $coinloreToCmc[$coinloreId] ?? null;
        if (!$cmcId) continue;

        $out[(int) $cmcId] = [
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
