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
        // Atomic write so a concurrent reader can't decode a partial file.
        $tmp = $cacheFile . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, json_encode($all)) !== false) {
            @rename($tmp, $cacheFile);
        }
    }

    $mem = $all;
    return $all;
}

function coinloreSearch(string $query): array
{
    $query = strtolower(trim($query));
    if ($query === '') return [];

    // coinloreGetAll() paginates through 50+ HTTP requests when cache is cold.
    // Skip local-search and let other providers (with dedicated search APIs) handle it.
    $cf = coinloreCacheFile();
    if (!file_exists($cf) || (time() - filemtime($cf)) >= 3600) {
        return [];
    }

    $coins = coinloreGetAll();

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
        // CMC and CoinLore ids are unrelated spaces; without a real mapping we
        // must skip (like the other providers) rather than fetch a wrong coin.
        $coinloreId = (int) ($mappings[$cmcId]['coinlore_id'] ?? 0);
        if ($coinloreId <= 0) continue;
        $coinloreToCmc[$coinloreId] = $cmcId;
    }
    if (empty($coinloreToCmc)) return [];

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
