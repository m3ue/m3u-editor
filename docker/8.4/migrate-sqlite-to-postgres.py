#!/usr/bin/env python3
"""
SQLite → PostgreSQL one-time data migration.
Uses Python's sqlite3 stdlib + psql CLI (already in container).
Triggered by SQLITE_MIGRATE=true env var via migrate-sqlite-to-postgres.sh.

Flow:
  1. Drop all tables in Postgres (preserves extensions like pg_trgm, fuzzystrmatch)
  2. Recreate tables from SQLite's PRAGMA table_info — bare columns only, no NOT NULL /
     FK / index constraints. This avoids all schema-drift conflicts on import.
  3. Import all data including the `migrations` table, so php artisan migrate knows
     which migrations were already applied and only runs new ones.
  4. Reset sequences.
  (db-init.sh runs `php artisan migrate` afterwards to apply new migrations.)

Run manually inside the container:
  python3 /var/www/html/docker/8.4/migrate-sqlite-to-postgres.py
"""

import csv
import io
import os
import re
import sqlite3
import subprocess
from datetime import datetime

SQLITE_DB = os.getenv("SQLITE_DB", "/var/www/config/database/database.sqlite")
MIGRATION_LOG_FILE = os.getenv("MIGRATION_LOG_FILE", "/var/www/config/logs/sqlite-migration.log")

# Path to Laravel migration files — used to detect pgsql-only migrations that need to re-run.
# Defaults relative to this script's location: ../../database/migrations
_SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
MIGRATION_FILES_DIR = os.getenv(
    "MIGRATION_FILES_DIR",
    os.path.normpath(os.path.join(_SCRIPT_DIR, "..", "..", "database", "migrations")),
)

DB_HOST = os.getenv("DB_HOST", "localhost")
DB_PORT = os.getenv("DB_PORT", "5432")
DB_DATABASE = os.getenv("DB_DATABASE", "m3ue")
DB_USERNAME = os.getenv("DB_USERNAME", "m3ue")
DB_PASSWORD = os.getenv("DB_PASSWORD", "")

os.makedirs(os.path.dirname(MIGRATION_LOG_FILE), exist_ok=True)

# Ephemeral tables — skip schema creation and data import entirely.
# They are recreated fresh by the app on startup.
SKIP_TABLES = {
    "cache",
    "cache_locks",
    "sessions",
    "failed_jobs",
    "telescope_entries",
    "telescope_entries_tags",
    "telescope_monitoring",
}

_NULL = r"\N"


def log(msg: str) -> None:
    line = f"[sqlite-migrate] {msg}"
    print(line, flush=True)
    with open(MIGRATION_LOG_FILE, "a") as f:
        f.write(f"{datetime.now().isoformat()} {line}\n")


def psql_env() -> dict:
    return {**os.environ, "PGPASSWORD": DB_PASSWORD}


def psql_cmd() -> list:
    return ["psql", "-h", DB_HOST, "-p", DB_PORT, "-U", DB_USERNAME, "-d", DB_DATABASE]


