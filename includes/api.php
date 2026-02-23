<?php
/**
 * Crypto price API wrapper (aggregator).
 * Default source: CoinMarketCap.
 * Supported sources: CoinMarketCap, CoinLore, CoinGecko.
 *
 * This file is a thin wrapper that loads all sub-modules.
 */

require_once __DIR__ . '/config.php';

// --- API sub-modules (order matters: common first, orchestrator last) ---
require_once __DIR__ . '/api/common.php';
require_once __DIR__ . '/api/coinmarketcap.php';
require_once __DIR__ . '/api/coinlore.php';
require_once __DIR__ . '/api/coingecko.php';
require_once __DIR__ . '/api/resolver.php';
require_once __DIR__ . '/api/orchestrator.php';
