<?php
/**
 * Cross-provider token ID resolution and lookup indexes.
 */

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
        // Symbol-only fallback for CoinLore
        if ($coinloreId === null && $symbol !== '') {
            foreach (($idx['coinlore_sym_name'] ?? []) as $symKey => $clId) {
                if (str_starts_with($symKey, $symbol . '|')) {
                    $coinloreId = (int) $clId;
                    break;
                }
            }
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
        // Symbol-only fallback for CoinGecko
        if ($coingeckoId === null && $symbol !== '') {
            foreach (($idx['gecko_sym_name'] ?? []) as $symKey => $gId) {
                if (str_starts_with($symKey, $symbol . '|')) {
                    $coingeckoId = (string) $gId;
                    break;
                }
            }
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

    // 1. Exact slug match
    if ($slug !== '' && isset($idx['cmc_slug'][$slug])) {
        return (int) $idx['cmc_slug'][$slug];
    }

    // 2. Exact symbol+name match
    $key = ($symbol !== '' && $name !== '') ? ($symbol . '|' . $name) : '';
    if ($key !== '' && isset($idx['cmc_sym_name'][$key])) {
        return (int) $idx['cmc_sym_name'][$key];
    }

    // 3. Symbol-only match: scan CMC map for matching symbol (pick highest rank / lowest ID)
    if ($symbol !== '') {
        $candidates = [];
        foreach (($idx['cmc_by_id'] ?? []) as $id => $meta) {
            if (strtolower((string) ($meta['symbol'] ?? '')) === $symbol) {
                $candidates[] = (int) $id;
            }
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        // Multiple matches: prefer the one with the lowest ID (usually the most established)
        if (!empty($candidates)) {
            sort($candidates);
            return $candidates[0];
        }
    }

    // 4. Slug variations: try common transformations
    if ($slug !== '') {
        // Try stripping common suffixes/prefixes
        $slugParts = explode('-', $slug);
        // Try first part of slug (e.g., "binance-coin" → "binance")
        // Try removing '-coin', '-token', '-network' suffixes
        $variations = [];
        foreach (['-coin', '-token', '-network', '-protocol'] as $suffix) {
            if (str_ends_with($slug, $suffix)) {
                $variations[] = substr($slug, 0, -strlen($suffix));
            }
        }
        // Also try the symbol as slug
        $variations[] = $symbol;

        foreach ($variations as $altSlug) {
            if ($altSlug !== '' && $altSlug !== $slug && isset($idx['cmc_slug'][$altSlug])) {
                return (int) $idx['cmc_slug'][$altSlug];
            }
        }
    }

    return null;
}

/**
 * Try to resolve the real CMC ID for a stored cmc_id that isn't in the CMC map.
 * Looks up token metadata in the DB and uses the CMC map to find a match.
 */
function _resolveRealCmcIdForStored(int $storedId): ?int
{
    // Find this token in user_tokens by cmc_id to get symbol/name/slug
    if (!function_exists('dbGetTokenSourceMappingsByCmcIds')) return null;

    // Use a lightweight DB query to get token metadata
    static $tokenMeta = null;
    if ($tokenMeta === null) {
        $tokenMeta = [];
        if (function_exists('dbGetUserTokens')) {
            // We need all tokens; use a direct approach depending on DB backend
            if (function_exists('dbPdo')) {
                try {
                    $stmt = dbPdo()->query('SELECT cmc_id, symbol, name, slug FROM user_tokens');
                    foreach ($stmt->fetchAll() as $row) {
                        $tokenMeta[(int) $row['cmc_id']] = $row;
                    }
                } catch (\Throwable $e) {
                    // Fallback: empty
                }
            } elseif (function_exists('readTable')) {
                foreach (readTable('user_tokens') as $t) {
                    $tokenMeta[(int) $t['cmc_id']] = $t;
                }
            }
        }
    }

    $meta = $tokenMeta[$storedId] ?? null;
    if (!$meta) return null;

    $symbol = strtolower(trim((string) ($meta['symbol'] ?? '')));
    $name = strtolower(trim((string) ($meta['name'] ?? '')));
    $slug = strtolower(trim((string) ($meta['slug'] ?? '')));

    return resolveCmcIdByMeta($symbol, $name, $slug);
}

/**
 * Resolve CoinGecko ID for a stored cmc_id directly from token metadata.
 */
function _resolveGeckoIdForStored(int $storedId): ?string
{
    // Reuse the static tokenMeta cache from _resolveRealCmcIdForStored
    _resolveRealCmcIdForStored($storedId); // Ensures cache is populated

    $idx = tokenLookupIndexes();

    // Build tokenMeta again (or access it)
    static $localMeta = null;
    if ($localMeta === null) {
        $localMeta = [];
        if (function_exists('dbPdo')) {
            try {
                $stmt = dbPdo()->query('SELECT cmc_id, symbol, name, slug FROM user_tokens');
                foreach ($stmt->fetchAll() as $row) {
                    $localMeta[(int) $row['cmc_id']] = $row;
                }
            } catch (\Throwable $e) {}
        } elseif (function_exists('readTable')) {
            foreach (readTable('user_tokens') as $t) {
                $localMeta[(int) $t['cmc_id']] = $t;
            }
        }
    }

    $meta = $localMeta[$storedId] ?? null;
    if (!$meta) return null;

    $symbol = strtolower(trim((string) ($meta['symbol'] ?? '')));
    $name = strtolower(trim((string) ($meta['name'] ?? '')));
    $slug = strtolower(trim((string) ($meta['slug'] ?? '')));

    $key = ($symbol !== '' && $name !== '') ? ($symbol . '|' . $name) : '';

    // Try slug match, then sym+name, then symbol-only
    if ($slug !== '' && isset($idx['gecko_slug'][$slug])) {
        return (string) $idx['gecko_slug'][$slug];
    }
    if ($key !== '' && isset($idx['gecko_sym_name'][$key])) {
        return (string) $idx['gecko_sym_name'][$key];
    }
    if ($symbol !== '') {
        foreach (($idx['gecko_sym_name'] ?? []) as $symKey => $gId) {
            if (str_starts_with($symKey, $symbol . '|')) {
                return (string) $gId;
            }
        }
    }
    return null;
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
