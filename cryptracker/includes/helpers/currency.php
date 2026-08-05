<?php
/**
 * Display-currency conversion (presentation layer only).
 *
 * The whole app stores and computes in USD. This module lets the user *view*
 * every monetary figure in another currency (Toman/IRT, EUR, CAD, …) without
 * touching stored data. It fetches free-market fiat rates — quoted in Iranian
 * Toman — from a cached public source and converts USD figures on the fly.
 *
 * Conversion is a single cross-rate:
 *     value_in(C) = value_in_USD * ( Toman_per_USD / Toman_per_C )
 * with Toman itself having a divisor of 1 (the feed's values are Toman), so
 * IRT collapses to `value_USD * Toman_per_USD`.
 *
 * The chosen currency is a per-device preference stored in cookies by the
 * client (see app.js); the server only reads it to render flash-free.
 */

const CURRENCY_DEFAULT           = 'usd';
const CURRENCY_SECONDARY_DEFAULT = 'irt';
const CURRENCY_RATES_URL         = 'https://raw.githubusercontent.com/HosseinOdd/Navasan-API/main/data/fiat.json';
const CURRENCY_RATES_TTL         = 3600; // seconds — the upstream feed refreshes every ~2-3h

/**
 * Supported display currencies.
 *   code => [label, symbol, pos(prefix|suffix), decimals(null=use precision), key]
 * `key` is the field name in the Navasan feed; null marks the Toman base.
 * Symbols are ASCII/standard currency signs only — no Farsi/Arabic glyphs.
 */
function currencyRegistry(): array
{
    return [
        'usd' => ['label' => 'US Dollar',        'symbol' => '$',   'pos' => 'prefix', 'decimals' => null, 'key' => 'usd'],
        'irt' => ['label' => 'Toman (IRT)',      'symbol' => 'IRT', 'pos' => 'suffix', 'decimals' => 0,    'key' => null],
        'eur' => ['label' => 'Euro',             'symbol' => '€',   'pos' => 'prefix', 'decimals' => null, 'key' => 'eur'],
        'cad' => ['label' => 'Canadian Dollar',  'symbol' => 'C$',  'pos' => 'prefix', 'decimals' => null, 'key' => 'cad'],
        'gbp' => ['label' => 'British Pound',     'symbol' => '£',   'pos' => 'prefix', 'decimals' => null, 'key' => 'gbp'],
        'aed' => ['label' => 'UAE Dirham',       'symbol' => 'AED', 'pos' => 'suffix', 'decimals' => null, 'key' => 'aed'],
        'try' => ['label' => 'Turkish Lira',     'symbol' => '₺',   'pos' => 'prefix', 'decimals' => null, 'key' => 'try'],
    ];
}

function currencyIsSupported(string $code): bool
{
    return isset(currencyRegistry()[strtolower(trim($code))]);
}

/**
 * Test seam: inject a fixed rate table (code => Toman value) so unit tests can
 * exercise conversion without hitting the network. Pass null to clear.
 */
function _currencyRatesOverride(?array $rates = null, bool $apply = false): ?array
{
    static $override = null;
    if ($apply) {
        $override = $rates;
        currencyRatesCacheReset();
    }
    return $override;
}

function currencyRatesCacheReset(): void
{
    currencyRates(true);
}

function currencyRatesCacheFile(): string
{
    $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : dirname(__DIR__, 2);
    return $base . '/database/navasan_rates.json';
}

/**
 * Minimal self-contained HTTPS GET (mirrors api/common.php's httpGet so this
 * module has no dependency on the API layer, which many pages don't load).
 */
function currencyHttpGet(string $url, int $timeout = 8): ?string
{
    if (function_exists('httpGet')) {
        return httpGet($url, [], $timeout);
    }

    $sslOpts  = ['verify_peer' => true, 'verify_peer_name' => true];
    $caBundle = '/etc/ssl/certs/ca-certificates.crt';
    if (file_exists($caBundle)) {
        $sslOpts['cafile'] = $caBundle;
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => "Accept: application/json\r\nUser-Agent: CrypTracker/1.0 (PHP " . PHP_VERSION . ")\r\n",
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => $sslOpts,
    ]);

    $res = @file_get_contents($url, false, $context);
    return $res === false ? null : $res;
}

/**
 * Fetch and normalise the upstream feed into  code => Toman value (float).
 * Returns null on any failure or if USD is missing/invalid.
 */
function currencyFetchRates(): ?array
{
    $raw = currencyHttpGet(CURRENCY_RATES_URL);
    if (!$raw) return null;

    $data = json_decode($raw, true);
    if (!is_array($data)) return null;

    $out = [];
    foreach ($data as $code => $entry) {
        if (is_array($entry) && isset($entry['value']) && is_numeric($entry['value'])) {
            $out[strtolower((string) $code)] = (float) $entry['value'];
        }
    }

    return (isset($out['usd']) && $out['usd'] > 0) ? $out : null;
}

/**
 * Rate table with hourly shared-file caching and last-good fallback.
 * Cached per-request (static). Pass $reset=true to clear that memo (tests).
 * Returns null when no rate is available at all.
 */
