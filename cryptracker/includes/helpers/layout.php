<?php
/**
 * HTML layout rendering: head, navigation, customize modal, footer.
 */

function layoutHead(string $title, bool $authPage = false): void
{
    sendSecurityHeaders();
    $themeClass = theme() === 'light' ? 'theme-light' : '';
    $classes = trim(($authPage ? 'auth-page' : '') . ' ' . $themeClass);
    $bodyAttr = $classes ? 'class="' . $classes . '"' : '';
    $publicDir = __DIR__ . '/../../../public';
    $themeColor = theme() === 'light' ? '#ffffff' : '#0b0d14';
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>' . e($title) . ' – ' . e(APP_NAME) . '</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css?v=' . filemtime($publicDir . '/assets/style.css') . '">

    <link rel="manifest" href="manifest.webmanifest">
    <meta name="theme-color" content="' . $themeColor . '">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="' . e(APP_NAME) . '">
    <link rel="icon" type="image/svg+xml" href="assets/pwa/favicon.svg">
    <link rel="icon" type="image/png" sizes="192x192" href="assets/pwa/icon-192.png">
    <link rel="apple-touch-icon" href="assets/pwa/icon-192.png">
    <script defer src="assets/pwa.js?v=' . filemtime($publicDir . '/assets/pwa.js') . '"></script>

    <meta name="csrf-token" content="' . e(csrfToken()) . '">
</head>
<body ' . $bodyAttr . '>';
}

function layoutNav(array $user): void
{
    $mode = plMode();
    $isExact = $mode === 'fifo';
    $modeLabel = $isExact ? 'Exact' : 'Average';
    $redirect = $_SERVER['REQUEST_URI'] ?? 'index.php';
    $redirect = basename($redirect);
    if (!preg_match('/^(index\.php|token\.php\?id=\d+)$/', $redirect)) {
        $redirect = 'index.php';
    }
    $src = priceSource();
    $srcLabel = priceSourceLabel($src);

    $curActive      = currencyActiveCode();
    $curSecondary   = currencySecondaryCode();
    $curIsSecondary = $curActive !== 'usd';

    echo '<nav class="navbar animate-slide-down">
        <a href="index.php" class="nav-brand">
            <span class="brand-icon">◈</span> ' . e(APP_NAME) . '
        </a>
        <div class="nav-right">
            <div class="source-indicator" id="sourceIndicator" data-selected-source="' . e($src) . '">
                <span class="source-dot"></span>
                <span class="source-text">' . e($srcLabel) . '</span>
            </div>
            <button type="button" class="mode-toggle currency-toggle" id="currencyToggle"
                    data-cur-current="' . e($curActive) . '" data-cur-secondary="' . e($curSecondary) . '"
                    data-tooltip="Display currency">
                <span class="mode-label" id="currencyToggleLabel">' . e(strtoupper($curActive)) . '</span>
                <span class="mode-switch ' . ($curIsSecondary ? 'active' : '') . '">
                    <span class="mode-knob"></span>
                </span>
            </button>
            <form method="POST" action="toggle_mode.php" class="mode-toggle-form">
                ' . csrfField() . '
                <input type="hidden" name="redirect" value="' . e($redirect) . '">
                <button type="submit" class="mode-toggle" data-tooltip="P/L calculation mode">
                    <span class="mode-label">' . $modeLabel . '</span>
                    <span class="mode-switch ' . ($isExact ? 'active' : '') . '">
                        <span class="mode-knob"></span>
                    </span>
                </button>
            </form>
            <div class="user-menu-wrapper">
                <button type="button" class="nav-user-btn" id="userMenuBtn">
                    <span class="user-avatar">' . e(strtoupper($user['username'][0])) . '</span>
                    <span class="user-name">' . e($user['username']) . '</span>
                    <span class="user-caret">▾</span>
                </button>
                <div class="user-menu" id="userMenu">
                    <button type="button" class="user-menu-item" id="openCustomize">
                        <span class="menu-icon">⚙</span> Customize
                    </button>
                    <button type="button" class="user-menu-item" id="exportCsv">
                        <span class="menu-icon">⤓</span> Export to CSV
                    </button>
                    <button type="button" class="user-menu-item" id="exportJson">
                        <span class="menu-icon">⎙</span> Export to JSON
                    </button>
                    <div class="user-menu-divider"></div>
                    <form method="POST" action="logout.php" class="menu-logout-form">
                        ' . csrfField() . '
                        <button type="submit" class="user-menu-item menu-danger">
                            <span class="menu-icon">⏻</span> Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>';

    layoutCustomizeModal($user, $redirect);
}

