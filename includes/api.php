<?php
/**
 * Crypto price API wrapper.
 * Default source: CoinMarketCap.
 * Supported sources: CoinMarketCap, CoinLore, CoinGecko.
 */

require_once __DIR__ . '/config.php';

const PRICE_SOURCE_DEFAULT = 'coinmarketcap';
const PRICE_SOURCE_RAPID_WINDOW = 90;

function priceSourcesList(): array
{
    return ['coinmarketcap', 'coinlore', 'coingecko'];
}

function normalizePriceSource(string $source): string
{
    $source = strtolower(trim($source));
    return in_array($source, priceSourcesList(), true) ? $source : PRICE_SOURCE_DEFAULT;
}

function selectedPriceSource(): string
{
    $current = $_SESSION['price_source'] ?? PRICE_SOURCE_DEFAULT;
    return normalizePriceSource((string) $current);
}

function setSelectedPriceSource(string $source): void
{
    $_SESSION['price_source'] = normalizePriceSource($source);
}

function nextPriceSource(string $source): string
{
    $list = priceSourcesList();
    $idx = array_search(normalizePriceSource($source), $list, true);
    if ($idx === false) return PRICE_SOURCE_DEFAULT;
    return $list[($idx + 1) % count($list)];
}

function sourcePriority(string $preferred): array
{
    $preferred = normalizePriceSource($preferred);
    $list = priceSourcesList();
    return array_values(array_unique(array_merge([$preferred], $list)));
}

function sourceDisplayName(string $source): string
{
    return match (normalizePriceSource($source)) {
        'coinmarketcap' => 'CoinMarketCap',
        'coinlore' => 'CoinLore',
        'coingecko' => 'CoinGecko',
        default => 'Unknown',
    };
}

function geckoCoinListCacheFile(): string
{
    $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : dirname(__DIR__);
    return $base . '/database/coingecko_list_cache.json';
}

function cmcMapCacheFile(): string
{
    $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : dirname(__DIR__);
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
        $mem = json_decode(file_get_contents($cacheFile), true) ?: [];
        if (!empty($mem)) return $mem;
    }

    $data = cmcRequest('/v1/cryptocurrency/map', ['sort' => 'cmc_rank', 'limit' => 5000]);
    if (!is_array($data) || empty($data)) {
        $mem = [];
        return $mem;
    }

    file_put_contents($cacheFile, json_encode($data), LOCK_EX);
    $mem = $data;
    return $mem;
}

function tokenLookupIndexes(): array
{
    static $idx = null;
    if ($idx !== null) return $idx;

    $coinlore = coinloreGetAll();
    $gecko = geckoGetCoinList();
    $cmc = cmcGetAllMap();

    $coinloreBySlug = [];
    $coinloreBySymName = [];
    foreach ($coinlore as $coin) {
        $slug = strtolower((string) ($coin['nameid'] ?? ''));
        $sym = strtolower((string) ($coin['symbol'] ?? ''));
        $name = strtolower((string) ($coin['name'] ?? ''));
        $id = (int) ($coin['id'] ?? 0);
        if ($slug !== '') $coinloreBySlug[$slug] = $id;
        if ($sym !== '' && $name !== '') $coinloreBySymName[$sym . '|' . $name] = $id;
    }

    $geckoBySlug = [];
    $geckoBySymName = [];
    foreach ($gecko as $coin) {
        $slug = strtolower((string) ($coin['id'] ?? ''));
        $sym = strtolower((string) ($coin['symbol'] ?? ''));
        $name = strtolower((string) ($coin['name'] ?? ''));
        if ($slug !== '') $geckoBySlug[$slug] = $slug;
        if ($sym !== '' && $name !== '') $geckoBySymName[$sym . '|' . $name] = $slug;
    }

    $cmcBySlug = [];
    $cmcBySymName = [];
    $cmcById = [];
    foreach ($cmc as $coin) {
        $id = (int) ($coin['id'] ?? 0);
        if ($id <= 0) continue;
        $slug = strtolower((string) ($coin['slug'] ?? ''));
        $sym = strtolower((string) ($coin['symbol'] ?? ''));
        $name = strtolower((string) ($coin['name'] ?? ''));
        if ($slug !== '') $cmcBySlug[$slug] = $id;
        if ($sym !== '' && $name !== '') $cmcBySymName[$sym . '|' . $name] = $id;
        $cmcById[$id] = ['slug' => $slug, 'symbol' => $sym, 'name' => $name];
    }

    $idx = [
        'coinlore_slug' => $coinloreBySlug,
        'coinlore_sym_name' => $coinloreBySymName,
        'gecko_slug' => $geckoBySlug,
        'gecko_sym_name' => $geckoBySymName,
        'cmc_slug' => $cmcBySlug,
        'cmc_sym_name' => $cmcBySymName,
        'cmc_by_id' => $cmcById,
    ];

    return $idx;
}

