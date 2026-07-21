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
    #
    # TRGM_THRESHOLD tunes pg_trgm's `%` operator, which SimilaritySearchService
    # uses to widen the EPG candidate pool to typo/transliteration matches with
    # no shared literal substring. Setting it here via ALTER DATABASE (as the
    # postgres superuser, before PG_USER's ownership even matters) is what
    # makes this configurable at all - the app's own DB role can select the
    # threshold's current session GUC, but cannot ALTER DATABASE ... SET a
    # pg_trgm-defined parameter itself: Postgres requires either superuser or
    # an explicit GRANT SET ON PARAMETER for that, even for the database owner.
    su-exec "${WWWUSER}" psql --quiet \
        -U postgres -h localhost -p "${PG_PORT}" -d "${PG_DATABASE}" <<-EOSQL
CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
ALTER DATABASE "${PG_DATABASE}" SET pg_trgm.similarity_threshold = ${TRGM_THRESHOLD};
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

# Build the EPG candidate-recall trigram indexes now that migrations have
# created epg_channels (can't do this earlier - the table doesn't exist yet
# on a fresh install). Embedded Postgres only - an external database is the
# admin's responsibility per the note above, and SimilaritySearchService
# detects pg_trgm at runtime, so it degrades to its pre-trigram LIKE-only
# candidate search if these were never created.
#
# CONCURRENTLY avoids taking an exclusive lock on epg_channels while it
# builds - a plain CREATE INDEX would block any in-flight EPG import job
# trying to write to that table for the duration of the build. Unlike a
# Laravel migration (which wraps each migration in a transaction unless it
# opts out), a bare psql heredoc with no explicit BEGIN already runs each
# statement outside a transaction block, which CONCURRENTLY requires - no
# extra opt-out needed here.
if [ "${POSTGRES_LOCAL_ENABLED}" = "true" ]; then
    echo "[db-init] Ensuring EPG candidate-recall trigram indexes..."
    su-exec "${WWWUSER}" psql --quiet \
        -U postgres -h localhost -p "${PG_PORT}" -d "${PG_DATABASE}" <<-EOSQL
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_epg_channels_channel_id_trgm ON epg_channels USING gin (LOWER(channel_id) gin_trgm_ops);
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_epg_channels_name_trgm ON epg_channels USING gin (LOWER(name) gin_trgm_ops);
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_epg_channels_display_name_trgm ON epg_channels USING gin (LOWER(display_name) gin_trgm_ops);
ANALYZE epg_channels;
EOSQL
    echo "[db-init] Trigram indexes ready."
fi

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
