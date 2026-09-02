#!/bin/sh
# Boot sequence for a container deploy.
#
# Deliberately NOT `php artisan optimize`. That runs route:cache, and caching
# the route collection on this app drops GET from `/`, which then answers 405
# and locks everyone out at the login redirect -- see CLAUDE.md, "Never run
# php artisan route:cache". config:cache and view:cache are safe, and are what
# this does instead.
set -e

echo "==> REMEDI boot"

# ── Wait for the database ──────────────────────────────────────────────────
# Railway starts the app and MySQL together, so the first migrate can land
# before the database accepts connections. Twelve tries at five seconds is a
# minute, longer than a cold MySQL takes to come up.
tries=0
until php -r 'new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));' 2>/dev/null; do
    tries=$((tries + 1))
    if [ "$tries" -ge 12 ]; then
        echo "!! database unreachable at ${DB_HOST}:${DB_PORT:-3306} after 60s"
        exit 1
    fi
    echo "   waiting for ${DB_HOST}:${DB_PORT:-3306} ($tries/12)"
    sleep 5
done
echo "==> database is up"

# ── Schema ─────────────────────────────────────────────────────────────────
# --force because there is no TTY to confirm at. Migrations only: SEEDING IS A
# SEPARATE, MANUAL STEP. The seeders read a 15 MB CSV and insert ~114k history
# rows; running that on every deploy would either cost minutes or violate the
# (product_sku, sale_date) unique constraint on the second attempt.
php artisan migrate --force

# ── Caches ─────────────────────────────────────────────────────────────────
# config and view only. Cleared first so a redeploy cannot serve the previous
# image's compiled config.
php artisan config:clear
php artisan view:clear
php artisan config:cache
php artisan view:cache

# ── Serve ──────────────────────────────────────────────────────────────────
# Railway injects $PORT. FrankenPHP reads $SERVER_NAME, so bind them together,
# falling back to 8080 for a plain `docker run`.
export SERVER_NAME=":${PORT:-8080}"
echo "==> serving on ${SERVER_NAME}"

exec frankenphp run --config /etc/caddy/Caddyfile
