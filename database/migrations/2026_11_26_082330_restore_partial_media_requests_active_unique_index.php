<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'media_requests_active_unique';

    private const COLUMNS = ['playlist_auth_id', 'arr_integration_id', 'external_id', 'request_type'];

    /**
     * Installs converted from SQLite to Postgres before the conversion script learned about
     * partial indexes got media_requests_active_unique as a full UNIQUE constraint (or, when
     * request history had duplicates, no index at all). The full constraint blocks requesting
     * an item again after an earlier request was rejected or completed.
     *
     * Drop any non-partial unique key on these columns and recreate the partial index. Fresh
     * Postgres installs and SQLite installs already have the partial index, so this is a no-op
     * there. media_requests is small and not written by the import or sync jobs, so a plain
     * transactional rebuild is used: the old key is never gone without the new one in place.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $hasPartialIndex = false;

        foreach ($this->uniqueKeysOnColumns() as $name) {
            if ($name === self::INDEX && $this->isValidPartialIndex($name)) {
                $hasPartialIndex = true;

                continue;
            }

            $this->dropUniqueKey($name);
        }

        if ($hasPartialIndex) {
            return;
        }

        $this->rejectDuplicateActiveRequests();

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::INDEX
            .' ON media_requests ('.implode(', ', self::COLUMNS).')'
            ." WHERE status IN ('pending', 'approved')"
        );
    }

    /**
     * Nothing to reverse: the partial index is the intended schema, created by
     * 2026_07_16_174950_add_active_request_unique_index_to_media_requests_table.
     */
    public function down(): void {}

    /**
     * Names of the unique, non-primary keys covering exactly the active-request columns.
     *
     * @return list<string>
     */
    private function uniqueKeysOnColumns(): array
    {
        return collect(Schema::getIndexes('media_requests'))
            ->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'] && $index['columns'] === self::COLUMNS)
            ->pluck('name')
            ->values()
            ->all();
    }

    private function isValidPartialIndex(string $name): bool
    {
        return DB::table('pg_index')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_index.indexrelid')
            ->where('pg_class.relname', $name)
            ->whereNotNull('pg_index.indpred')
            ->where('pg_index.indisvalid', true)
            ->exists();
    }

    /**
     * Converted installs hold the key as a UNIQUE constraint, which DROP INDEX cannot remove.
     */
    private function dropUniqueKey(string $name): void
    {
        $isConstraint = DB::table('pg_constraint')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_constraint.conrelid')
            ->where('pg_class.relname', 'media_requests')
            ->where('pg_constraint.conname', $name)
            ->exists();

        DB::statement($isConstraint
            ? 'ALTER TABLE media_requests DROP CONSTRAINT "'.$name.'"'
            : 'DROP INDEX IF EXISTS "'.$name.'"');
    }

    /**
     * Installs where the full constraint failed to create had no uniqueness at all, so they
     * may hold duplicate active requests. Keep one per key (approved over pending, then the
     * most recent) and reject the rest, as the original migration did.
     */
    private function rejectDuplicateActiveRequests(): void
    {
        DB::table('media_requests')
            ->whereIn('status', ['pending', 'approved'])
            ->whereNotNull('playlist_auth_id')
            ->whereNotNull('external_id')
            ->orderByDesc('requested_at')
            ->orderBy('id')
            ->get(['id', ...self::COLUMNS, 'status'])
            ->groupBy(fn ($row) => implode(':', [
                $row->playlist_auth_id,
                $row->arr_integration_id,
                $row->external_id,
                $row->request_type,
            ]))
            ->each(function ($group) {
                $keep = $group->sortByDesc(fn ($row) => $row->status === 'approved')->first();

                $staleIds = $group->reject(fn ($row) => $row->id === $keep->id)->pluck('id');
                if ($staleIds->isNotEmpty()) {
                    DB::table('media_requests')
                        ->whereIn('id', $staleIds)
                        ->update(['status' => 'rejected', 'reviewed_at' => now()]);
                }
            });
    }
};
