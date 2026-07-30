<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert dvr_recordings and dvr_recording_rules datetime columns from
 * TIMESTAMP (without time zone) to TIMESTAMP WITH TIME ZONE.
 *
 * Why: the existing columns store app-timezone wall-clock values ("13:46:00"
 * meaning 13:46 in app TZ) but carry no TZ marker. With a PostgreSQL session
 * running in UTC, every WHERE comparison — `where('scheduled_end', '<=', now())` or
 * otherwise — interprets the column as UTC and compares against the parameter
 * (which Eloquent binds as ISO 8601 with Z suffix when the parameter is a
 * Carbon). The mixed comparison shifts results by the app-TZ offset
 * (4 hours in summer for America/New_York), so a recording whose scheduled_end
 * is genuinely 4 hours in the future compares as 4 hours in the past and is
 * marked expired.
 *
 * Symptom history: recordings started by the scheduler were stopped 4-36
 * seconds later by the next tick of stopExpiredRecordings, because both ran
 * in the same minute and the WHERE clause shifted both sides of the comparison
 * to put scheduled_end in the past.
 *
 * After this migration, both sides of every comparison carry TZ info and
 * PostgreSQL normalises to UTC internally, so:
 *  - bind 'now' as Carbon → ISO 8601 Z → PG parses as UTC ✓
 *  - bind 'now' as wall-clock string → PG parses by session TZ, but both
 *    sides carry the same shift ✓
 *  - any new code that compares against these columns works correctly
 *    without per-call TZ gymnastics.
 *
 * The USING (col AT TIME ZONE '<app TZ>') clause tells PostgreSQL the
 * existing values are app-TZ wall-clock, so the conversion to UTC instant
 * preserves the original absolute time. Without this clause, PG would
 * assume the values were already UTC and shift them by the app TZ offset
 * (a silent data corruption equivalent to subtracting 4 hours from every
 * scheduled time).
 *
 * This migration is data-preserving: it does not change the absolute instant
 * any value represents, only changes the column type so PG knows what TZ to
 * assume when the value is interpreted.
 *
 * Scope: only dvr_recordings and dvr_recording_rules. Other tables with the
 * same anti-pattern (e.g. networks.broadcast_scheduled_start) are out of
 * scope and should be migrated separately if/when needed.
 *
 * Companion: the hotfix in DvrSchedulerService (formatting `now()` as a
 * wall-clock string) is kept as defense-in-depth — both Belt and Suspenders.
 * Either layer alone would be sufficient for correctness; both together
 * ensure no future contributor can re-introduce the bug by writing
 * `where('scheduled_end', '<=', now())` against this table.
 */
return new class extends Migration
{
    private const DVR_RECORDING_COLUMNS = [
        'scheduled_start',
        'scheduled_end',
        'actual_start',
        'actual_end',
        'programme_start',
        'programme_end',
        'created_at',
        'updated_at',
    ];

    private const DVR_RECORDING_RULE_COLUMNS = [
        'manual_start',
        'manual_end',
        'created_at',
        'updated_at',
    ];

    public function up(): void
    {
        if ($this->isUnsupportedDriver()) {
            return;
        }

        $appTz = $this->resolveAppTimezone();

        foreach (self::DVR_RECORDING_COLUMNS as $column) {
            $this->convertColumn('dvr_recordings', $column, $appTz, toTz: true);
        }

        foreach (self::DVR_RECORDING_RULE_COLUMNS as $column) {
            $this->convertColumn('dvr_recording_rules', $column, $appTz, toTz: true);
        }
    }

    public function down(): void
    {
        if ($this->isUnsupportedDriver()) {
            return;
        }

        $appTz = $this->resolveAppTimezone();

        foreach (self::DVR_RECORDING_COLUMNS as $column) {
            $this->convertColumn('dvr_recordings', $column, $appTz, toTz: false);
        }

        foreach (self::DVR_RECORDING_RULE_COLUMNS as $column) {
            $this->convertColumn('dvr_recording_rules', $column, $appTz, toTz: false);
        }
    }

    /**
     * SQLite has no concept of TIMESTAMP WITH TIME ZONE — every datetime column
     * is stored as ISO 8601 TEXT. The TZ comparison bug this migration fixes
     * is PostgreSQL-specific (session TZ vs column-without-TZ interaction), so
     * SQLite needs no migration.
     */
    private function isUnsupportedDriver(): bool
    {
        return DB::getDriverName() === 'sqlite';
    }

    private function convertColumn(string $table, string $column, string $appTz, bool $toTz): void
    {
        // SQL identifier validation — these are constant strings from this file
        // but be defensive in case the constants are edited later.
        if (! preg_match('/^[a-z_]+$/', $table) || ! preg_match('/^[a-z_]+$/', $column)) {
            throw new InvalidArgumentException("Invalid table or column name: {$table}.{$column}");
        }

        $targetType = $toTz ? 'timestamptz' : 'timestamp';

        // USING (...) AT TIME ZONE '<app TZ>' is the data-preserving conversion:
        //  up:   TIMESTAMP → TIMESTAMPTZ interprets the wall-clock value in app TZ
        //         and stores the equivalent UTC instant.
        //  down: TIMESTAMPTZ → TIMESTAMP interprets the UTC instant back to the
        //         app TZ wall-clock (since AT TIME ZONE on a timestamptz returns
        //         a timestamp).
        //
        // Without the AT TIME ZONE clause, PG would assume wall-clock values
        // were UTC during up, or treat the UTC instant as a UTC wall-clock during
        // down — either of which silently shifts every value by app TZ hours.
        $sql = sprintf(
            'ALTER TABLE %s ALTER COLUMN %s TYPE %s USING (%s AT TIME ZONE %s)',
            $table,
            $column,
            $targetType,
            $column,
            "'".addslashes($appTz)."'",
        );

        DB::statement($sql);
    }

    /**
     * Resolve the app timezone for the AT TIME ZONE conversion.
     *
     * Falls back to UTC if app.timezone is not configured — in that case the
     * current behaviour (treat values as UTC) is preserved exactly, since
     * (col AT TIME ZONE 'UTC') of a UTC value is the same value.
     */
    private function resolveAppTimezone(): string
    {
        return config('app.timezone', 'UTC');
    }
};
