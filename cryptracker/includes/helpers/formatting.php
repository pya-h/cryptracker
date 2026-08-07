<?php
/**
 * Value formatting helpers for display output.
 */

function plClass(float $v): string
{
    return $v >= 0 ? 'profit' : 'loss';
}

function formatPL(float $v, int $decimals = -1): string
{
    if ($decimals < 0) $decimals = precision();
    if ($v >= 0) {
        return trimZeros('+$' . number_format($v, $decimals));
    }
    return trimZeros('-$' . number_format(abs($v), $decimals));
}

/**
 * When Scientific Notation is on, map |value| to an SI-style [divisor, suffix]
 * pair — K/M/B/T for ≥1000, m/u/n for <0.01 — or null inside the plain band.
 * The mirror of this lives in app.js (compactUnit) so live values match.
 */
function compactMagnitude(float $abs): ?array
{
    if ($abs <= 0 || !is_finite($abs)) return null;
    if ($abs >= 1e12) return [1e12, 'T'];
    if ($abs >= 1e9)  return [1e9, 'B'];
    if ($abs >= 1e6)  return [1e6, 'M'];
    if ($abs >= 1e3)  return [1e3, 'K'];
    if ($abs >= 0.01) return null;
    if ($abs >= 1e-3) return [1e-3, 'm'];
    if ($abs >= 1e-6) return [1e-6, 'u'];
    return [1e-9, 'n'];
}

function formatCrypto(float $amount, int $decimals = -1): string
{
    if ($decimals < 0) $decimals = precision();
    if (scientificNotation() && ($u = compactMagnitude(abs($amount))) !== null) {
        $mant = trimZeros(number_format(abs($amount) / $u[0], precision()));
        return ($amount < 0 ? '-' : '') . $mant . $u[1];
    }
    $max = max($decimals, 8);
    return rtrim(rtrim(number_format($amount, $max), '0'), '.');
}

function formatUSD(float $v, int $decimals = -1): string
{
    if ($decimals < 0) $decimals = precision();
    return trimZeros('$' . number_format($v, $decimals));
}

function formatPercent(float $v, int $decimals = -1): string
{
    if ($decimals < 0) $decimals = precision();
    $sign = $v >= 0 ? '+' : '';
    return trimZeros($sign . number_format($v, $decimals)) . '%';
}

function formatBigNum(float $v): string
{
    $p = precision();
    if ($v >= 1e12) return trimZeros('$' . number_format($v / 1e12, $p)) . 'T';
    if ($v >= 1e9)  return trimZeros('$' . number_format($v / 1e9, $p)) . 'B';
    if ($v >= 1e6)  return trimZeros('$' . number_format($v / 1e6, $p)) . 'M';
    if ($v >= 1e3)  return trimZeros('$' . number_format($v / 1e3, $p)) . 'K';
    return trimZeros('$' . number_format($v, $p));
}

function formatFormValue(float $v): string
{
    return rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.');
}

function formatSupply(float $v): string
{
    if ($v <= 0) return '—';
    $p = precision();
    if ($v >= 1e12) return trimZeros(number_format($v / 1e12, $p)) . 'T';
    if ($v >= 1e9)  return trimZeros(number_format($v / 1e9, $p)) . 'B';
    if ($v >= 1e6)  return trimZeros(number_format($v / 1e6, $p)) . 'M';
    if ($v >= 1e3)  return trimZeros(number_format($v / 1e3, $p)) . 'K';
    return number_format($v, 0);
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
