#!/usr/bin/env bash
set -euo pipefail

APP="ig-live-score-engine"

log() { echo -e "\033[1;32m[+]\033[0m $1"; }
err() { echo -e "\033[1;31m[!]\033[0m $1" >&2; }

# --- Pre-flight checks ---
for cmd in composer php; do
    if ! command -v "$cmd" &>/dev/null; then
        err "'$cmd' is required but not found in PATH."
        exit 1
    fi
done

log "Creating Laravel Zero project: ${APP}"
composer create-project laravel-zero/laravel-zero "${APP}"
cd "${APP}"

log "Installing dependencies..."
composer require laravel/dusk symfony/process

php application dusk:install || true

# --- Create directories ---
mkdir -p storage public

# --- Initialise state files ---
echo '{}' > storage/scores.json
echo '{"ended":false}' > storage/round.json
chmod 0666 storage/scores.json storage/round.json

# --- Copy project source files ---
cp -r ../ig-live-score-engine-src/app/Commands app/Commands
cp ../ig-live-score-engine-src/public/* public/

log "System ready."
echo ""
echo "  Run Engine:"
echo "    php application run https://www.instagram.com/USERNAME/live/ 'correct answer'"
echo ""
echo "  Start built-in web server:"
echo "    php -S 0.0.0.0:8000 -t public"
echo ""
echo "  Admin Panel:        http://127.0.0.1:8000/admin.php"
echo "  Fullscreen Overlay: http://127.0.0.1:8000/overlay.php"
echo "  Scores API:         http://127.0.0.1:8000/api.php"
echo ""
