<?php
/**
 * Display-Currency Conversion Tests
 *
 * Exercises the USD→currency factor math and the formatMoney/formatMoneyPL
 * renderers without touching the network — rates are injected through the
 * currency module's test seam (_currencyRatesOverride).
 */

function _curReset(): void
{
    $_COOKIE = [];
    _currencyRatesOverride(null, true); // clear injected rates + cached memo
}

function _curSetRates(): void
{
    // Toman-denominated free-market values, as the upstream feed provides them.
    _currencyRatesOverride([
        'usd' => 188200.0,
        'eur' => 217130.0,
        'cad' => 137000.0,
    ], true);
}

function test_currency_factor_usd_is_one(): void
{
    _curReset();
    assert_equals(1.0, currencyFactorFor('usd'), 'USD factor is 1');
    _curReset();
}

function test_currency_factor_irt_is_toman_per_usd(): void
{
    _curSetRates();
    assert_equals(188200.0, currencyFactorFor('irt'), 'IRT factor equals Toman-per-USD');
    _curReset();
}

function test_currency_factor_eur_is_cross_rate(): void
{
    _curSetRates();
    $expected = 188200.0 / 217130.0;
    assert_true(abs(currencyFactorFor('eur') - $expected) < 1e-9, 'EUR factor is the USD/EUR cross-rate');
    _curReset();
}

function test_currency_factor_unknown_is_null(): void
{
    _curSetRates();
    assert_null(currencyFactorFor('xyz'), 'unknown currency has no factor');
    _curReset();
}

function test_currency_factor_missing_rate_is_null(): void
{
    _curSetRates(); // feed has no TRY entry
    assert_null(currencyFactorFor('try'), 'currency absent from the feed has no factor');
    _curReset();
}

function test_format_money_usd_default(): void
{
    _curReset(); // no cookie → USD
    $_SESSION['precision'] = 3;
    $_SESSION['worthless_zeros'] = false;
    assert_equals('$1,234.5', formatMoney(1234.5), 'USD: $ prefix with trailing zeros trimmed');
    _curReset();
}

function test_format_money_irt_suffix_zero_decimals(): void
{
    _curSetRates();
    $_COOKIE['display_currency'] = 'irt';
    // 100 USD * 188200 = 18,820,000 Toman, grouped, 0 decimals, IRT suffix.
    assert_equals('18,820,000 IRT', formatMoney(100.0), 'IRT is grouped, 0-decimal and suffixed');
    _curReset();
}

function test_format_money_pl_signs_irt(): void
{
    _curSetRates();
    $_COOKIE['display_currency'] = 'irt';
    assert_equals('+18,820,000 IRT', formatMoneyPL(100.0), 'positive P/L gets a + sign');
    assert_equals('-18,820,000 IRT', formatMoneyPL(-100.0), 'negative P/L gets a - sign');
    _curReset();
}

function test_format_money_eur_prefix(): void
{
    _curSetRates();
    $_COOKIE['display_currency'] = 'eur';
    $_SESSION['precision'] = 2;
    $_SESSION['worthless_zeros'] = true; // keep decimals for a stable assertion
    // 100 USD * (188200/217130) ≈ 86.68 EUR
    assert_equals('€86.68', formatMoney(100.0), 'EUR converts via cross-rate with € prefix');
    _curReset();
}

function test_active_code_falls_back_to_usd_when_rate_missing(): void
{
    _curSetRates(); // no GBP in the feed
    $_COOKIE['display_currency'] = 'gbp';
    assert_equals('usd', currencyActiveCode(), 'a currency with no rate degrades to USD');
    _curReset();
}

function test_active_code_respects_available_cookie(): void
{
    _curSetRates();
    $_COOKIE['display_currency'] = 'irt';
    assert_equals('irt', currencyActiveCode(), 'an available currency is honored');
    _curReset();
}

function test_active_code_rejects_unknown_cookie(): void
{
    _curSetRates();
    $_COOKIE['display_currency'] = 'zzz';
    assert_equals('usd', currencyActiveCode(), 'an unknown currency code falls back to USD');
    _curReset();
}

function test_secondary_code_default_custom_and_never_usd(): void
{
    _curReset();
    assert_equals('irt', currencySecondaryCode(), 'secondary slot defaults to IRT');

    $_COOKIE['secondary_currency'] = 'eur';
    assert_equals('eur', currencySecondaryCode(), 'secondary slot honors the cookie');

    $_COOKIE['secondary_currency'] = 'usd';
    assert_equals('irt', currencySecondaryCode(), 'secondary slot can never be USD');
    _curReset();
}

function test_data_attrs_expose_conversion_meta(): void
{
    _curSetRates();
    $_COOKIE['display_currency'] = 'irt';
    $attrs = currencyDataAttrs();
    assert_contains($attrs, 'data-cur-code="irt"', 'attrs carry the active code');
    assert_contains($attrs, 'data-cur-symbol="IRT"', 'attrs carry the symbol');
    assert_contains($attrs, 'data-cur-factor="188200"', 'attrs carry the factor');
    assert_contains($attrs, 'data-cur-decimals="0"', 'attrs carry IRT decimals');
    assert_contains($attrs, 'data-cur-pos="suffix"', 'attrs carry symbol position');
    _curReset();
}

function test_registry_symbols_have_no_farsi_glyphs(): void
{
    // Enforces the "English/ASCII only — no Farsi/Arabic" requirement.
    foreach (currencyRegistry() as $code => $meta) {
        $blob = $meta['symbol'] . $meta['label'];
        $hasArabicBlock = preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $blob) === 1;
        assert_false($hasArabicBlock, "currency $code uses no Farsi/Arabic glyphs");
    }
}
