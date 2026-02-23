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

function formatCrypto(float $amount, int $decimals = -1): string
{
    if ($decimals < 0) $decimals = precision();
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