function resolveProviderIdsForToken(int $cmcId, string $symbol, string $name, string $slug, ?int $coinloreHint = null, ?string $coingeckoHint = null): array
{
    $idx = tokenLookupIndexes();

    $symbol = strtolower(trim($symbol));
    $name = strtolower(trim($name));
    $slug = strtolower(trim($slug));

    if (($slug === '' || $symbol === '' || $name === '') && isset($idx['cmc_by_id'][$cmcId])) {
        $meta = $idx['cmc_by_id'][$cmcId];
        $slug = $slug !== '' ? $slug : (string) ($meta['slug'] ?? '');
        $symbol = $symbol !== '' ? $symbol : (string) ($meta['symbol'] ?? '');
        $name = $name !== '' ? $name : (string) ($meta['name'] ?? '');
    }

    $key = ($symbol !== '' && $name !== '') ? ($symbol . '|' . $name) : '';

    $coinloreId = ($coinloreHint !== null && $coinloreHint > 0) ? $coinloreHint : null;
    if ($coinloreId === null) {
        if ($slug !== '' && isset($idx['coinlore_slug'][$slug])) {
            $coinloreId = (int) $idx['coinlore_slug'][$slug];
        } elseif ($key !== '' && isset($idx['coinlore_sym_name'][$key])) {
            $coinloreId = (int) $idx['coinlore_sym_name'][$key];
        }
    }

    $coingeckoId = $coingeckoHint !== null ? strtolower(trim($coingeckoHint)) : null;
    if ($coingeckoId === '') $coingeckoId = null;
    if ($coingeckoId === null) {
        if ($slug !== '' && isset($idx['gecko_slug'][$slug])) {
            $coingeckoId = (string) $idx['gecko_slug'][$slug];
        } elseif ($key !== '' && isset($idx['gecko_sym_name'][$key])) {
            $coingeckoId = (string) $idx['gecko_sym_name'][$key];
        }
    }

    return [
        'coinlore_id' => $coinloreId,
        'coingecko_id' => $coingeckoId,
    ];
}

function resolveCmcIdByMeta(string $symbol, string $name, string $slug): ?int
{
    $idx = tokenLookupIndexes();

    $symbol = strtolower(trim($symbol));
    $name = strtolower(trim($name));
    $slug = strtolower(trim($slug));

    if ($slug !== '' && isset($idx['cmc_slug'][$slug])) {
        return (int) $idx['cmc_slug'][$slug];
    }

    $key = ($symbol !== '' && $name !== '') ? ($symbol . '|' . $name) : '';
    if ($key !== '' && isset($idx['cmc_sym_name'][$key])) {
        return (int) $idx['cmc_sym_name'][$key];
    }

    return null;
}

function dbSourceMappingsByCmcIds(array $cmcIds): array
{
    if (function_exists('dbGetTokenSourceMappingsByCmcIds')) {
        return dbGetTokenSourceMappingsByCmcIds($cmcIds);
    }
    return [];
}

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

