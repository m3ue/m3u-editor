<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Postgres can't build or drop an index CONCURRENTLY inside a transaction.
     */
    public $withinTransaction = false;

    private const NEW_INDEX = 'idx_source_playlist_vod';

    /**
     * Key imported channels on (source_id, playlist_id, is_vod) instead of (source_id, playlist_id).
     *
     * Xtream rows use the provider stream_id as source_id for both live streams and movies, and
     * some panels number both from one sequence. Under the old key a live stream and a movie with
     * the same id shared a single row, so whichever import ran first won and the other was dropped.
     *
     * The new index is built before the old one is dropped, and on Postgres both steps run
     * CONCURRENTLY so the import and sync job chains writing to channels aren't blocked.
     * Existing rows always satisfy the wider key, so the build can't fail on current data.
     *
     * is_vod is backfilled first: it is nullable on databases converted from SQLite, and a NULL
     * never matches the upsert key, so those rows would be duplicated on the next sync.
     */
    public function up(): void
    {
        DB::table('channels')->whereNull('is_vod')->update(['is_vod' => false]);

        $oldIndexes = $this->uniqueIndexesOn(['source_id', 'playlist_id']);

        if (DB::getDriverName() === 'pgsql') {
            $this->createUniqueIndexConcurrently(self::NEW_INDEX, 'source_id, playlist_id, is_vod');
            foreach ($oldIndexes as $index) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS "'.$index.'"');
            }

            return;
        }

        Schema::table('channels', function (Blueprint $table) use ($oldIndexes) {
            $table->unique(['source_id', 'playlist_id', 'is_vod'], self::NEW_INDEX);
            foreach ($oldIndexes as $index) {
                $table->dropUnique($index);
            }
        });
    }

    /**
     * Restore the narrower key. This fails if a live stream and a movie now share a source_id,
     * since the old index can't hold both rows.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->createUniqueIndexConcurrently('idx_source_playlist', 'source_id, playlist_id');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NEW_INDEX);

            return;
        }

        Schema::table('channels', function (Blueprint $table) {
            $table->unique(['source_id', 'playlist_id'], 'idx_source_playlist');
            $table->dropUnique(self::NEW_INDEX);
        });
    }

    /**
     * A failed CONCURRENTLY build leaves an invalid index behind that IF NOT EXISTS would then
     * skip over, so drop any invalid leftover before building.
     */
    private function createUniqueIndexConcurrently(string $name, string $columns): void
    {
        $invalid = DB::table('pg_index')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_index.indexrelid')
            ->where('pg_class.relname', $name)
            ->where('pg_index.indisvalid', false)
            ->exists();
        if ($invalid) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }

        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON channels ('.$columns.')');
    }

    /**
     * Names of the unique indexes covering exactly these columns. The (source_id, playlist_id)
     * index isn't always called idx_source_playlist, e.g. databases converted from SQLite to
     * Postgres with pgloader carry a prefixed name.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function uniqueIndexesOn(array $columns): array
    {
        return collect(Schema::getIndexes('channels'))
            ->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'] && $index['columns'] === $columns)
            ->pluck('name')
            ->values()
            ->all();
    }
};
