<div align="center">

# CrypTracker

**A self-hosted cryptocurrency portfolio tracker built with pure PHP, vanilla JavaScript, and CSS.**

Track tokens, record trades, compute profit & loss, and view live prices — all without frameworks, npm, or external dependencies.

[![PHP 8.0+](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![Tests](https://img.shields.io/badge/tests-146%20passed-brightgreen)](cryptracker/tests/run.php)
[![Assertions](https://img.shields.io/badge/assertions-481-blue)](cryptracker/tests/run.php)
[![License](https://img.shields.io/badge/license-MIT-green)](#license)

</div>

---

## Why CrypTracker?

Most crypto trackers are either bloated SaaS platforms that harvest your data, or complex self-hosted apps requiring Docker, Node.js, and a database server. CrypTracker takes a different approach:

- **Zero external dependencies** — No Composer, no npm, no Docker. Just PHP.
- **No database server** — Data lives in SQLite or plain JSON files.
- **Privacy-first** — Self-hosted, your data never leaves your machine.
- **Minimal footprint** — ~5K lines of PHP, ~2K lines of JS, ~1.6K lines of CSS.

---

## Features

### Portfolio Management
- **Multi-token tracking** — Search and add any of 5,000+ cryptocurrencies
- **Buy/sell recording** — Record trades with exact price per unit and date
- **Direct swaps** — Convert one tracked token straight into another in a single step (records a sell of A + a buy of B), with two-way amount inputs, live-price or custom rate/ratio, and strict value conservation
- **Undo last action** — Instantly revert the most recent buy, sell, or swap (a swap removes both legs) from the transaction history; single-step by design, so an undo can never corrupt earlier records' stored P/L
- **Banks (wallets)** — Optionally split each token's holdings across named wallets (e.g. Main, Ledger, exchange) in a collapsed per-token section. Purely a bookkeeping overlay: it never touches P/L, charts, or history — buys/sells/swaps just carry a wallet so its balance stays current, and a sell/swap is capped by the chosen wallet's balance. The default "Main" wallet always holds the remainder
- **P/L engine** — Choose between **FIFO** or **weighted-average** cost basis methods
- **Realized & unrealized P/L** — Per-token and portfolio-wide calculations
- **Analytics timeline** — Historical P/L progression with interactive canvas graph

### Live Market Data
- **Multi-provider architecture** — CoinMarketCap, CoinLore, and CoinGecko
- **Automatic failover** — If one provider fails 3× rapidly, auto-switches to the next
- **10-second auto-refresh** — Prices, P/L values, and market data update live on the page
- **Smart caching** — Provider coin lists cached (1h–24h) to avoid redundant API calls

### User Experience
- **Multi-currency display** — View every value (holdings, P/L, prices) in USD, Toman/IRT, EUR, CAD, GBP, AED or TRY via a navbar switch; free-market (Toman-based) rates, display-only conversion (stored data stays in USD)
- **Dark & light themes** — Full theme support with glassmorphism and smooth animations
- **Responsive design** — Works on desktop, tablet, and mobile
- **Count-up animations** — P/L values animate on page load
- **Interactive graph** — Canvas-rendered P/L chart with hover tooltips, series toggling, and screenshot capture (drag-select → crop → zoom → download PNG)
- **Customization** — Configurable decimal precision, worthless-zero display, and trim-zero formatting
- **Export** — Download portfolio data as CSV or JSON

### Progressive Web App
- **Installable** — Add to home screen / desktop with its own icon and standalone window
- **Offline shell** — Service worker caches static assets and fonts; shows a branded offline page when the network drops
- **Security-aware caching** — Authenticated HTML and live price/JSON endpoints are **never** cached (no cross-user data leaks); only versioned static assets are
- **Dependency-free icons** — Launcher/maskable PNGs generated in pure PHP (no GD/ImageMagick)

### Security
- **bcrypt password hashing** (cost 12)
- **CSRF protection** on all POST forms
- **XSS prevention** via `htmlspecialchars()` with `ENT_QUOTES | ENT_HTML5`
- **Session fixation prevention** — ID regenerated on login/register
- **Rate limiting** — 5 login attempts per 60 seconds
- **Security headers** — X-Frame-Options, X-Content-Type-Options, X-XSS-Protection
- **Input validation** — Username regex, email filter, type whitelisting
- **Atomic writes** — File-based storage uses temp file + rename

---

## Requirements

- **PHP 8.0+** with `json` and `session` extensions
- No database server needed (SQLite preferred, JSON fallback)
- No curl required (uses `file_get_contents` with stream contexts)
- No Composer or npm

---

## Quick Start

```bash
# Clone and enter the project
git clone <your-repo-url> cryptracker
cd cryptracker

# Copy environment config (lives in the private core folder)
cp cryptracker/.env.example cryptracker/.env

# (Optional) Add your CoinMarketCap API key in cryptracker/.env
# CMC_API_KEY=your-key-here

# Start the development server with the web root pointed at public/
php -S 0.0.0.0:8080 -t public

# Open http://localhost:8080 and register an account
```

> **Deployment (cPanel / shared hosting):** map the **contents of `public/`** to your
> web root (e.g. `public_html`) and keep the `cryptracker/` folder **outside** the web
> root, as a sibling of it. Only `public/` is ever served; all backend code, secrets
> (`.env`), and stored data stay private. Both folders also ship a defensive `.htaccess`
> as a second layer in case of a docroot misconfiguration.

---

## Project Structure

The project is split into two top-level folders for security: **`public/`** (the only
folder served by the web server) and **`cryptracker/`** (the private core — backend
logic, secrets, and data — kept **outside** the web root).

```
cryptracker/                        # ← project root (repo)
│
├── public/                         # ← WEB ROOT (map its contents to public_html)
│   ├── .htaccess                   # -Indexes + block dotfiles/sensitive extensions
│   ├── index.php                   # Portfolio dashboard
│   ├── token.php                   # Single-token analytics, graph, trades
│   ├── transaction.php             # Buy/sell POST handler
│   ├── swap.php                    # Token-to-token conversion POST handler
│   ├── undo.php                    # Undo-last-action POST handler
│   ├── bank.php                    # Banks (wallets) create/delete/move handler
│   ├── add_token.php               # Add token POST handler
│   ├── remove_token.php            # Remove token POST handler
│   ├── search_tokens.php           # AJAX coin search endpoint
│   ├── api_prices.php              # AJAX live price endpoint
│   ├── save_settings.php           # User preferences handler
│   ├── toggle_mode.php             # FIFO ↔ AVG mode switch
│   ├── export_csv.php              # CSV export
│   ├── export_json.php             # JSON export
│   ├── login.php                   # Login page
│   ├── register.php                # Registration page
│   ├── logout.php                  # Logout handler
│   ├── manifest.webmanifest        # PWA manifest (name, icons, theme)
│   ├── service-worker.js           # PWA offline caching (security-aware)
│   ├── offline.html                # Offline fallback page
│   └── assets/
│       ├── app.js                  # Live refresh, search, UI interactions
│       ├── graph.js                # Canvas P/L graph + screenshot system
│       ├── pwa.js                  # Service worker registration
│       ├── style.css               # Theme, layout, glassmorphism, animations
│       └── pwa/                    # App icons (192/512/maskable PNG + favicon.svg)
│
└── cryptracker/                    # ← PRIVATE CORE (keep OUTSIDE the web root)
    ├── .htaccess                   # Deny-all defense-in-depth
    ├── .env                        # Secrets (gitignored)
    ├── .env.example                # Environment config template
    │
    ├── includes/                   # Core PHP logic
    │   ├── config.php              # Env loader, session bootstrap
    │   ├── db.php                  # DB strategy dispatcher
    │   ├── sqlite_db.php           # SQLite storage implementation
    │   ├── json_db.php             # JSON flat-file storage implementation
    │   ├── auth.php                # Register, login, logout, current user
    │   ├── helpers.php             # Wrapper — loads 10 sub-modules ↓
    │   │   └── helpers/
    │   │       ├── security.php    # CSRF tokens, XSS escaping, headers
    │   │       ├── formatting.php  # USD, crypto, P/L, percent formatting
    │   │       ├── preferences.php # Theme, precision, P/L mode, source
    │   │       ├── pl_engine.php   # FIFO & weighted-average P/L calc
    │   │       ├── swap.php        # Value-conserving token-to-token conversion
    │   │       ├── bank.php        # Banks (wallets) — display-only holdings segmentation
    │   │       ├── currency.php    # Display-currency conversion (USD → Toman/EUR/…)
    │   │       ├── undo.php        # Single-step undo of the last buy/sell/swap
    │   │       ├── flash.php       # Flash message system
    │   │       └── layout.php      # HTML head, nav, footer rendering
    │   ├── api.php                 # Wrapper — loads 6 sub-modules ↓
    │   │   └── api/
    │   │       ├── common.php      # Source management, HTTP client
    │   │       ├── coinmarketcap.php  # CMC provider
    │   │       ├── coinlore.php    # CoinLore provider
    │   │       ├── coingecko.php   # CoinGecko provider
    │   │       ├── resolver.php    # Cross-provider token ID resolution
    │   │       └── orchestrator.php   # Multi-source fallback & search
    │
    ├── tools/
    │   └── generate_pwa_icons.php  # Pure-PHP PWA icon generator (no image libs)
    │
    ├── tests/                      # 146 tests / 481 assertions (JSON + SQLite)
    │   ├── run.php                 # Test runner (autodiscovers Test*.php; DB_BACKEND-aware)
    │   ├── run.sh                  # Runs the suite against both JSON & SQLite backends
    │   ├── TestAuth.php            # Authentication tests
    │   ├── TestDb.php              # Database layer tests
    │   ├── TestPL.php              # P/L calculation tests (FIFO + AVG)
    │   ├── TestSwap.php            # Token swap / conversion tests
    │   ├── TestBank.php            # Banks / wallet segmentation tests
    │   ├── TestCurrency.php        # Display-currency conversion tests
    │   ├── TestUndo.php            # Undo-last-action tests
    │   ├── TestSecurity.php        # CSRF, XSS, formatting, flash tests
    │   ├── TestPreferences.php     # User preference helpers tests
    │   ├── TestFormatting.php      # Number formatting tests
    │   ├── TestApiCommon.php       # API utility & source management tests
    │   └── TestLayout.php          # HTML layout rendering tests
    │
    └── database/                   # Auto-created data files (gitignored)
```

---

## Running Tests

```bash
php cryptracker/tests/run.php        # JSON backend (default)
```

Or run the identical suite against **both** storage backends:

```bash
cryptracker/tests/run.sh             # JSON + SQLite
```

`run.sh` runs `run.php` once per backend. The JSON pass always runs; the SQLite
pass (`DB_BACKEND=sqlite`) self-skips with a notice when the `pdo_sqlite` driver
isn't installed, so it stays green on JSON-only machines and gives full
dual-backend coverage anywhere `pdo_sqlite` is present (CI, production hosts).

Tests use an isolated temporary data directory and clean up after themselves.

```
══════════════════════════════════════════════
  Passed: 481
  Failed: 0
══════════════════════════════════════════════
```

| Suite | Tests | Coverage |
|---|---|---|
| **Database** | 8 | CRUD, cascade delete, field whitelisting, multi-user isolation |
| **Authentication** | 7 | Registration, validation, duplicate prevention, login, rate limiting |
| **P/L Calculations** | 17 | FIFO, weighted-avg (moving), sell-then-rebuy, partial sell, cross-lot, break-even, timeline |
| **Token Swaps** | 10 | Value conservation, FIFO/avg (moving) realized P/L, notes, holdings & ownership guards, wallet routing |
| **Undo** | 9 | Buy/sell/swap revert, single-use, latest-only & ownership guards, P/L restoration |
| **Banks (wallets)** | 22 | Default remainder, create/delete/move validation, per-trade balance effects, Σ-wallets invariant, swap routing, undo reversal, cross-user guards |
| **Currency** | 15 | Factor math (USD/IRT/EUR cross-rate), formatting, cookie resolution, missing-rate fallback |
| **Security** | 12 | CSRF tokens, XSS escaping, password hashing, flash messages |
| **Preferences** | 14 | Theme, precision, P/L mode, source selection, defaults, fallbacks |
| **Formatting** | 11 | Big numbers, supply, form values, worthless zeros |
| **API Common** | 14 | Source list, normalization, priority, auto-switch, failure tracking |
| **Layout** | 7 | Head rendering, theme variants, auth pages, XSS, cache busting |

---

## Price Providers

CrypTracker fetches live data from three providers with automatic failover:

| Provider | API Key | Coin List Cache | Usage |
|---|---|---|---|
| **CoinMarketCap** | Required (`CMC_API_KEY` in `.env`) | 12 hours | Quotes, search, ID resolution |
| **CoinLore** | Not needed | 1 hour | Quotes, search (when cache warm) |
| **CoinGecko** | Not needed | 24 hours | Quotes, search (dedicated API) |

The user can choose a preferred provider. If it fails 3 times within 90 seconds, CrypTracker automatically switches to the next available provider and shows a toast notification.

---

## Development Effort

This project was built collaboratively between a human developer and AI (GitHub Copilot / Claude).

```
Developer  ███████████░░░░░░░░░░░░░░░░░░░░░░░░░  30%
AI         █████████████████████████░░░░░░░░░░░░  70%
```

**Developer** — Architecture, design decisions, domain logic direction, visual theme, feature specification, prompt engineering, bug fixing, implementing some sections (that AI was fucking up), testing and QA oversight.

**AI** — Code implementation, refactoring, modularization, test authoring, bug investigation, documentation.