def run_psql(sql: str) -> str:
    result = subprocess.run(
        psql_cmd() + ["-c", sql],
        env=psql_env(),
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        raise RuntimeError(f"psql failed: {result.stderr}")
    return result.stdout


def drop_all_tables() -> None:
    """Drop all user tables without touching extensions (pg_trgm, fuzzystrmatch, etc.)."""
    run_psql("""
        DO $$
        DECLARE t RECORD;
        BEGIN
            FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public'
            LOOP
                EXECUTE 'DROP TABLE IF EXISTS ' || quote_ident(t.tablename) || ' CASCADE';
            END LOOP;
        END $$;
    """)
    log("All Postgres tables dropped.")


def _map_sqlite_type(sqlite_type: str) -> tuple:
    """Map a SQLite declared column type to (postgres_type, is_boolean).

    We intentionally map to loose types here — migrations will ALTER columns
    to their final Postgres types (jsonb, enum-backed varchar with CHECK, etc.).
    """
    if not sqlite_type:
        return "text", False

    t = sqlite_type.strip().upper()

    if re.match(r"TINYINT\s*\(1\)", t):
        return "boolean", True
    if re.match(r"TINYINT", t):
        return "smallint", False
    if re.match(r"SMALLINT", t):
        return "smallint", False
    if re.match(r"MEDIUMINT", t):
        return "integer", False
    if re.match(r"(UNSIGNED\s+BIG\s+INTEGER|BIGINT)", t):
        return "bigint", False
    if re.match(r"INT(EGER)?", t):
        return "bigint", False
    if re.match(r"REAL|FLOAT|DOUBLE(\s+PRECISION)?", t):
        return "double precision", False
    if re.match(r"NUMERIC|DECIMAL", t):
        return sqlite_type.lower(), False  # preserve precision/scale
    if t == "BLOB":
        return "bytea", False
    if re.match(r"DATETIME|TIMESTAMP", t):
        return "timestamp(0) without time zone", False
    if t == "DATE":
        return "date", False
    if t == "TIME":
        return "time", False
    if re.match(r"TEXT|CLOB|LONGTEXT|MEDIUMTEXT|JSON|JSONB", t):
        return "text", False  # migrations convert json/jsonb columns to proper types
    if re.match(r"VARCHAR|CHAR", t):
        return sqlite_type.lower(), False  # preserve length specifier

    return "text", False


def _translate_default(value: object) -> str | None:
    """Translate a SQLite column default to a Postgres-compatible default expression."""
    if value is None:
        return None
    d = str(value).strip()
    upper = d.upper()
    if upper in ("CURRENT_TIMESTAMP", "CURRENT_DATE", "CURRENT_TIME", "NULL", "TRUE", "FALSE"):
        return d
    if d.startswith("'") or re.match(r"^-?\d+(\.\d+)?$", d):
        return d
    return None  # skip complex expressions that may not translate


def build_postgres_schema(conn: sqlite3.Connection) -> dict:
    """Create bare Postgres tables from SQLite's PRAGMA table_info.

    Intentionally omits NOT NULL, foreign keys, and indexes — all of those are
    re-applied by `php artisan migrate` after the import. This avoids every
    category of schema-drift conflict (nullable columns made NOT NULL in later
    migrations, data that predates a constraint, etc.).

    Returns {table_name: set_of_boolean_column_names} for use during COPY.
    """
    table_names = [
        row[0]
        for row in conn.execute(
            "SELECT name FROM sqlite_master "
            "WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )
        if row[0] not in SKIP_TABLES
    ]

    bool_cols: dict = {}

    for table_name in table_names:
        cols = conn.execute(f'PRAGMA table_info("{table_name}")').fetchall()
        if not cols:
            continue

        table_bool_cols: set = set()
        col_defs = []

        for col in cols:
            # PRAGMA columns: cid, name, type, notnull, dflt_value, pk
            col_name = col[1]
            col_type_raw = col[2] or ""
            default_raw = col[4]
            is_pk = bool(col[5])

            pg_type, is_bool = _map_sqlite_type(col_type_raw)
            if is_bool:
                table_bool_cols.add(col_name)

            # Integer PKs need a sequence so Laravel can INSERT without supplying id.
            # Upgrade bigint/integer/smallint PKs to their serial equivalents.
            if is_pk and pg_type in ("bigint", "integer", "smallint"):
                pg_type = {"bigint": "bigserial", "integer": "serial", "smallint": "smallserial"}[pg_type]

            col_def = f'  "{col_name}" {pg_type}'
            if is_pk:
                col_def += " PRIMARY KEY"

            pg_default = _translate_default(default_raw)
            if pg_default is not None:
                col_def += f" DEFAULT {pg_default}"

            col_defs.append(col_def)

        create_sql = (
            f'CREATE TABLE IF NOT EXISTS "{table_name}" (\n'
            + ",\n".join(col_defs)
            + "\n)"
        )

        try:
            run_psql(create_sql)
        except RuntimeError as e:
            log(f"  Warning: failed to create table {table_name}: {e}")

        bool_cols[table_name] = table_bool_cols

    return bool_cols


def _csv_val(val: object, is_boolean: bool = False) -> object:
    """Normalise a SQLite value for Postgres COPY.

    - None / empty string → null marker (\\N)
    - Boolean columns: 0/1 → false/true (Postgres COPY rejects 0/1 for boolean)
    """
    if val is None or val == "":
        return _NULL
    if is_boolean:
        return "true" if val else "false"
    return val


def copy_table(conn: sqlite3.Connection, table_name: str, columns: list, bool_col_names: set) -> int:
    """Stream table data from SQLite into Postgres via COPY FROM STDIN."""
    col_list = ", ".join(f'"{c}"' for c in columns)
    bool_indices = {i for i, c in enumerate(columns) if c in bool_col_names}

    # session_replication_role must be set per-connection — each psql subprocess
    # is a new session, so a value set in a prior call does not carry over.
    psql_copy_cmd = psql_cmd() + [
        "-c", "SET session_replication_role = 'replica'",
        "-c",
        (
            f'COPY "{table_name}" ({col_list}) FROM STDIN '
            f"WITH (FORMAT csv, NULL '\\N', QUOTE '\"', ESCAPE '\"')"
        ),
    ]

    proc = subprocess.Popen(
        psql_copy_cmd,
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=psql_env(),
    )
    assert proc.stdin is not None

    cursor = conn.execute(f'SELECT {col_list} FROM "{table_name}"')
    buf = io.StringIO()
    writer = csv.writer(buf, quoting=csv.QUOTE_MINIMAL)

    row_count = 0
    for row in cursor:
        writer.writerow([_csv_val(val, i in bool_indices) for i, val in enumerate(row)])
        row_count += 1

        if row_count % 10000 == 0:
            proc.stdin.write(buf.getvalue().encode())
            buf = io.StringIO()
            writer = csv.writer(buf, quoting=csv.QUOTE_MINIMAL)

    remaining = buf.getvalue()
    if remaining:
        proc.stdin.write(remaining.encode())

    _, stderr = proc.communicate()

    if proc.returncode != 0:
        raise RuntimeError(f"COPY failed for {table_name}: {stderr.decode()}")

    return row_count


def create_unique_indexes(conn: sqlite3.Connection) -> None:
    """Create unique indexes in Postgres from SQLite's PRAGMA index_list.

    Called after COPY so the import doesn't fight constraints, but before the app
    starts so ON CONFLICT / uniqueness checks work at runtime.
    Skips primary-key indexes (already handled as PRIMARY KEY in the schema).
    """
    table_names = [
        row[0]
        for row in conn.execute(
            "SELECT name FROM sqlite_master "
            "WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )
        if row[0] not in SKIP_TABLES
    ]

    created = 0
    for table_name in table_names:
        indexes = conn.execute(f"PRAGMA index_list('{table_name}')").fetchall()
        for idx in indexes:
            # PRAGMA index_list cols: seq, name, unique, origin, partial
            idx_name = idx[1]
            is_unique = idx[2]
            origin = idx[3]  # 'c'=CREATE INDEX, 'u'=UNIQUE constraint, 'pk'=primary key

            if not is_unique or origin == "pk":
                continue

            # PRAGMA index_info cols: seqno, cid, name
            cols = conn.execute(f"PRAGMA index_info('{idx_name}')").fetchall()
            if not cols:
                continue

            col_names = ", ".join(f'"{col[2]}"' for col in cols)
            try:
                run_psql(
                    f'CREATE UNIQUE INDEX IF NOT EXISTS "{idx_name}" ON "{table_name}" ({col_names})'
                )
                created += 1
            except RuntimeError as e:
                log(f"  Warning: could not create unique index {idx_name} on {table_name}: {e}")

    log(f"Created {created} unique index(es) from SQLite schema.")


def remove_pgsql_only_migrations() -> None:
    """Delete migration records for pgsql-only migrations so php artisan migrate re-runs them.

    These migrations guard with `config('database.default') !== 'pgsql'` and return early on
    SQLite — but Laravel still records them as run. After import to Postgres the bare schema has
    text columns where jsonb is expected, so these migrations must actually execute.
    """
    if not os.path.isdir(MIGRATION_FILES_DIR):
        log(f"Migration files dir not found at {MIGRATION_FILES_DIR}. Skipping pgsql-only cleanup.")
        return

    to_remove = []
    for filename in sorted(os.listdir(MIGRATION_FILES_DIR)):
        if not filename.endswith(".php"):
            continue
        filepath = os.path.join(MIGRATION_FILES_DIR, filename)
        try:
            with open(filepath, "r", encoding="utf-8") as fh:
                content = fh.read()
        except OSError:
            continue
        if (
            "config('database.default') !== 'pgsql'" in content
            or 'config("database.default") !== "pgsql"' in content
        ):
            to_remove.append(filename[:-4])  # strip .php extension

    if not to_remove:
        log("No pgsql-only migrations found to clean up.")
        return

    placeholders = ", ".join(f"'{n}'" for n in to_remove)
    run_psql(f"DELETE FROM migrations WHERE migration IN ({placeholders});")
    log(f"Removed {len(to_remove)} pgsql-only migration record(s) so they re-run after import.")
    for name in to_remove:
        log(f"  - {name}")


def reset_sequences() -> None:
    """Reset all Postgres sequences to MAX(id) of their linked tables.

    Required after bulk COPY — sequences still sit at 1 and would conflict
    on the next INSERT without this step.
    """
    run_psql("""
        DO $$
        DECLARE seq RECORD;
        BEGIN
            FOR seq IN
                SELECT t.relname AS table_name, a.attname AS column_name, s.relname AS seq_name
                FROM pg_class s
                JOIN pg_depend d ON d.objid = s.oid
                JOIN pg_class t ON d.refobjid = t.oid
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = d.refobjsubid
                WHERE s.relkind = 'S'
            LOOP
                EXECUTE format(
                    'SELECT setval(%L, COALESCE((SELECT MAX(%I) FROM %I), 1))',
                    seq.seq_name, seq.column_name, seq.table_name
                );
            END LOOP;
        END $$;
    """)


def main() -> None:
    drop_all_tables()

    conn = sqlite3.connect(SQLITE_DB)
    conn.row_factory = sqlite3.Row

    log("Building Postgres schema from SQLite PRAGMA...")
    bool_cols = build_postgres_schema(conn)
    log(f"Created {len(bool_cols)} tables.")

    tables = [
        row[0]
        for row in conn.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )
        if row[0] not in SKIP_TABLES
    ]

    log(f"Found {len(tables)} tables to import.")
    total_rows = 0

    for table in tables:
        try:
            cols = [row[1] for row in conn.execute(f'PRAGMA table_info("{table}")')]
            if not cols:
                log(f"  Skipping {table} (no columns)")
                continue

            count = copy_table(conn, table, cols, bool_cols.get(table, set()))
            total_rows += count
            log(f"  Imported {table}: {count} rows")
        except Exception as e:
            log(f"  ERROR importing {table}: {e}")
            conn.close()
            raise

    log("Creating unique indexes from SQLite schema...")
    create_unique_indexes(conn)

    conn.close()

    log("Removing pgsql-only migration records so they re-run...")
    remove_pgsql_only_migrations()

    log("Resetting sequences...")
    reset_sequences()

    log(f"Done. {total_rows} rows imported across {len(tables)} tables.")
    log("Run `php artisan migrate` to apply any migrations newer than your SQLite install.")


if __name__ == "__main__":
    main()
