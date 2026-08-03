<?php
/**
 * CrypTracker Test Runner
 *
 * Run:  php tests/run.php
 *
 * Each test file defines functions named test_*(), this runner
 * discovers and executes them, printing results.
 */

/* ── Minimal test framework ─────────────────────────────────── */

$_TEST_RESULTS = ['passed' => 0, 'failed' => 0, 'errors' => []];

function assert_true(bool $condition, string $message = ''): void
{
    global $_TEST_RESULTS;
    if ($condition) {
        $_TEST_RESULTS['passed']++;
    } else {
        $_TEST_RESULTS['failed']++;
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $file  = basename($trace[0]['file']);
        $line  = $trace[0]['line'];
        $_TEST_RESULTS['errors'][] = "  FAIL: $message ($file:$line)";
    }
}

function assert_false(bool $condition, string $message = ''): void
{
    assert_true(!$condition, $message);
}

function assert_equals($expected, $actual, string $message = ''): void
{
    // Use loose comparison for numeric types (JSON decode returns int for whole numbers)
    $match = (is_numeric($expected) && is_numeric($actual))
        ? ($expected == $actual)
        : ($expected === $actual);

    if ($match) {
        assert_true(true);
    } else {
        $exp = var_export($expected, true);
        $act = var_export($actual, true);
        assert_true(false, "$message — Expected: $exp, Got: $act");
    }
}

function assert_not_null($value, string $message = ''): void
{
    assert_true($value !== null, $message);
}

function assert_null($value, string $message = ''): void
{
    assert_true($value === null, $message);
}

function assert_greater_than(float $a, float $b, string $message = ''): void
{
    assert_true($a > $b, "$message — Expected $a > $b");
}

function assert_contains(string $haystack, string $needle, string $message = ''): void
{
    assert_true(str_contains($haystack, $needle), "$message — '$needle' not found in string");
}

/* ── Setup: use isolated test data directory ────────────────── */

define('APP_BASE_PATH', __DIR__ . '/tmp_test_data');

if (is_dir(APP_BASE_PATH)) {
    array_map('unlink', glob(APP_BASE_PATH . '/database/*.json'));
    array_map('unlink', glob(APP_BASE_PATH . '/database/*.tmp.*'));
    array_map('unlink', glob(APP_BASE_PATH . '/database/*.sqlite'));
    @rmdir(APP_BASE_PATH . '/database');
    @unlink(APP_BASE_PATH . '/.env');
    @rmdir(APP_BASE_PATH);
}

if (!is_dir(APP_BASE_PATH)) {
    mkdir(APP_BASE_PATH, 0755, true);
}

file_put_contents(APP_BASE_PATH . '/.env', implode("\n", [
    'APP_NAME=CrypTracker_Test',
    'CMC_API_KEY=test_key_not_real',
    'SESSION_LIFETIME=3600',
]));

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ── Load application code ──────────────────────────────────── */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/json_db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api.php';

/* ── Discover and run test files ────────────────────────────── */

echo "\n╔══════════════════════════════════════════════╗\n";
echo "║       CrypTracker Test Suite                 ║\n";
echo "╚══════════════════════════════════════════════╝\n\n";

$testFiles = glob(__DIR__ . '/Test*.php');
sort($testFiles);

foreach ($testFiles as $file) {
    $name = basename($file, '.php');
    echo "▸ Loading $name...\n";
    require_once $file;
}

$allFunctions = get_defined_functions()['user'];
$testFunctions = array_filter($allFunctions, fn($f) => str_starts_with($f, 'test_'));

echo "\n─── Running " . count($testFunctions) . " tests ───\n\n";

foreach ($testFunctions as $fn) {
    echo "  ● $fn ... ";
    try {
        $before = $_TEST_RESULTS['failed'];
        $fn();
        if ($_TEST_RESULTS['failed'] === $before) {
            echo "✓\n";
        } else {
            echo "✗\n";
        }
    } catch (\Throwable $e) {
        $_TEST_RESULTS['failed']++;
        $_TEST_RESULTS['errors'][] = "  EXCEPTION in $fn: " . $e->getMessage();
        echo "✗ (exception)\n";
    }
}

/* ── Results ────────────────────────────────────────────────── */

echo "\n══════════════════════════════════════════════\n";
echo "  Passed: {$_TEST_RESULTS['passed']}\n";
echo "  Failed: {$_TEST_RESULTS['failed']}\n";

if ($_TEST_RESULTS['errors']) {
    echo "\n  Failures:\n";
    foreach ($_TEST_RESULTS['errors'] as $err) {
        echo "  $err\n";
    }
}

echo "══════════════════════════════════════════════\n";

/* ── Cleanup ────────────────────────────────────────────────── */

array_map('unlink', glob(APP_BASE_PATH . '/database/*.json'));
array_map('unlink', glob(APP_BASE_PATH . '/database/*.tmp.*'));
array_map('unlink', glob(APP_BASE_PATH . '/database/*.sqlite'));
@rmdir(APP_BASE_PATH . '/database');
@unlink(APP_BASE_PATH . '/.env');
@rmdir(APP_BASE_PATH);

exit($_TEST_RESULTS['failed'] > 0 ? 1 : 0);
