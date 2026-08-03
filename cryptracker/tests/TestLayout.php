<?php
/**
 * Layout rendering tests — verify HTML output via output buffering.
 * Note: sendSecurityHeaders() emits warnings in CLI since output
 * was already started; we suppress them with error_reporting.
 */

function _captureLayout(callable $fn): string
{
    $prev = error_reporting(E_ALL & ~E_WARNING);
    ob_start();
    $fn();
    $html = ob_get_clean();
    error_reporting($prev);
    return $html;
}

function test_layout_head_basic(): void
{
    $_SESSION = [];

    $html = _captureLayout(fn() => layoutHead('Dashboard'));

    assert_contains($html, '<!DOCTYPE html>', 'Should start with doctype');
    assert_contains($html, '<html lang="en">', 'Should have html lang');
    assert_contains($html, 'Dashboard', 'Should contain page title');
    assert_contains($html, APP_NAME, 'Should contain app name');
    assert_contains($html, 'style.css', 'Should link stylesheet');
    assert_contains($html, 'csrf-token', 'Should include CSRF meta tag');

    $_SESSION = [];
}

function test_layout_head_theme_light(): void
{
    $_SESSION = ['theme' => 'light'];

    $html = _captureLayout(fn() => layoutHead('Test'));

    assert_contains($html, 'theme-light', 'Light theme should add theme-light class');

    $_SESSION = [];
}

function test_layout_head_theme_dark(): void
{
    $_SESSION = ['theme' => 'dark'];

    $html = _captureLayout(fn() => layoutHead('Test'));

    // Dark theme is default — no theme-light class
    assert_false(str_contains($html, 'theme-light'), 'Dark theme should not have theme-light class');

    $_SESSION = [];
}

function test_layout_head_auth_page(): void
{
    $_SESSION = [];

    $html = _captureLayout(fn() => layoutHead('Login', true));

    assert_contains($html, 'auth-page', 'Auth page should have auth-page class');

    $_SESSION = [];
}

function test_layout_footer(): void
{
    $html = _captureLayout(fn() => layoutFooter());

    assert_contains($html, 'app.js', 'Footer should include app.js');
    assert_contains($html, '</body>', 'Footer should close body tag');
    assert_contains($html, '</html>', 'Footer should close html tag');
}

function test_layout_head_escapes_title(): void
{
    $_SESSION = [];

    $html = _captureLayout(fn() => layoutHead('<script>alert(1)</script>'));

    assert_false(str_contains($html, '<script>alert(1)</script>'), 'Title should be HTML-escaped');
    assert_contains($html, '&lt;script&gt;', 'Escaped script tag in title');

    $_SESSION = [];
}

function test_layout_footer_cache_busting(): void
{
    $html = _captureLayout(fn() => layoutFooter());

    // Should have ?v= cache buster on app.js
    assert_contains($html, 'app.js?v=', 'Footer should have cache-busting query string');
}
