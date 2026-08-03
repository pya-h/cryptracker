<?php
/**
 * Common API utilities: source management, HTTP client, validation.
 */

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

function httpGet(string $url, array $extraHeaders = [], int $timeout = 15): ?string
{
    $hasUA = false;
    foreach ($extraHeaders as $h) {
        if (stripos($h, 'User-Agent:') === 0) { $hasUA = true; break; }
    }
    if (!$hasUA) {
        $extraHeaders[] = 'User-Agent: CrypTracker/1.0 (PHP ' . PHP_VERSION . ')';
    }

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
    if ($res === false) return null;

    // Detect HTTP error status from response headers
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $hdr) {
            if (preg_match('/^HTTP\/[\d.]+ (\d{3})/', $hdr, $m)) {
                $code = (int) $m[1];
                if ($code >= 400) return null;
            }
        }
    }

    return $res;
}

function isValidCoinList(mixed $data): bool
{
    if (!is_array($data) || empty($data)) return false;
    // Must be an indexed array of coin objects, not an error response
    if (isset($data['status']) || isset($data['error'])) return false;
    $first = reset($data);
    return is_array($first) && (isset($first['id']) || isset($first['symbol']) || isset($first['name']));
}

function dbSourceMappingsByCmcIds(array $cmcIds): array
{
    if (function_exists('dbGetTokenSourceMappingsByCmcIds')) {
        return dbGetTokenSourceMappingsByCmcIds($cmcIds);
    }
    return [];
}
