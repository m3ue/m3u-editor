<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEW_INDEX = 'idx_source_playlist_vod';

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
     * Migrations run before supervisord starts the app and queue workers (docker/8.4/db-init.sh),
     * so nothing else is writing to channels while this runs.
     */
    public function up(): void
    {
        DB::table('channels')->whereNull('is_vod')->update(['is_vod' => false]);

        Schema::table('channels', function (Blueprint $table) {
            $table->unique(['source_id', 'playlist_id', 'is_vod'], self::NEW_INDEX);
        });

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
            $table->dropUnique(self::NEW_INDEX);
        });
    }

    /**
     * Schema-built databases hold the old key as a UNIQUE constraint, dropped with
     * DROP CONSTRAINT. Databases converted from SQLite via pgloader hold a plain index instead,
     * which DROP CONSTRAINT can't remove.
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
            : 'DROP INDEX "'.$name.'"');
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
