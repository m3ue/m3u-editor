<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEW_INDEX = 'idx_source_playlist_vod';

    /**
     * CREATE/DROP INDEX CONCURRENTLY can't run inside a transaction.
     */
    public $withinTransaction = false;

    /**
     * Key imported channels on (source_id, playlist_id, is_vod) instead of (source_id, playlist_id).
     *
     * Xtream rows use the provider stream_id as source_id for both live streams and movies, and
     * some panels number both from one sequence. Under the old key a live stream and a movie with
     * the same id shared a single row, so whichever import ran first won and the other was dropped.
     *
     * is_vod is backfilled first: it is nullable on databases converted from SQLite, and a NULL
     * never matches the upsert key, so those rows would be duplicated on the next sync.
     *
     * db-init runs migrations as a supervisord program alongside the queue workers and the
     * scheduler, so imports can write to channels while this runs. On Postgres the new key is
     * built CONCURRENTLY, which doesn't block those writes; an import upsert that runs before it
     * exists fails (it names the new key as its conflict target) and the sync is retried.
     */
    public function up(): void
    {
        DB::table('channels')->whereNull('is_vod')->update(['is_vod' => false]);

        if (DB::getDriverName() === 'pgsql') {
            // An interrupted concurrent build leaves an INVALID index that IF NOT EXISTS would keep
            $this->dropInvalidIndex(self::NEW_INDEX);
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "'.self::NEW_INDEX.'" ON channels (source_id, playlist_id, is_vod)');
        } else {
            Schema::table('channels', function (Blueprint $table) {
                $table->unique(['source_id', 'playlist_id', 'is_vod'], self::NEW_INDEX);
            });
        }

        foreach ($this->uniqueIndexesOn(['source_id', 'playlist_id']) as $index) {
            $this->dropUniqueIndex($index);
        }
    }

    /**
     * Restore the narrower key. This fails if a live stream and a movie now share a source_id,
     * since the old index can't hold both rows.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->unique(['source_id', 'playlist_id'], 'idx_source_playlist');
        });

        $this->dropUniqueIndex(self::NEW_INDEX);
    }

    /**
     * Schema-built databases hold the old key as a UNIQUE constraint, dropped with
     * DROP CONSTRAINT (a quick catalog change). Databases converted from SQLite via pgloader,
     * and the new key built above, are plain indexes, dropped CONCURRENTLY.
     */
    private function dropUniqueIndex(string $name): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('channels', fn (Blueprint $table) => $table->dropUnique($name));

            return;
        }

        $isConstraint = DB::table('pg_constraint')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_constraint.conrelid')
            ->where('pg_class.relname', 'channels')
            ->where('pg_constraint.conname', $name)
            ->exists();

        DB::statement($isConstraint
            ? 'ALTER TABLE channels DROP CONSTRAINT "'.$name.'"'
            : 'DROP INDEX CONCURRENTLY IF EXISTS "'.$name.'"');
    }

    private function dropInvalidIndex(string $name): void
    {
        $isInvalid = DB::table('pg_index')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_index.indexrelid')
            ->where('pg_class.relname', $name)
            ->where('pg_index.indisvalid', false)
            ->exists();

        if ($isInvalid) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS "'.$name.'"');
        }
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
