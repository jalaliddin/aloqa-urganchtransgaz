#!/bin/sh
# Container entrypoint for backend, queue and scheduler services.
# CONTAINER_ROLE: app (default) | queue | scheduler
set -e

ROLE="${CONTAINER_ROLE:-app}"
cd /var/www/html

fail() {
    echo "[entrypoint] ERROR: $*" >&2
    exit 1
}

# ── Production guard rails ─────────────────────────────────────────────────────
if [ "${APP_ENV}" = "production" ]; then
    [ -n "${APP_KEY}" ] || fail "APP_KEY is not set."
    case "${APP_DEBUG}" in
        true|1|TRUE|True) fail "APP_DEBUG must be false in production." ;;
    esac
    [ "${DB_USERNAME}" != "root" ] || fail "Production must use a dedicated, restricted DB user (DB_USERNAME=root is not allowed)."
    if [ "${#DB_PASSWORD}" -lt 16 ]; then
        fail "Production DB_PASSWORD must be at least 16 characters."
    fi
    case "${DB_PASSWORD}" in
        root|secret|password|changeme*) fail "Production DB_PASSWORD is too weak." ;;
    esac
fi

# ── Writable directories (named volume may start empty) ────────────────────────
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
         storage/framework/views storage/logs storage/fonts bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ missing, running composer install"
    composer install --no-interaction --prefer-dist
fi

if [ "${ROLE}" = "app" ]; then
    if [ "${APP_ENV}" = "production" ]; then
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
        php artisan event:cache
    else
        # Only file-based caches: the database cache store may not exist before migrations
        php artisan config:clear >/dev/null
        php artisan route:clear >/dev/null
        php artisan view:clear >/dev/null
    fi

    if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
        php artisan migrate --force --no-interaction
        # Roles/permissions are code-defined and idempotent — keep them in sync on every start
        php artisan db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force --no-interaction
    fi
fi

case "${ROLE}" in
    queue)
        exec su-exec www-data php artisan queue:work --sleep=3 --tries=3 --timeout=120 --no-interaction
        ;;
    scheduler)
        exec su-exec www-data php artisan schedule:work --no-interaction
        ;;
esac

exec "$@"
