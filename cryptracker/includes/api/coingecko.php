<?php
/**
 * CoinGecko provider: coin list cache, search, quotes.
 */

function geckoCoinListCacheFile(): string
{
    $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : dirname(__DIR__, 2);
    return $base . '/database/coingecko_list_cache.json';
}

function geckoGetCoinList(): array
{
    static $mem = null;
    if ($mem !== null) return $mem;

    $cacheFile = geckoCoinListCacheFile();
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $cached = json_decode(file_get_contents($cacheFile), true) ?: [];
        if (isValidCoinList($cached)) {
            $mem = $cached;
            return $mem;
        }
        // Bad cache — delete it so a fresh fetch is attempted
        @unlink($cacheFile);
    }

    $raw = httpGet('https://api.coingecko.com/api/v3/coins/list?include_platform=false', [], 20);
    if (!$raw) {
        $mem = [];
        return $mem;
    }

    $list = json_decode($raw, true);
    if (!isValidCoinList($list)) {
        $mem = [];
        return $mem;
    }

    file_put_contents($cacheFile, json_encode($list), LOCK_EX);
    $mem = $list;
    return $mem;
}

function geckoGetQuotes(array $cmcIds): array
{
    if (empty($cmcIds)) return [];

    $cmcIds = array_values(array_unique(array_map('intval', $cmcIds)));
    $cmcToGecko = geckoCmcMap();
    $mappings = dbSourceMappingsByCmcIds($cmcIds);

    $geckoToCmc = [];
    foreach ($cmcIds as $cmcId) {
        $gid = $mappings[$cmcId]['coingecko_id'] ?? ($cmcToGecko[$cmcId] ?? null);

        // If no mapping found, the stored cmc_id might not be a real CMC ID.
        // Try resolving from token metadata.
        if (!$gid) {
            $realCmcId = _resolveRealCmcIdForStored($cmcId);
            if ($realCmcId !== null && $realCmcId !== $cmcId) {
                $gid = $cmcToGecko[$realCmcId] ?? null;
            }
            // Also try resolving CoinGecko ID directly from token symbol/slug
            if (!$gid) {
                $gid = _resolveGeckoIdForStored($cmcId);
            }
        }

        if ($gid) $geckoToCmc[$gid] = $cmcId;
    }

    if (empty($geckoToCmc)) return [];

    $idsParam = implode(',', array_keys($geckoToCmc));
    $url = 'https://api.coingecko.com/api/v3/coins/markets?vs_currency=usd&ids=' . rawurlencode($idsParam)
        . '&sparkline=false&price_change_percentage=1h,24h,7d';

    $raw = httpGet($url, [], 20);
    if (!$raw) return [];

    $rows = json_decode($raw, true);
    if (!is_array($rows)) return [];

    $out = [];
    foreach ($rows as $row) {
        $gid = strtolower((string) ($row['id'] ?? ''));
        $cmcId = $geckoToCmc[$gid] ?? null;
        if (!$cmcId) continue;

        $out[(int) $cmcId] = [
            'price'              => (float) ($row['current_price'] ?? 0),
            'percent_change_1h'  => (float) ($row['price_change_percentage_1h_in_currency'] ?? 0),
            'percent_change_24h' => (float) ($row['price_change_percentage_24h'] ?? 0),
            'percent_change_7d'  => (float) ($row['price_change_percentage_7d_in_currency'] ?? 0),
            'market_cap'         => (float) ($row['market_cap'] ?? 0),
            'volume_24h'         => (float) ($row['total_volume'] ?? 0),
            'volume_24h_native'  => 0.0,
            'csupply'            => (float) ($row['circulating_supply'] ?? 0),
            'tsupply'            => (float) ($row['total_supply'] ?? 0),
            'msupply'            => (float) ($row['max_supply'] ?? 0),
            'rank'               => (int) ($row['market_cap_rank'] ?? 0),
        ];
    }

    return $out;
}

function geckoSearchCoins(string $query): array
{
    $query = trim($query);
    if ($query === '') return [];

    $raw = httpGet('https://api.coingecko.com/api/v3/search?query=' . rawurlencode($query), [], 15);
    if (!$raw) return [];

    $json = json_decode($raw, true);
    $coins = $json['coins'] ?? [];
    if (!is_array($coins) || empty($coins)) return [];

    $result = [];
    foreach ($coins as $coin) {
        $gid = strtolower((string) ($coin['id'] ?? ''));
        if ($gid === '') continue;
        $name = (string) ($coin['name'] ?? '');
        $symbol = strtoupper((string) ($coin['symbol'] ?? ''));
        $slug = $gid;

        $cmcId = resolveCmcIdByMeta($symbol, $name, $slug);
        if ($cmcId === null) continue;

        $resolved = resolveProviderIdsForToken((int) $cmcId, $symbol, $name, $slug, null, $gid);

        $result[] = [
            'id' => (int) $cmcId,
            'name' => $name,
            'symbol' => $symbol,
            'slug' => $slug,
            'coinlore_id' => $resolved['coinlore_id'] ?? null,
            'coingecko_id' => $gid,
        ];

        if (count($result) >= 20) break;
    }

    return $result;
}
