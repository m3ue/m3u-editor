#!/usr/bin/env bash
set -euo pipefail

FLAG_FILE="${FLAG_FILE:-/var/www/config/database/sqlite_migrated.flag}"
SQLITE_DB="${SQLITE_DB:-/var/www/config/database/database.sqlite}"
BACKUP_DIR="${BACKUP_DIR:-/var/www/config/database/backups}"
MIGRATION_LOG_FILE="${MIGRATION_LOG_FILE:-/var/www/config/logs/sqlite_migration.log}"

# Derive script/app paths so this works in Docker and locally without extra config.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_ROOT="${APP_ROOT:-$(cd "${SCRIPT_DIR}/../.." && pwd)}"
MIGRATION_SCRIPT_DIR="${MIGRATION_SCRIPT_DIR:-${SCRIPT_DIR}}"
PHP_CMD="${PHP_CMD:-php}"
ARTISAN="${ARTISAN:-${APP_ROOT}/artisan}"

log() { echo "[sqlite-migrate] $*"; }

# Guard: already migrated
if [ -f "${FLAG_FILE}" ]; then
    log "Already migrated (flag file found). Skipping."
    exit 0
fi

# Guard: only run when explicitly requested with pgsql connection
if [ "${SQLITE_MIGRATE:-false}" != "true" ]; then
    exit 0
fi

if [ "${DB_CONNECTION:-sqlite}" != "pgsql" ]; then
    log "ERROR: SQLITE_MIGRATE=true but DB_CONNECTION is not pgsql. Aborting."
    exit 1
fi

# Guard: source database must exist and be non-empty
if [ ! -s "${SQLITE_DB}" ]; then
    log "SQLite database not found or empty. Skipping."
    exit 0
fi

log "Starting SQLite → PostgreSQL migration..."

# Step 1: Pre-migrate the SQLite database so its migrations table is fully up-to-date.
# This ensures that when we import the migrations table to Postgres, every migration
# (including FK-dropping, index-dropping, etc.) is already recorded as run.
# php artisan migrate will then only re-run the pgsql-specific ones.
log "Pre-migrating SQLite database to record all pending migrations..."
DB_CONNECTION=sqlite DB_DATABASE="${SQLITE_DB}" "${PHP_CMD}" "${ARTISAN}" migrate --force 2>&1 \
    | while IFS= read -r line; do log "  [sqlite] ${line}"; done
log "SQLite pre-migration complete."

# Step 2: Checkpoint WAL mode to merge sidecar files (.sqlite-wal, .sqlite-shm) into main file
sqlite3 "${SQLITE_DB}" "PRAGMA wal_checkpoint(TRUNCATE);" 2>/dev/null || true

# Step 3: Backup before touching anything
mkdir -p "${BACKUP_DIR}"
BACKUP_TS=$(date +%Y%m%d_%H%M%S)
cp "${SQLITE_DB}" "${BACKUP_DIR}/database.sqlite.${BACKUP_TS}.bak"
log "Backup created: ${BACKUP_DIR}/database.sqlite.${BACKUP_TS}.bak"

# Step 4: Run the Python migration script (drop Postgres tables, recreate from SQLite PRAGMA, import data)
MIGRATION_LOG_FILE="${MIGRATION_LOG_FILE}" \
    python3 "${MIGRATION_SCRIPT_DIR}/migrate-sqlite-to-postgres.py"

# Step 5: Write idempotency flag — persisted on the host volume so it survives container restarts
echo "Migrated on $(date) to ${DB_HOST:-localhost}:${DB_PORT:-5432}/${DB_DATABASE:-m3ue}" > "${FLAG_FILE}"
log "Flag written to ${FLAG_FILE}. Safe to leave SQLITE_MIGRATE=true — migration will not re-run."
