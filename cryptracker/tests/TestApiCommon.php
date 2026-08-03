<?php
/**
 * API common utility tests — source management, validation.
 * These tests only exercise pure functions, no network calls.
 */

/* ── Price source list & normalization ────────────────────── */

function test_api_price_sources_list(): void
{
    $list = priceSourcesList();
    assert_equals(3, count($list), 'Should have 3 price sources');
    assert_true(in_array('coinmarketcap', $list, true), 'Should include coinmarketcap');
    assert_true(in_array('coinlore', $list, true), 'Should include coinlore');
    assert_true(in_array('coingecko', $list, true), 'Should include coingecko');
}

function test_api_normalize_price_source(): void
{
    assert_equals('coinmarketcap', normalizePriceSource('coinmarketcap'), 'Exact match');
    assert_equals('coinlore', normalizePriceSource('CoinLore'), 'Case insensitive');
    assert_equals('coingecko', normalizePriceSource('  coingecko  '), 'Trimmed');
    assert_equals('coinmarketcap', normalizePriceSource('invalid'), 'Invalid falls back to default');
    assert_equals('coinmarketcap', normalizePriceSource(''), 'Empty falls back to default');
}

function test_api_selected_price_source(): void
{
    $_SESSION = [];
    assert_equals('coinmarketcap', selectedPriceSource(), 'Default is coinmarketcap');

    $_SESSION['price_source'] = 'coingecko';
    assert_equals('coingecko', selectedPriceSource(), 'Reads from session');

    $_SESSION['price_source'] = 'invalid';
    assert_equals('coinmarketcap', selectedPriceSource(), 'Invalid normalizes to default');

    $_SESSION = [];
}

function test_api_set_selected_price_source(): void
{
    $_SESSION = [];

    setSelectedPriceSource('coinlore');
    assert_equals('coinlore', $_SESSION['price_source'], 'Should set session value');

    setSelectedPriceSource('COINGECKO');
    assert_equals('coingecko', $_SESSION['price_source'], 'Should normalize before storing');

    setSelectedPriceSource('invalid');
    assert_equals('coinmarketcap', $_SESSION['price_source'], 'Invalid normalizes to default');

    $_SESSION = [];
}

function test_api_next_price_source(): void
{
    // coinmarketcap → coinlore → coingecko → coinmarketcap (wraps)
    assert_equals('coinlore', nextPriceSource('coinmarketcap'), 'CMC next is CoinLore');
    assert_equals('coingecko', nextPriceSource('coinlore'), 'CoinLore next is CoinGecko');
    assert_equals('coinmarketcap', nextPriceSource('coingecko'), 'CoinGecko next wraps to CMC');
    assert_equals('coinlore', nextPriceSource('invalid'), 'Invalid normalizes to CMC, next is CoinLore');
}

function test_api_source_priority(): void
{
    $p = sourcePriority('coinlore');
    assert_equals('coinlore', $p[0], 'Preferred source should be first');
    assert_equals(3, count($p), 'Should have 3 sources in priority');
    // All three should be present
    assert_true(in_array('coinmarketcap', $p, true), 'CMC in priority');
    assert_true(in_array('coingecko', $p, true), 'CoinGecko in priority');

    $p2 = sourcePriority('coingecko');
    assert_equals('coingecko', $p2[0], 'CoinGecko first when preferred');
}

function test_api_source_display_name(): void
{
    assert_equals('CoinMarketCap', sourceDisplayName('coinmarketcap'), 'CMC display name');
    assert_equals('CoinLore', sourceDisplayName('coinlore'), 'CoinLore display name');
    assert_equals('CoinGecko', sourceDisplayName('coingecko'), 'CoinGecko display name');
    assert_equals('CoinMarketCap', sourceDisplayName('invalid'), 'Invalid normalizes to CMC name');
}

/* ── isValidCoinList ─────────────────────────────────────── */

