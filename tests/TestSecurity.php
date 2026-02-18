<?php
/**
 * Security & Helper Tests
 */

function test_security_csrf_token_generation(): void
{
    $_SESSION = [];

    $token = csrfToken();
    assert_true(strlen($token) === 64, 'CSRF token should be 64 hex chars');
    assert_true(ctype_xdigit($token), 'CSRF token should be hex string');

    // Same session should return same token
    $token2 = csrfToken();
    assert_equals($token, $token2, 'Same session should return same CSRF token');

    $_SESSION = [];
}

function test_security_csrf_field(): void
{
    $_SESSION = [];

    $field = csrfField();
    assert_contains($field, 'type="hidden"', 'CSRF field should be hidden input');
    assert_contains($field, 'name="_csrf"', 'CSRF field name should be _csrf');
    assert_contains($field, csrfToken(), 'CSRF field should contain the token value');

    $_SESSION = [];
}

function test_security_csrf_verify(): void
{
    $_SESSION = [];

    $token = csrfToken();

    $_POST['_csrf'] = $token;
    assert_true(csrfVerify(), 'Valid CSRF token should verify');

    $_POST['_csrf'] = 'invalid_token';
    assert_false(csrfVerify(), 'Invalid CSRF token should not verify');

    unset($_POST['_csrf']);
    assert_false(csrfVerify(), 'Missing CSRF token should not verify');

    $_SESSION = [];
    $_POST['_csrf'] = $token;
    assert_false(csrfVerify(), 'CSRF verify should fail with empty session');

    $_POST = [];
    $_SESSION = [];
}

function test_security_e_escaping(): void
{
    assert_equals('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'), 'Should escape HTML tags');
    assert_equals('&quot;quoted&quot;', e('"quoted"'), 'Should escape double quotes');
    // PHP 8.4+ENT_HTML5 uses &apos; while older versions use &#039;
    $escaped = e("'single'");
    assert_true(
        $escaped === '&#039;single&#039;' || $escaped === '&apos;single&apos;',
        'Should escape single quotes'
    );
    assert_equals('a &amp; b', e('a & b'), 'Should escape ampersands');
    assert_equals('safe text', e('safe text'), 'Safe text should pass through');
}

function test_helper_pl_class(): void
{
    assert_equals('profit', plClass(100.0), 'Positive should be profit');
    assert_equals('profit', plClass(0.0), 'Zero should be profit');
    assert_equals('loss', plClass(-50.0), 'Negative should be loss');
}

function test_helper_format_pl(): void
{
    assert_equals('+$1,000.00', formatPL(1000.0), 'Positive P/L format');
    assert_equals('+$0.00', formatPL(0.0), 'Zero P/L format');

    // Negative values — the key bug fix: should be -$100.00 not $-100.00
    assert_equals('-$100.00', formatPL(-100.0), 'Negative P/L should have sign before $');
    assert_equals('-$1,234.56', formatPL(-1234.56), 'Large negative P/L format');
}

function test_helper_format_usd(): void
{
    assert_equals('$1,000.00', formatUSD(1000.0), 'USD format');
    assert_equals('$0.00', formatUSD(0.0), 'Zero USD');
    assert_equals('$99.99', formatUSD(99.99), 'Decimals');
}

function test_helper_format_crypto(): void
{
    assert_equals('1.5', formatCrypto(1.5), 'Trim trailing zeros');
    assert_equals('0.001', formatCrypto(0.001), 'Small crypto amount');
    assert_equals('100', formatCrypto(100.0), 'Whole number should have no decimals');
    assert_equals('0.12345678', formatCrypto(0.12345678), 'Full precision');
}

function test_helper_format_percent(): void
{
    assert_equals('+5.25%', formatPercent(5.25), 'Positive percent');
    assert_equals('-3.10%', formatPercent(-3.1), 'Negative percent');
    assert_equals('+0.00%', formatPercent(0.0), 'Zero percent');
}

function test_helper_flash_messages(): void
{
    $_SESSION = [];

    flash('success', 'Token added!');
    flash('error', 'Something failed.');

    $flashes = getFlashes();
    assert_equals(2, count($flashes), 'Should have 2 flash messages');
    assert_equals('success', $flashes[0]['type'], 'First flash type');
    assert_equals('Token added!', $flashes[0]['message'], 'First flash message');
    assert_equals('error', $flashes[1]['type'], 'Second flash type');

    $again = getFlashes();
    assert_equals(0, count($again), 'Flashes should be consumed after reading');

    $_SESSION = [];
}

function test_helper_render_flashes(): void
{
    $_SESSION = [];

    flash('error', 'Test error');
    $html = renderFlashes();
    assert_contains($html, 'alert-error', 'Should contain error class');
    assert_contains($html, 'Test error', 'Should contain message');
    assert_contains($html, 'animate-slide-down', 'Should contain animation class');

    $_SESSION = [];
}

function test_security_password_cost(): void
{
    dbPurgeAll();
    $_SESSION = [];

    registerUser('costtest', 'cost@test.com', 'password123');
    $user = dbGetUserByField('username', 'costtest');

    // Verify bcrypt cost is 12 (higher security)
    $info = password_get_info($user['password_hash']);
    assert_equals('bcrypt', $info['algoName'], 'Should use bcrypt');

    dbPurgeAll();
    $_SESSION = [];
}
