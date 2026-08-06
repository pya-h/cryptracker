<?php
/**
 * Shared view helpers, CSRF protection, security headers & layout.
 *
 * This file aggregates all helper sub-modules. External code should
 * continue to `require_once` this file — the public API is unchanged.
 */

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/helpers/security.php';
require_once __DIR__ . '/helpers/preferences.php';
require_once __DIR__ . '/helpers/formatting.php';
require_once __DIR__ . '/helpers/currency.php';
require_once __DIR__ . '/helpers/pl_engine.php';
require_once __DIR__ . '/helpers/bank.php';
require_once __DIR__ . '/helpers/swap.php';
require_once __DIR__ . '/helpers/undo.php';
require_once __DIR__ . '/helpers/flash.php';
require_once __DIR__ . '/helpers/layout.php';