function test_api_is_valid_coin_list(): void
{
    assert_false(isValidCoinList([]), 'Empty array is invalid');
    assert_false(isValidCoinList(null), 'Null is invalid');
    assert_false(isValidCoinList('string'), 'String is invalid');

    // Error response formats should be rejected
    assert_false(isValidCoinList(['status' => 'error']), 'Status key means error response');
    assert_false(isValidCoinList(['error' => 'bad request']), 'Error key means error response');

    // Valid coin list
    $valid = [
        ['id' => 1, 'symbol' => 'BTC', 'name' => 'Bitcoin'],
        ['id' => 2, 'symbol' => 'ETH', 'name' => 'Ethereum'],
    ];
    assert_true(isValidCoinList($valid), 'Array of coin objects is valid');

    // Minimal valid (only symbol key)
    $minimal = [['symbol' => 'BTC']];
    assert_true(isValidCoinList($minimal), 'Minimal coin object with symbol is valid');

    // Minimal valid (only name key)
    $nameOnly = [['name' => 'Bitcoin']];
    assert_true(isValidCoinList($nameOnly), 'Minimal coin object with name is valid');

    // Invalid: nested structure but first element has no id/symbol/name
    $noKeys = [['rank' => 1, 'slug' => 'bitcoin']];
    assert_false(isValidCoinList($noKeys), 'Missing id/symbol/name keys is invalid');
}

/* ── Constants ───────────────────────────────────────────── */

function test_api_constants(): void
{
    assert_equals('coinmarketcap', PRICE_SOURCE_DEFAULT, 'Default source constant');
    assert_equals(90, PRICE_SOURCE_RAPID_WINDOW, 'Rapid window constant');
}

/* ── recordPreferredSourceAttempt ─────────────────────────── */

function test_api_record_preferred_source_success(): void
{
    $_SESSION = [];

    $result = recordPreferredSourceAttempt('coinmarketcap', true);
    assert_false($result['auto_switched'], 'Success should not auto-switch');
    assert_equals('coinmarketcap', $result['new_source'], 'Source stays the same');
    assert_equals(0, $result['failure_count'], 'No failures on success');
    assert_equals('', $result['toast_message'], 'No toast on success');

    // Session state should be reset
    assert_equals(0, $_SESSION['price_source_fail']['count'], 'Failure count reset');

    $_SESSION = [];
}

function test_api_record_preferred_source_single_failure(): void
{
    $_SESSION = [];

    $result = recordPreferredSourceAttempt('coinmarketcap', false);
    assert_false($result['auto_switched'], 'Single failure should not auto-switch');
    assert_equals(1, $result['failure_count'], 'Failure count should be 1');
    assert_equals('', $result['toast_message'], 'No toast on single failure');

    $_SESSION = [];
}

function test_api_record_preferred_source_auto_switch(): void
{
    $_SESSION = [];

    // Simulate 3 rapid failures
    $result1 = recordPreferredSourceAttempt('coinmarketcap', false);
    assert_equals(1, $result1['failure_count'], 'First failure');

    $result2 = recordPreferredSourceAttempt('coinmarketcap', false);
    assert_equals(2, $result2['failure_count'], 'Second failure');

    $result3 = recordPreferredSourceAttempt('coinmarketcap', false);
    assert_true($result3['auto_switched'], 'Third failure should trigger auto-switch');
    assert_equals('coinlore', $result3['new_source'], 'Should switch to next source');
    assert_contains($result3['toast_message'], 'auto-switched', 'Toast should mention auto-switch');
    assert_contains($result3['toast_message'], 'CoinLore', 'Toast should mention new source');

    // Session should now have the new source
    assert_equals('coinlore', selectedPriceSource(), 'Session source updated');

    $_SESSION = [];
}

function test_api_record_preferred_source_reset_after_success(): void
{
    $_SESSION = [];

    // 2 failures then a success
    recordPreferredSourceAttempt('coinmarketcap', false);
    recordPreferredSourceAttempt('coinmarketcap', false);
    $result = recordPreferredSourceAttempt('coinmarketcap', true);

    assert_false($result['auto_switched'], 'Success resets, no auto-switch');
    assert_equals(0, $result['failure_count'], 'Failure count reset to 0');

    // One more failure should be count 1, not 3
    $r = recordPreferredSourceAttempt('coinmarketcap', false);
    assert_equals(1, $r['failure_count'], 'Count restarts after success');

    $_SESSION = [];
}

/* ── dbSourceMappingsByCmcIds fallback ────────────────────── */

function test_api_db_source_mappings_fallback(): void
{
    // When the function isn't available, should return empty
    $result = dbSourceMappingsByCmcIds([1, 2, 3]);
    // This either returns empty (if dbGetTokenSourceMappingsByCmcIds doesn't exist)
    // or returns real data. Just verify it returns an array.
    assert_true(is_array($result), 'Should always return an array');
}
