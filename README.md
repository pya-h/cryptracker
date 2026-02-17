# CrypTracker

A pure PHP crypto portfolio tracker with authentication, buy/sell recording, and profit/loss calculation.

## Features

- **User authentication** — Register/login with bcrypt-hashed passwords, session-based auth with rate limiting
- **Token tracking** — Search and add any cryptocurrency via CoinLore API (5000+ coins)
- **Buy/Sell recording** — Record trades with price per unit
- **P/L calculations** — Weighted-average cost basis, realized & unrealized P/L
- **Live prices** — Real-time prices from CoinLore (free, no API key needed)
- **Security** — CSRF protection, XSS escaping, session fixation prevention, security headers
- **Responsive UI** — Dark theme with glassmorphism, animations, and mobile support

## Requirements

- PHP 8.0+ with `json` and `session` extensions
- No database server needed (uses JSON file storage)
- No curl needed (uses `file_get_contents`)

## Quick Start

```bash
# 1. Clone and enter the project
cd cryptracker

# 2. Copy environment config
cp .env.example .env

# 3. Start the development server
php -S 0.0.0.0:8080

# 4. Open http://localhost:8080 in your browser
```

## Project Structure

```
cryptracker/
├── includes/           # Core PHP logic (not directly accessible)
│   ├── config.php      # Environment loader, session bootstrap
│   ├── db.php          # JSON file database layer
│   ├── auth.php        # Authentication (register, login, logout)
│   ├── api.php         # CoinLore/CMC API wrapper with caching
│   └── helpers.php     # CSRF, formatting, layout helpers
├── assets/
│   ├── style.css       # Dark theme with animations & glassmorphism
│   └── app.js          # Token search, interactive UI
├── tests/              # Test suite
│   ├── run.php         # Test runner
│   ├── TestDb.php      # Database layer tests
│   ├── TestAuth.php    # Authentication tests
│   ├── TestPL.php      # Profit/loss calculation tests
│   └── TestSecurity.php # Security & helper tests
├── database/           # Auto-created, gitignored JSON data files
├── index.php           # Dashboard
├── login.php           # Login page
├── register.php        # Registration page
├── logout.php          # Logout handler
├── token.php           # Single token detail + trade forms
├── transaction.php     # Buy/sell POST handler
├── add_token.php       # Add token POST handler
├── remove_token.php    # Remove token POST handler
├── search_tokens.php   # AJAX search endpoint
├── .env.example        # Environment config template
└── .gitignore
```

## Running Tests

```bash
php tests/run.php
```

Tests use an isolated temporary data directory and clean up after themselves. The suite covers:

- **Database** (8 tests) — CRUD operations, cascade deletes, field whitelisting, user isolation
- **Authentication** (7 tests) — Registration validation, duplicate prevention, login, rate limiting
- **P/L Calculations** (7 tests) — Profits, losses, weighted averages, partial sells, break-even
- **Security** (12 tests) — CSRF tokens, XSS escaping, password hashing, flash messages, formatting

## Security Measures

| Feature | Implementation |
|---|---|
| Password hashing | bcrypt with cost 12 |
| CSRF protection | Per-session tokens on all POST forms |
| XSS prevention | `htmlspecialchars()` with `ENT_QUOTES \| ENT_HTML5` everywhere |
| Session fixation | `session_regenerate_id(true)` on login/register |
| Rate limiting | 5 login attempts per 60 seconds |
| Security headers | X-Frame-Options, X-Content-Type-Options, X-XSS-Protection |
| Input validation | Username regex, email filter, type whitelisting |
| Atomic writes | JSON files written via temp file + rename |

## API

Uses **CoinLore** (free, no key) as primary API with **CoinMarketCap** as optional fallback. Set `CMC_API_KEY` in `.env` if you want the fallback.
