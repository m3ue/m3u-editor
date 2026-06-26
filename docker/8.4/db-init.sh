#!/usr/bin/env bash
set -e

# If using local embedded Postgres, wait for it to be ready then configure roles/DB.
if [ "${POSTGRES_LOCAL_ENABLED}" = "true" ]; then
    echo "[db-init] Waiting for local Postgres on port ${PG_PORT}..."
    until su-exec "${WWWUSER}" pg_isready -h localhost -p "${PG_PORT}" --quiet; do
        sleep 1
    done
    echo "[db-init] Local Postgres is ready."

    # Ensure application role exists with the correct password
    su-exec "${WWWUSER}" psql \
        --quiet --tuples-only --no-align \
        -U postgres -h localhost -p "${PG_PORT}" <<-EOSQL
DO \$\$
BEGIN
  IF NOT EXISTS (
    SELECT FROM pg_catalog.pg_roles WHERE rolname = '${PG_USER}'
  ) THEN
    CREATE ROLE "${PG_USER}" LOGIN PASSWORD '${PG_PASSWORD}';
  ELSE
    ALTER ROLE "${PG_USER}" WITH PASSWORD '${PG_PASSWORD}';
  END IF;
  ALTER ROLE postgres WITH PASSWORD '${PG_PASSWORD}';
END
\$\$;
EOSQL

    # Create database if it does not yet exist; otherwise ensure correct ownership
    if su-exec "${WWWUSER}" psql -U postgres -h localhost -p "${PG_PORT}" \
        --quiet -tAc "SELECT 1 FROM pg_database WHERE datname='${PG_DATABASE}'" | grep -q 1; then
        echo "[db-init] Database '${PG_DATABASE}' exists, ensuring correct owner."
        su-exec "${WWWUSER}" psql -U postgres -h localhost -p "${PG_PORT}" --quiet <<-EOSQL
ALTER DATABASE "${PG_DATABASE}" OWNER TO "${PG_USER}";
\c "${PG_DATABASE}" postgres
DO \$\$
DECLARE
    tbl record;
BEGIN
    EXECUTE 'ALTER SCHEMA public OWNER TO "${PG_USER}"';
    FOR tbl IN
        SELECT table_schema, table_name
        FROM information_schema.tables
        WHERE table_schema = 'public'
    LOOP
        EXECUTE format(
            'ALTER TABLE %I.%I OWNER TO "${PG_USER}"',
            tbl.table_schema, tbl.table_name
        );
    END LOOP;
END
\$\$;
EOSQL
    else
        echo "[db-init] Creating database '${PG_DATABASE}'..."
        su-exec "${WWWUSER}" psql -U postgres -h localhost -p "${PG_PORT}" \
            --quiet -v ON_ERROR_STOP=1 <<-EOSQL
CREATE DATABASE "${PG_DATABASE}"
  OWNER = "${PG_USER}"
  ENCODING = 'UTF8'
  LC_COLLATE = 'C'
  LC_CTYPE = 'C.UTF-8'
  TEMPLATE = template0;
EOSQL
    fi

    # Create extensions
    su-exec "${WWWUSER}" psql --quiet \
        -U postgres -h localhost -p "${PG_PORT}" -d "${PG_DATABASE}" <<-EOSQL
CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
EOSQL

    echo "[db-init] Postgres role/database configuration complete."
fi

# Embeded DB instance is ready to accept connections at this point, 
# we can now run any necessary application-level initialization tasks.
# NOTE: If using an external database, we assume it is already configured correctly and ready to accept connections by the time this script runs.

# Note: start-container already removes bootstrap/cache/*.php and runs `php artisan optimize`
# with the correct runtime env (DB_CONNECTION, APP_KEY, etc.) before supervisord starts.
# No cache clearing is needed here — by the time db-init runs, the caches are already correct.

# Run Laravel migrations (or migrate from SQLite first if requested)
if [ "${SQLITE_MIGRATE:-false}" = "true" ] && [ "${DB_CONNECTION:-sqlite}" = "pgsql" ]; then
    # The migration script drops all Postgres tables, recreates them from SQLite's
    # schema via PRAGMA (bare columns, no constraints), imports all data including
    # the migrations table, then resets sequences. php artisan migrate then applies
    # only the migrations that post-date the user's SQLite install.
    echo "[db-init] SQLITE_MIGRATE=true — importing SQLite data into PostgreSQL..."
    /bin/bash /var/www/html/docker/8.4/migrate-sqlite-to-postgres.sh
    echo "[db-init] SQLite import complete."

    echo "[db-init] Running post-import migrations..."
    /usr/bin/php /var/www/html/artisan migrate --force
    echo "[db-init] Post-import migrations complete."
else
    echo "[db-init] Running migrations..."
    /usr/bin/php /var/www/html/artisan migrate --force
    echo "[db-init] Migrations complete."
fi
/usr/bin/php /var/www/html/artisan migrate --database=jobs --force

# Reset any stale sync processes left over from a previous run
echo "[db-init] Resetting sync process..."
/usr/bin/php /var/www/html/artisan app:reset-sync-process
echo "[db-init] Sync process reset."

# Cleanup any stale files (Playlist imports, EPG uploads and cache, etc.)
echo "[db-init] Cleaning up any stale files..."
php artisan app:cleanup-stale-files --force
echo "[db-init] Stale file cleanup complete."

# Discover and auto-trust official plugins bundled in this image
echo "[db-init] Syncing official plugins..."
/usr/bin/php /var/www/html/artisan plugins:sync-official
echo "[db-init] Official plugins synced."

# Rebuild application caches with runtime env vars (DB_CONNECTION, APP_KEY, etc.).
# Must run AFTER all migrations so route:cache can boot the fully-migrated app.
echo "[db-init] Rebuilding application caches..."
/usr/bin/php /var/www/html/artisan optimize --quiet 2>/dev/null || true
echo "[db-init] Application caches rebuilt."
