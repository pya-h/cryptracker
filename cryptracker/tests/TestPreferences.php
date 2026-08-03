<?php
/**
 * Preferences & session helper tests.
 */

function test_pref_pl_mode_default(): void
{
    $_SESSION = [];
    assert_equals('fifo', plMode(), 'Default plMode should be fifo');
    $_SESSION = [];
}

function test_pref_pl_mode_custom(): void
{
    $_SESSION = ['pl_mode' => 'avg'];
    assert_equals('avg', plMode(), 'plMode should return avg when set');
    $_SESSION = ['pl_mode' => 'fifo'];
    assert_equals('fifo', plMode(), 'plMode should return fifo when set');
    $_SESSION = [];
}

function test_pref_precision_default(): void
{
    $_SESSION = [];
    assert_equals(3, precision(), 'Default precision should be 3');
    $_SESSION = [];
}

function test_pref_precision_custom(): void
{
    $_SESSION = ['precision' => 5];
    assert_equals(5, precision(), 'Precision should return 5 when set');
    $_SESSION = ['precision' => 10];
    assert_equals(10, precision(), 'Precision should return 10 when set');
    $_SESSION = [];
}

function test_pref_worthless_zeros_default(): void
{
    $_SESSION = [];
    assert_false(worthlessZeros(), 'Default worthlessZeros should be false');
    $_SESSION = [];
}

function test_pref_worthless_zeros_custom(): void
{
    $_SESSION = ['worthless_zeros' => true];
    assert_true(worthlessZeros(), 'worthlessZeros should be true when set');
    $_SESSION = ['worthless_zeros' => false];
    assert_false(worthlessZeros(), 'worthlessZeros should be false when set');
    $_SESSION = [];
}

function test_pref_trim_zeros_active(): void
{
    $_SESSION = ['worthless_zeros' => false, 'precision' => 3];
    // trimZeros should strip trailing zeros when worthlessZeros is off
    assert_equals('$1,000', trimZeros('$1,000.000'), 'Should trim trailing zeros');
    assert_equals('$99.5', trimZeros('$99.500'), 'Should trim to last significant digit');
    assert_equals('$100', trimZeros('$100.00'), 'Should trim all zeros after decimal');
    assert_equals('$3.14', trimZeros('$3.14'), 'No trailing zeros to trim');
    $_SESSION = [];
}

function test_pref_trim_zeros_disabled(): void
{
    $_SESSION = ['worthless_zeros' => true, 'precision' => 3];
    // trimZeros should pass through when worthlessZeros is on
    assert_equals('$1,000.000', trimZeros('$1,000.000'), 'Should preserve trailing zeros');
    assert_equals('$99.500', trimZeros('$99.500'), 'Should preserve trailing zeros');
    assert_equals('$100.00', trimZeros('$100.00'), 'Should preserve trailing zeros');
    $_SESSION = [];
}

function test_pref_theme_default(): void
{
    $_SESSION = [];
    assert_equals('light', theme(), 'Default theme should be light');
    $_SESSION = [];
}

function test_pref_theme_custom(): void
{
    $_SESSION = ['theme' => 'dark'];
    assert_equals('dark', theme(), 'Theme should return dark when set');
    $_SESSION = ['theme' => 'light'];
    assert_equals('light', theme(), 'Theme should return light when set');
    $_SESSION = [];
}

function test_pref_price_source_default(): void
{
    $_SESSION = [];
    assert_equals('coinmarketcap', priceSource(), 'Default priceSource should be coinmarketcap');
    $_SESSION = [];
}

function test_pref_price_source_custom(): void
{
    $_SESSION = ['price_source' => 'coinlore'];
    assert_equals('coinlore', priceSource(), 'priceSource should return coinlore');
    $_SESSION = ['price_source' => 'coingecko'];
    assert_equals('coingecko', priceSource(), 'priceSource should return coingecko');
    $_SESSION = [];
}

function test_pref_price_source_invalid_fallback(): void
{
    $_SESSION = ['price_source' => 'invalid_source'];
    assert_equals('coinmarketcap', priceSource(), 'Invalid source should fallback to coinmarketcap');
    $_SESSION = [];
}

function test_pref_price_source_label(): void
{
    assert_equals('CoinMarketCap', priceSourceLabel('coinmarketcap'), 'CMC label');
    assert_equals('CoinLore', priceSourceLabel('coinlore'), 'CoinLore label');
    assert_equals('CoinGecko', priceSourceLabel('coingecko'), 'CoinGecko label');
    assert_equals('CoinMarketCap', priceSourceLabel('unknown'), 'Unknown should default to CMC');
}
