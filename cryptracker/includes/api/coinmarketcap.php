<?php
/**
 * CoinMarketCap provider: map cache, search, quotes.
 */

function cmcMapCacheFile(): string
{
    $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : dirname(__DIR__, 2);
    return $base . '/database/cmc_map_cache.json';
}

function cmcGetAllMap(): array
{
    static $mem = null;
    if ($mem !== null) return $mem;

    if (CMC_API_KEY === '') {
        $mem = [];
        return $mem;
    }

    $cacheFile = cmcMapCacheFile();
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 43200) {
        $cached = json_decode(file_get_contents($cacheFile), true) ?: [];
        if (isValidCoinList($cached)) {
            $mem = $cached;
            return $mem;
        }
        @unlink($cacheFile);
    }

    $data = cmcRequest('/v1/cryptocurrency/map', ['sort' => 'cmc_rank', 'limit' => 5000]);
    if (!isValidCoinList($data)) {
        $mem = [];
        return $mem;
    }

    file_put_contents($cacheFile, json_encode($data), LOCK_EX);
    $mem = $data;
    return $mem;
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
    $data = cmcGetAllMap();
    if (!$data) return [];

    $query  = strtolower(trim($query));
    $result = [];
    foreach ($data as $coin) {
        if (str_contains(strtolower($coin['name'] ?? ''), $query) ||
            str_contains(strtolower($coin['symbol'] ?? ''), $query)) {
            $cmcId = (int) ($coin['id'] ?? 0);
            if ($cmcId <= 0) continue;
            $resolved = resolveProviderIdsForToken(
                $cmcId,
                (string) ($coin['symbol'] ?? ''),
                (string) ($coin['name'] ?? ''),
                (string) ($coin['slug'] ?? '')
            );

            $result[] = [
                'id'     => $cmcId,
                'name'   => (string) ($coin['name'] ?? ''),
                'symbol' => strtoupper((string) ($coin['symbol'] ?? '')),
                'slug'   => (string) ($coin['slug'] ?? ''),
                'coinlore_id' => $resolved['coinlore_id'] ?? null,
                'coingecko_id' => $resolved['coingecko_id'] ?? null,
            ];
        }
        if (count($result) >= 20) break;
    }
    return $result;
}

function cmcGetQuotes(array $cmcIds): array
{
    if (empty($cmcIds)) return [];

    // Validate IDs against CMC map — resolve any that aren't real CMC IDs
    $cmcMap = cmcGetAllMap();
    $cmcMapById = [];
    foreach ($cmcMap as $coin) {
        $id = (int) ($coin['id'] ?? 0);
        if ($id > 0) $cmcMapById[$id] = true;
    }

    $validIds = [];
    $remappedToOriginal = []; // realCmcId => storedId

    foreach ($cmcIds as $storedId) {
        if (isset($cmcMapById[$storedId])) {
            $validIds[] = $storedId;
        } else {
            // This stored ID is not a real CMC ID — try to resolve the real one
            // via token metadata (symbol/name/slug) in the DB
            $realCmcId = _resolveRealCmcIdForStored($storedId);
            if ($realCmcId !== null && $realCmcId !== $storedId) {
                $validIds[] = $realCmcId;
                $remappedToOriginal[$realCmcId] = $storedId;
            }
            // If we can't resolve, skip — other providers will handle it
        }
    }

    if (empty($validIds)) return [];

    $data = cmcRequest('/v2/cryptocurrency/quotes/latest', [
        'id' => implode(',', $validIds), 'convert' => 'USD',
    ]);
    if (!$data) return [];

    $out = [];
    foreach ($data as $id => $coin) {
        $q = $coin['quote']['USD'] ?? [];
        $price = (float) ($q['price'] ?? 0);
        // Skip entries with zero price (inactive/delisted tokens)
        if ($price <= 0) continue;

        // Map back to the original stored ID if remapped
        $outId = isset($remappedToOriginal[(int) $id]) ? $remappedToOriginal[(int) $id] : (int) $id;

        $out[$outId] = [
            'price'              => $price,
            'percent_change_1h'  => (float) ($q['percent_change_1h'] ?? 0),
            'percent_change_24h' => (float) ($q['percent_change_24h'] ?? 0),
            'percent_change_7d'  => (float) ($q['percent_change_7d'] ?? 0),
            'market_cap'         => (float) ($q['market_cap'] ?? 0),
            'volume_24h'         => (float) ($q['volume_24h'] ?? 0),
            'volume_24h_native'  => 0.0,
            'csupply'            => (float) ($coin['circulating_supply'] ?? 0),
            'tsupply'            => (float) ($coin['total_supply'] ?? 0),
            'msupply'            => (float) ($coin['max_supply'] ?? 0),
            'rank'               => (int) ($coin['cmc_rank'] ?? 0),
        ];
    }
    return $out;
}
