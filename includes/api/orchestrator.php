<?php
/**
 * High-level price API orchestration: multi-source fallback, auto-switch, search aggregation.
 */

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
