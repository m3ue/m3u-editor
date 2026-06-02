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

# Run Laravel migrations
echo "[db-init] Running migrations..."
/usr/bin/php /var/www/html/artisan migrate --force
/usr/bin/php /var/www/html/artisan migrate --database=jobs --force
echo "[db-init] Migrations complete."

# Reset any stale sync processes left over from a previous run
echo "[db-init] Resetting sync process..."
/usr/bin/php /var/www/html/artisan app:reset-sync-process
echo "[db-init] Sync process reset."

# Discover and auto-trust official plugins bundled in this image
echo "[db-init] Syncing official plugins..."
/usr/bin/php /var/www/html/artisan plugins:sync-official
echo "[db-init] Official plugins synced."
