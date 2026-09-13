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

# ── Refuse to serve a debug-enabled production container ───────────────────
# APP_DEBUG=true turns every unhandled error into a page carrying a full
# stack trace, the offending SQL, and (via Ignition's environment tab) other
# env vars -- DB credentials included -- to whoever's browser hit it. Railway
# is documented (docs/DEPLOY-RAILWAY.md) to set APP_DEBUG=false, but a
# document is not a guard: a variable left unset, typo'd, or copied from a
# local .env during a dashboard edit would ship exactly that page to the
# public internet with nothing here to notice. This container refuses to
# start rather than trust the variable was set correctly elsewhere. Only
# guards APP_ENV=production -- local/staging containers may legitimately run
# with debug on.
#
# Matches true/1/(true) -- not just the literal string "true" -- because
# config/app.php reads this as `(bool) env('APP_DEBUG', false)` and PHP casts
# the string "1" to true same as "true", so a variable set as APP_DEBUG=1
# enables debug mode in Laravel just as surely and must be caught the same way.
case "${APP_DEBUG}" in
    true | 1 | '(true)')
        if [ "${APP_ENV}" = "production" ]; then
            echo "!! refusing to start: APP_ENV=production with APP_DEBUG=${APP_DEBUG}"
            echo "!! this would serve stack traces, SQL, and environment variables to every visitor"
            echo "!! set APP_DEBUG=false (or unset it) in the Railway service variables"
            exit 1
        fi
        ;;
esac

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

# ── Scheduler ──────────────────────────────────────────────────────────────
# Kernel::schedule() runs forecast:generate nightly at 02:00, but nothing was
# ever invoking it -- FrankenPHP only serves HTTP, so that job silently never
# fired regardless of how long this container stayed up. schedule:work polls
# once a minute for the life of the process, same as a cron entry running
# `schedule:run` would. Backgrounded so it survives the `exec` below (which
# replaces this shell as PID 1 but leaves already-forked children in place).
php artisan schedule:work &

# ── Serve ──────────────────────────────────────────────────────────────────
# Railway injects $PORT. FrankenPHP reads $SERVER_NAME, so bind them together,
# falling back to 8080 for a plain `docker run`.
export SERVER_NAME=":${PORT:-8080}"
echo "==> serving on ${SERVER_NAME}"

exec frankenphp run --config /etc/caddy/Caddyfile
