<?php
/**
 * Extended formatting tests — formatBigNum, formatFormValue, formatSupply.
 */

function test_format_big_num_trillions(): void
{
    $_SESSION = ['precision' => 2, 'worthless_zeros' => false];
    assert_equals('$1.5T', formatBigNum(1.5e12), '1.5 trillion');
    assert_equals('$2T', formatBigNum(2.0e12), '2 trillion');
    $_SESSION = [];
}

function test_format_big_num_billions(): void
{
    $_SESSION = ['precision' => 2, 'worthless_zeros' => false];
    assert_equals('$1.5B', formatBigNum(1.5e9), '1.5 billion');
    assert_equals('$50B', formatBigNum(50.0e9), '50 billion');
    $_SESSION = [];
}

function test_format_big_num_millions(): void
{
    $_SESSION = ['precision' => 2, 'worthless_zeros' => false];
    assert_equals('$3.5M', formatBigNum(3.5e6), '3.5 million');
    assert_equals('$100M', formatBigNum(100.0e6), '100 million');
    $_SESSION = [];
}

function test_format_big_num_thousands(): void
{
    $_SESSION = ['precision' => 2, 'worthless_zeros' => false];
    assert_equals('$5K', formatBigNum(5000.0), '5 thousand');
    assert_equals('$42.5K', formatBigNum(42500.0), '42.5 thousand');
    $_SESSION = [];
}

function test_format_big_num_small(): void
{
    $_SESSION = ['precision' => 2, 'worthless_zeros' => false];
    assert_equals('$500', formatBigNum(500.0), 'Below 1K: regular format');
    assert_equals('$0', formatBigNum(0.0), 'Zero');
    $_SESSION = [];
}

function test_format_form_value(): void
{
    // formatFormValue should output a plain number string (no commas, no symbol)
    assert_equals('100', formatFormValue(100.0), 'Whole number');
    assert_equals('99.99', formatFormValue(99.99), 'Two decimals');
    assert_equals('0.001', formatFormValue(0.001), 'Small fraction');
    assert_equals('1234567.89', formatFormValue(1234567.89), 'Large number, no commas');
    assert_equals('0', formatFormValue(0.0), 'Zero');
}

function test_format_supply_large(): void
{
    $_SESSION = ['precision' => 2, 'worthless_zeros' => false];
    assert_equals('21M', formatSupply(21000000.0), '21 million supply');
    assert_equals('1B', formatSupply(1000000000.0), '1 billion supply');
    assert_equals('100T', formatSupply(100e12), '100 trillion supply');
    $_SESSION = [];
}

function test_format_supply_small(): void
{
    $_SESSION = ['precision' => 2, 'worthless_zeros' => false];
    assert_equals('500K', formatSupply(500000.0), '500K supply');
    assert_equals('100', formatSupply(100.0), 'Small supply');
    $_SESSION = [];
}

function test_format_supply_zero(): void
{
    assert_equals('—', formatSupply(0.0), 'Zero supply should return dash');
    assert_equals('—', formatSupply(-5.0), 'Negative supply should return dash');
}

function test_format_big_num_with_worthless_zeros(): void
{
    $_SESSION = ['precision' => 3, 'worthless_zeros' => true];
    assert_equals('$1.500B', formatBigNum(1.5e9), 'Worthless zeros preserved on bignum');
    assert_equals('$5.000K', formatBigNum(5000.0), 'Worthless zeros preserved on thousands');
    $_SESSION = [];
}

function test_format_supply_with_worthless_zeros(): void
{
    $_SESSION = ['precision' => 3, 'worthless_zeros' => true];
    assert_equals('21.000M', formatSupply(21000000.0), 'Supply with worthless zeros');
    $_SESSION = [];
}

function test_format_crypto_scientific(): void
{
    $_SESSION = ['precision' => 2, 'worthless_zeros' => false, 'scientific' => true];
    assert_equals('1.5K', formatCrypto(1500.0), 'thousands compact');
    assert_equals('2M', formatCrypto(2.0e6), 'millions compact');
    assert_equals('3.4B', formatCrypto(3.4e9), 'billions compact');
    assert_equals('5m', formatCrypto(0.005), 'milli compact');
    assert_equals('500u', formatCrypto(0.0005), 'micro compact');
    assert_equals('500n', formatCrypto(0.0000005), 'nano compact');
    assert_equals('1.5', formatCrypto(1.5), 'plain band unchanged');
    assert_equals('-2.5K', formatCrypto(-2500.0), 'negative compact');
    $_SESSION = [];
}

function test_format_crypto_scientific_off(): void
{
    $_SESSION = ['precision' => 2, 'worthless_zeros' => false];
    assert_equals('1,500', formatCrypto(1500.0), 'no compact when off');
    assert_equals('0.0005', formatCrypto(0.0005), 'tiny value untouched when off');
    $_SESSION = [];
}

function test_format_money_scientific(): void
{
    $_SESSION = ['precision' => 2, 'worthless_zeros' => false, 'scientific' => true];
    assert_equals('$1.5K', formatMoney(1500.0), 'money thousands compact');
    assert_equals('$500u', formatMoney(0.0005), 'money micro compact');
    assert_equals('$12.5', formatMoney(12.5), 'money plain band unchanged');
    assert_equals('+$2.5K', formatMoneyPL(2500.0), 'positive P/L compact');
    assert_equals('-$2.5K', formatMoneyPL(-2500.0), 'negative P/L compact');
    $_SESSION = [];
}
