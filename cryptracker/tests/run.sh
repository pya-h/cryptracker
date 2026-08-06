#!/usr/bin/env bash
#
# Run the CrypTracker test suite against BOTH storage backends.
#
#   • JSON   — always runs (pure PHP, no extensions needed).
#   • SQLite — runs when the pdo_sqlite driver is available; otherwise the
#              pass self-skips (exit 0) with a notice, so this script stays
#              green on a machine that only ships JSON support and gives full
#              dual-backend coverage anywhere pdo_sqlite is installed.
#
# Usage:   tests/run.sh            # both backends
#          PHP=php8.4 tests/run.sh # pin a specific PHP binary
#
set -u
DIR="$(cd "$(dirname "$0")" && pwd)"
PHP="${PHP:-php}"
status=0

echo "════════════════════════════════════════════════"
echo "  Backend 1/2: JSON"
echo "════════════════════════════════════════════════"
DB_BACKEND=json "$PHP" "$DIR/run.php" || status=1

echo
echo "════════════════════════════════════════════════"
echo "  Backend 2/2: SQLite"
echo "════════════════════════════════════════════════"
DB_BACKEND=sqlite "$PHP" "$DIR/run.php" || status=1

echo
if [ "$status" -eq 0 ]; then
    echo "✓ All backend runs passed."
else
    echo "✗ One or more backend runs failed."
fi
exit "$status"
