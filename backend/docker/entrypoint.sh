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
# Every problem is reported at once, so .env can be fixed in one pass
if [ "${APP_ENV}" = "production" ]; then
    problems=0
    problem() {
        echo "[entrypoint] ERROR: $*" >&2
        problems=$((problems + 1))
    }
    [ -n "${APP_KEY}" ] || problem "APP_KEY is not set."
    case "${APP_DEBUG}" in
        true|1|TRUE|True) problem "APP_DEBUG must be false in production." ;;
    esac
    [ "${DB_USERNAME}" != "root" ] || problem "Production must use a dedicated, restricted DB user (DB_USERNAME=root is not allowed)."
    if [ "${#DB_PASSWORD}" -lt 16 ]; then
        problem "Production DB_PASSWORD must be at least 16 characters."
    fi
    case "${DB_PASSWORD}" in
        root|secret|password|changeme*) problem "Production DB_PASSWORD is too weak." ;;
    esac
    if [ "${problems}" -gt 0 ]; then
        fail "${problems} setting(s) above must be fixed in .env, then run: docker compose up -d --force-recreate"
    fi
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

# ── Database credentials ───────────────────────────────────────────────────────
# Fail with an actionable message instead of a bare migration stack trace. The usual cause is a .env
# changed after the mysql_data volume was initialised: MySQL creates the user only on first start.
if [ "${ROLE}" = "app" ]; then
    php -r '
        $dsn = sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "mysql", getenv("DB_PORT") ?: "3306", getenv("DB_DATABASE"));
        for ($try = 1; ; $try++) {
            try {
                new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_TIMEOUT => 5]);
                exit(0);
            } catch (PDOException $e) {
                $code = $e->errorInfo[1] ?? 0;
                // Access denied / unknown database will not fix themselves by waiting
                if (in_array($code, [1044, 1045, 1049], true) || $try >= 30) {
                    fwrite(STDERR, "[entrypoint] ERROR: cannot connect to MySQL as DB_USERNAME=".getenv("DB_USERNAME").": ".$e->getMessage()."\n");
                    exit(in_array($code, [1044, 1045, 1049], true) ? 2 : 1);
                }
                sleep(2);
            }
        }
    ' || {
        status=$?
        [ "${status}" -eq 2 ] && cat >&2 <<'EOF'
[entrypoint] The MySQL user/password in .env does not match the database. MySQL creates the user only when the
[entrypoint] mysql_data volume is first initialised, so later .env changes are not applied automatically.
[entrypoint] Sync the user with .env (keeps all data):
[entrypoint]   docker compose up -d mysql
[entrypoint]   docker compose exec mysql bash /docker-entrypoint-initdb.d/01-app-user.sh
[entrypoint]   docker compose up -d
EOF
        exit 1
    }
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
