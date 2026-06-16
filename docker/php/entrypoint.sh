#!/usr/bin/env bash
set -euo pipefail

# Ensure vendor dir exists when source is bind-mounted over the image.
# Only the primary container (RUN_MIGRATIONS=1) installs; others wait so two
# containers never run composer install into the same shared source at once.
if [ ! -f vendor/autoload_runtime.php ]; then
    if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
        echo "[entrypoint] vendor/ missing — running composer install..."
        composer install --no-interaction --no-progress --prefer-dist
    else
        echo "[entrypoint] Waiting for vendor/ to be installed by the primary container..."
        until [ -f vendor/autoload_runtime.php ]; do
            sleep 3
        done
    fi
fi

# Wait for the database to accept connections.
if [ -n "${DATABASE_URL:-}" ]; then
    echo "[entrypoint] Waiting for database..."
    tries=0
    until php bin/console dbal:run-sql "SELECT 1" >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "[entrypoint] Database not reachable after 30 tries — continuing anyway."
            break
        fi
        sleep 2
    done
fi

# Run migrations + seed the dashboard admin on the primary container only.
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    echo "[entrypoint] Running database migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

    echo "[entrypoint] Ensuring dashboard admin user..."
    php bin/console app:ensure-admin || true
fi

# Make sure runtime dirs are writable.
mkdir -p var/cache var/log
chown -R www-data:www-data var || true

exec "$@"