function geckoGetCoinList(): array
{
    static $mem = null;
    if ($mem !== null) return $mem;

    $cacheFile = geckoCoinListCacheFile();
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
        $mem = json_decode(file_get_contents($cacheFile), true) ?: [];
        if (!empty($mem)) return $mem;
    }

    $raw = httpGet('https://api.coingecko.com/api/v3/coins/list?include_platform=false', [], 20);
    if (!$raw) {
        $mem = [];
        return $mem;
    }

    $list = json_decode($raw, true);
    if (!is_array($list)) {
        $mem = [];
        return $mem;
    }

    file_put_contents($cacheFile, json_encode($list), LOCK_EX);
    $mem = $list;
    return $mem;
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

function geckoCmcMap(): array
{
    static $map = null;
    if ($map !== null) return $map;

    $idx = tokenLookupIndexes();
    $map = [];
    foreach (($idx['cmc_by_id'] ?? []) as $cmcId => $meta) {
        $resolved = resolveProviderIdsForToken((int) $cmcId, (string) ($meta['symbol'] ?? ''), (string) ($meta['name'] ?? ''), (string) ($meta['slug'] ?? ''), null, null);
        if (!empty($resolved['coingecko_id'])) {
            $map[(int) $cmcId] = (string) $resolved['coingecko_id'];
        }
    }

    return $map;
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

function providerGetQuotes(string $source, array $ids): array
{
    return match (normalizePriceSource($source)) {
        'coinmarketcap' => cmcGetQuotes($ids),
        'coinlore' => coinloreGetQuotes($ids),
        'coingecko' => geckoGetQuotes($ids),
        default => [],
    };
}

function recordPreferredSourceAttempt(string $preferredSource, bool $success): array
{
    $preferredSource = normalizePriceSource($preferredSource);

    if (!isset($_SESSION['price_source_fail'])) {
        $_SESSION['price_source_fail'] = ['source' => $preferredSource, 'count' => 0, 'last_ts' => 0];
    }

    $state = $_SESSION['price_source_fail'];
    $now = time();

    if ($success) {
        $_SESSION['price_source_fail'] = ['source' => $preferredSource, 'count' => 0, 'last_ts' => 0];
        return ['auto_switched' => false, 'new_source' => $preferredSource, 'failure_count' => 0, 'toast_message' => ''];
    }

    if (($state['source'] ?? '') !== $preferredSource || ($now - (int) ($state['last_ts'] ?? 0)) > PRICE_SOURCE_RAPID_WINDOW) {
        $count = 1;
    } else {
        $count = ((int) ($state['count'] ?? 0)) + 1;
    }

    $_SESSION['price_source_fail'] = ['source' => $preferredSource, 'count' => $count, 'last_ts' => $now];

    if ($count >= 3) {
        $next = nextPriceSource($preferredSource);
        setSelectedPriceSource($next);
        $_SESSION['price_source_fail'] = ['source' => $next, 'count' => 0, 'last_ts' => 0];

        return [
            'auto_switched' => true,
            'new_source' => $next,
            'failure_count' => 0,
            'toast_message' => 'Price source auto-switched to ' . sourceDisplayName($next) . ' after repeated failures from ' . sourceDisplayName($preferredSource) . '.',
        ];
    }

    return ['auto_switched' => false, 'new_source' => $preferredSource, 'failure_count' => $count, 'toast_message' => ''];
}

function apiGetQuotesDetailed(array $ids, bool $trackPreferredFailures = false): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, fn($id) => $id > 0));
    if (empty($ids)) {
        return ['quotes' => [], 'meta' => ['preferred_source_before' => selectedPriceSource(), 'preferred_source_after' => selectedPriceSource(), 'used_source' => '', 'auto_switched' => false, 'toast_message' => '']];
    }

    $preferredBefore = selectedPriceSource();
    $priority = sourcePriority($preferredBefore);

    $quotes = [];
    $usedSource = '';
    $preferredCount = 0;

    foreach ($priority as $source) {
        $res = providerGetQuotes($source, $ids);

        if ($source === $preferredBefore) {
            $preferredCount = count($res);
        }

        if (!empty($res) && $usedSource === '') {
            $usedSource = $source;
        }

        foreach ($res as $id => $q) {
            if (!isset($quotes[$id])) {
                $quotes[$id] = $q;
            }
        }

        if (count($quotes) >= count($ids)) {
            break;
        }
    }

    $preferredSuccess = $preferredCount >= count($ids);
    $switchMeta = ['auto_switched' => false, 'new_source' => $preferredBefore, 'failure_count' => 0, 'toast_message' => ''];

    if ($trackPreferredFailures) {
        $switchMeta = recordPreferredSourceAttempt($preferredBefore, $preferredSuccess);
    }

    $preferredAfter = selectedPriceSource();

    return [
        'quotes' => $quotes,
        'meta' => [
            'preferred_source_before' => $preferredBefore,
            'preferred_source_after' => $preferredAfter,
            'used_source' => $usedSource,
            'fallback_used' => $usedSource !== '' && $usedSource !== $preferredBefore,
            'auto_switched' => (bool) $switchMeta['auto_switched'],
            'auto_switched_to' => $switchMeta['new_source'],
            'failure_count' => (int) $switchMeta['failure_count'],
            'toast_message' => (string) $switchMeta['toast_message'],
        ],
    ];
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

