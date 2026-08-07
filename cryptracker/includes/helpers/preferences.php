<?php
/**
 * User session preferences: P/L mode, precision, theme, etc.
 */

function plMode(): string
{
    return $_SESSION['pl_mode'] ?? 'fifo';
}

function precision(): int
{
    return (int) ($_SESSION['precision'] ?? 3);
}

function worthlessZeros(): bool
{
    return (bool) ($_SESSION['worthless_zeros'] ?? false);
}

function scientificNotation(): bool
{
    return (bool) ($_SESSION['scientific'] ?? false);
}

function trimZeros(string $s): string
{
    if (worthlessZeros()) return $s;
    return preg_replace_callback('/\d+\.\d+/', function ($m) {
        return rtrim(rtrim($m[0], '0'), '.');
    }, $s);
}

function theme(): string
{
    return $_SESSION['theme'] ?? 'light';
}

function priceSource(): string
{
    $src = strtolower((string) ($_SESSION['price_source'] ?? 'coinmarketcap'));
    $allowed = ['coinmarketcap', 'coinlore', 'coingecko'];
    return in_array($src, $allowed, true) ? $src : 'coinmarketcap';
}

function priceSourceLabel(string $source): string
{
    return match ($source) {
        'coinmarketcap' => 'CoinMarketCap',
        'coinlore' => 'CoinLore',
        'coingecko' => 'CoinGecko',
        default => 'CoinMarketCap',
    };
}