function currencyRates(bool $reset = false): ?array
{
    static $memo = null; // null = not resolved, false = resolved-but-none, array = rates

    if ($reset) { $memo = null; return null; }

    $override = _currencyRatesOverride();
    if ($override !== null) return $override;

    if ($memo !== null) return $memo ?: null;

    $file = currencyRatesCacheFile();
    $dir  = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    // Fresh cache — use as-is.
    if (file_exists($file) && (time() - filemtime($file)) < CURRENCY_RATES_TTL) {
        $mem = json_decode((string) @file_get_contents($file), true);
        if (is_array($mem) && isset($mem['usd'])) {
            $memo = $mem;
            return $memo;
        }
    }

    // Stale or missing — try a live refresh.
    $fresh = currencyFetchRates();
    if ($fresh !== null) {
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, json_encode($fresh), LOCK_EX) !== false) {
            @rename($tmp, $file); // atomic replace
        }
        $memo = $fresh;
        return $memo;
    }

    // Refresh failed — fall back to last-good cache even if stale.
    if (file_exists($file)) {
        $mem = json_decode((string) @file_get_contents($file), true);
        if (is_array($mem) && isset($mem['usd'])) {
            $memo = $mem;
            return $memo;
        }
    }

    $memo = false; // no rates available this request
    return null;
}

/**
 * USD → $code multiplier, or null if the currency is unknown / rate missing.
 */
function currencyFactorFor(string $code): ?float
{
    $code = strtolower(trim($code));
    $reg  = currencyRegistry();
    if (!isset($reg[$code])) return null;
    if ($code === 'usd')     return 1.0;

    $rates = currencyRates();
    if ($rates === null || !isset($rates['usd']) || $rates['usd'] <= 0) return null;

    $key = $reg[$code]['key'];
    if ($key === null) {
        // Toman base: value's own "Toman per unit" is 1.
        return (float) $rates['usd'];
    }

    if (!isset($rates[$key]) || $rates[$key] <= 0) return null;
    return (float) $rates['usd'] / (float) $rates[$key];
}

/**
 * The dynamic (non-USD) slot of the navbar switch. Defaults to IRT; never USD.
 */
function currencySecondaryCode(): string
{
    $code = strtolower((string) ($_COOKIE['secondary_currency'] ?? CURRENCY_SECONDARY_DEFAULT));
    if (!currencyIsSupported($code) || $code === 'usd') {
        return CURRENCY_SECONDARY_DEFAULT;
    }
    return $code;
}

/**
 * Currency currently being displayed. Falls back to USD when the requested
 * currency is unknown or its rate is unavailable.
 */
function currencyActiveCode(): string
{
    $code = strtolower((string) ($_COOKIE['display_currency'] ?? CURRENCY_DEFAULT));
    if (!currencyIsSupported($code)) return CURRENCY_DEFAULT;
    if ($code !== 'usd' && currencyFactorFor($code) === null) {
        return CURRENCY_DEFAULT; // rate feed down — degrade gracefully to USD
    }
    return $code;
}

/**
 * Resolved display descriptor used by both rendering and the JS bridge.
 */
function currencyDisplay(): array
{
    $code = currencyActiveCode();
    $meta = currencyRegistry()[$code];
    return [
        'code'     => $code,
        'label'    => $meta['label'],
        'symbol'   => $meta['symbol'],
        'pos'      => $meta['pos'],
        'decimals' => $meta['decimals'],
        'factor'   => currencyFactorFor($code) ?? 1.0,
    ];
}

/**
 * Wrap a *converted* value with the active currency's symbol/sign/decimals.
 * $pl toggles signed (+/-) profit-loss formatting.
 */
function currencyWrap(float $value, int $decimals, array $cur, bool $pl): string
{
    $num  = number_format(abs($value), $decimals);
    $core = ($cur['pos'] === 'suffix') ? ($num . ' ' . $cur['symbol']) : ($cur['symbol'] . $num);

    if ($pl) {
        return trimZeros(($value >= 0 ? '+' : '-') . $core);
    }
    return trimZeros(($value < 0 ? '-' : '') . $core);
}

/**
 * Format a USD amount in the active display currency (absolute money).
 * Mirrors formatUSD() but currency-aware.
 */
function formatMoney(float $usd, int $decimals = -1): string
{
    $cur = currencyDisplay();
    $dec = ($cur['decimals'] !== null) ? $cur['decimals'] : ($decimals < 0 ? precision() : $decimals);
    return currencyWrap($usd * $cur['factor'], $dec, $cur, false);
}

/**
 * Format a USD profit/loss amount in the active display currency (signed).
 * Mirrors formatPL() but currency-aware.
 */
function formatMoneyPL(float $usd, int $decimals = -1): string
{
    $cur = currencyDisplay();
    $dec = ($cur['decimals'] !== null) ? $cur['decimals'] : ($decimals < 0 ? precision() : $decimals);
    return currencyWrap($usd * $cur['factor'], $dec, $cur, true);
}

/**
 * The `data-cur-*` attributes app.js/graph.js read to convert live values.
 */
function currencyDataAttrs(): string
{
    $c   = currencyDisplay();
    $dec = $c['decimals'] === null ? '' : (string) $c['decimals'];
    // Plain-decimal factor (no scientific notation), trimmed.
    $factor = rtrim(rtrim(number_format($c['factor'], 8, '.', ''), '0'), '.');
    if ($factor === '') $factor = '1';

    return 'data-cur-code="' . e($c['code']) . '" '
         . 'data-cur-symbol="' . e($c['symbol']) . '" '
         . 'data-cur-pos="' . e($c['pos']) . '" '
         . 'data-cur-decimals="' . e($dec) . '" '
         . 'data-cur-factor="' . e($factor) . '"';
}