function layoutCustomizeModal(array $user, string $redirect): void
{
    $mode = plMode();
    $isExact = $mode === 'fifo';
    $modeLabel = $isExact ? 'Exact' : 'Average';
    $thm = theme();
    $prec = precision();
    $wz   = worthlessZeros();
    $sci  = scientificNotation();
    $src = priceSource();

    $curSel = currencyActiveCode();
    $curOptions = '';
    foreach (currencyRegistry() as $code => $meta) {
        $curOptions .= '<option value="' . e($code) . '"' . ($code === $curSel ? ' selected' : '') . '>'
            . e($meta['label']) . '</option>';
    }

    echo '<div class="modal-overlay" id="customizeOverlay">
    <div class="modal animate-scale-in">
        <div class="modal-header">
            <h2>Customize</h2>
            <button type="button" class="modal-close" id="closeCustomize">&times;</button>
        </div>
        <div class="modal-body">

            <div class="setting-group">
                <span class="setting-label">P/L Calculation Mode</span>
                <form method="POST" action="toggle_mode.php" class="mode-toggle-form">
                    ' . csrfField() . '
                    <input type="hidden" name="redirect" value="' . e($redirect) . '">
                    <button type="submit" class="mode-toggle" data-tooltip="P/L calculation mode">
                        <span class="mode-label">' . $modeLabel . '</span>
                        <span class="mode-switch ' . ($isExact ? 'active' : '') . '">
                            <span class="mode-knob"></span>
                        </span>
                    </button>
                </form>
            </div>

            <div class="setting-group">
                <span class="setting-label">Theme</span>
                <form method="POST" action="save_settings.php" class="mode-toggle-form">
                    ' . csrfField() . '
                    <input type="hidden" name="action" value="theme">
                    <input type="hidden" name="redirect" value="' . e($redirect) . '">
                    <button type="submit" class="mode-toggle">
                        <span class="mode-label">' . ($thm === 'light' ? 'Light' : 'Dark') . '</span>
                        <span class="mode-switch ' . ($thm === 'light' ? 'active' : '') . '">
                            <span class="mode-knob"></span>
                        </span>
                    </button>
                </form>
            </div>

            <div class="setting-group">
                <span class="setting-label">Precision: <strong id="precVal">' . $prec . '</strong> digits</span>
                <form method="POST" action="save_settings.php" class="precision-form" id="precisionForm">
                    ' . csrfField() . '
                    <input type="hidden" name="action" value="precision">
                    <input type="hidden" name="redirect" value="' . e($redirect) . '">
                    <input type="range" name="precision" min="2" max="10" value="' . $prec . '" class="precision-slider" id="precSlider">
                    <div class="precision-range-labels"><span>2</span><span>10</span></div>
                    <label class="wz-checkbox">
                        <input type="checkbox" name="worthless_zeros" value="1"' . ($wz ? ' checked' : '') . '>
                        <span>Worthless Zeros</span>
                    </label>
                    <label class="wz-checkbox">
                        <input type="checkbox" name="scientific" value="1"' . ($sci ? ' checked' : '') . '>
                        <span>Scientific Notation (K/M/B, m/u/n)</span>
                    </label>
                    <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.5rem">Apply</button>
                </form>
            </div>

            <div class="setting-group">
                <span class="setting-label">Price Source</span>
                <form method="POST" action="save_settings.php" class="settings-form">
                    ' . csrfField() . '
                    <input type="hidden" name="action" value="price_source">
                    <input type="hidden" name="redirect" value="' . e($redirect) . '">
                    <select name="price_source" required>
                        <option value="coinmarketcap"' . ($src === 'coinmarketcap' ? ' selected' : '') . '>CoinMarketCap (default)</option>
                        <option value="coinlore"' . ($src === 'coinlore' ? ' selected' : '') . '>CoinLore</option>
                        <option value="coingecko"' . ($src === 'coingecko' ? ' selected' : '') . '>CoinGecko</option>
                    </select>
                    <small class="setting-note">Current source: ' . e(priceSourceLabel($src)) . '</small>
                    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                </form>
            </div>

            <div class="setting-group">
                <span class="setting-label">Display Currency</span>
                <select id="displayCurrencySelect" class="settings-select">' . $curOptions . '</select>
                <small class="setting-note">Convert all displayed values. Choosing a non-USD currency also becomes the navbar USD⇄currency switch. Free-market rates (Toman-based).</small>
            </div>

            <div class="setting-divider"></div>

            <div class="setting-group">
                <span class="setting-label">Change Username</span>
                <form method="POST" action="save_settings.php" class="settings-form">
                    ' . csrfField() . '
                    <input type="hidden" name="action" value="username">
                    <input type="hidden" name="redirect" value="' . e($redirect) . '">
                    <input type="text" name="new_username" placeholder="New username" required
                           minlength="3" pattern="[a-zA-Z0-9_\-]{3,30}"
                           title="3-30 characters: letters, numbers, _ or -">
                    <button type="submit" class="btn btn-primary btn-sm">Update</button>
                </form>
            </div>

            <div class="setting-group">
                <span class="setting-label">Change Password</span>
                <form method="POST" action="save_settings.php" class="settings-form">
                    ' . csrfField() . '
                    <input type="hidden" name="action" value="password">
                    <input type="hidden" name="redirect" value="' . e($redirect) . '">
                    <input type="password" name="old_password" placeholder="Current password" required>
                    <input type="password" name="new_password" placeholder="New password (min 6 chars)" required minlength="6">
                    <button type="submit" class="btn btn-primary btn-sm">Update</button>
                </form>
            </div>

        </div>
    </div>
</div>';
}

function layoutFooter(): void
{
    echo '<script src="assets/app.js?v=' . filemtime(__DIR__ . '/../../../public/assets/app.js') . '"></script>
</body>
</html>';
}
