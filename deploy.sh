#!/usr/bin/env bash
# Production deploy for the Tika Dost backend (run on the server as root):
#   bash deploy.sh
#
# Runs all artisan as the web user (www-data) so it never leaves root-owned
# files in storage/ or bootstrap/cache (which break php-fpm with cache
# "permission denied" / "the assistant is busy"), and rebuilds the Filament
# component cache so newly-added resources/pages aren't 404.
set -euo pipefail
cd "$(dirname "$0")"

WEB=www-data
art() { sudo -u "$WEB" env HOME=/tmp php artisan "$@"; }

echo "== pull =="
BEFORE=$(git rev-parse HEAD)
git pull --ff-only origin main
AFTER=$(git rev-parse HEAD)

if git diff --name-only "$BEFORE" "$AFTER" | grep -qE '^composer\.(json|lock)$'; then
  echo "== composer install (deps changed) =="
  sudo -u "$WEB" composer install --no-dev --optimize-autoloader --no-interaction
fi

echo "== migrate =="
art migrate --force

echo "== caches =="
art config:cache
art filament:optimize-clear
art filament:cache-components

echo "== fix ownership =="
chown -R "$WEB":"$WEB" storage bootstrap/cache database 2>/dev/null || true

echo "== reload php-fpm =="
for s in php8.3-fpm php8.2-fpm; do systemctl reload "$s" 2>/dev/null || true; done

echo "== deployed: $(git log --oneline -1) =="