function apiSearchCoins(string $query): array
{
    $priority = sourcePriority(selectedPriceSource());
    foreach ($priority as $source) {
        $results = match ($source) {
            'coinmarketcap' => cmcSearchCoins($query),
            'coinlore' => coinloreSearch($query),
            'coingecko' => geckoSearchCoins($query),
            default => [],
        };
        if (!empty($results)) {
            $out = [];
            foreach ($results as $row) {
                $name = (string) ($row['name'] ?? '');
                $symbol = strtoupper((string) ($row['symbol'] ?? ''));
                $slug = (string) ($row['slug'] ?? '');
                $cmcId = 0;

                if ($source === 'coinmarketcap') {
                    $cmcId = (int) ($row['id'] ?? 0);
                } elseif ($source === 'coinlore') {
                    $cmcId = resolveCmcIdByMeta($symbol, $name, $slug) ?? 0;
                    if ($cmcId <= 0) {
                        $cmcId = (int) ($row['id'] ?? 0);
                    }
                } else {
                    $cmcId = (int) ($row['id'] ?? 0);
                }

                if ($cmcId <= 0) continue;

                $coinloreHint = null;
                if ($source === 'coinlore') {
                    $coinloreHint = (int) ($row['id'] ?? 0);
                    if ($coinloreHint <= 0) $coinloreHint = null;
                } elseif (isset($row['coinlore_id'])) {
                    $coinloreHint = (int) $row['coinlore_id'];
                    if ($coinloreHint <= 0) $coinloreHint = null;
                }

                $coingeckoHint = null;
                if ($source === 'coingecko') {
                    $coingeckoHint = (string) ($row['slug'] ?? '');
                } elseif (isset($row['coingecko_id'])) {
                    $coingeckoHint = (string) $row['coingecko_id'];
                }

                $resolved = resolveProviderIdsForToken($cmcId, $symbol, $name, $slug, $coinloreHint, $coingeckoHint);

                $out[] = [
                    'id' => $cmcId,
                    'name' => $name,
                    'symbol' => $symbol,
                    'slug' => $slug,
                    'coinlore_id' => $resolved['coinlore_id'] ?? null,
                    'coingecko_id' => $resolved['coingecko_id'] ?? null,
                ];

                if (count($out) >= 20) break;
            }

            if (!empty($out)) return $out;
        }
    }
    return [];
}

function apiGetQuotes(array $ids): array
{
    return apiGetQuotesDetailed($ids, false)['quotes'];
}

function apiGetPrice(int $id): float
{
    $q = apiGetQuotes([$id]);
    return $q[$id]['price'] ?? 0;
}
