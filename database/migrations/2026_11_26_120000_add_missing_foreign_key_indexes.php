<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY can't run inside a transaction on Postgres.
     */
    public $withinTransaction = false;

    /**
     * Indexes for foreign key columns that have none.
     *
     * Neither Postgres nor SQLite indexes a foreign key column on its own, so every
     * cascade or SET NULL triggered by deleting a channel, custom playlist or scrubber
     * log scans the whole referencing table, and lookups by these columns do the same.
     * Large libraries hit this on every channel cleanup during sync and when deleting
     * a playlist or EPG.
     *
     * The pivot tables already have unique (channel_id|series_id, custom_playlist_id)
     * indexes, which only help when the lookup starts from the channel or series. The
     * custom_playlist_id-leading indexes serve playlist exports, counts and deletes.
     *
     * These tables are written continuously by the import and sync job chains, so on
     * Postgres the indexes are built CONCURRENTLY (->online()). An index left INVALID
     * by an interrupted concurrent build is dropped and rebuilt.
     */
    private const INDEXES = [
        'channel_failovers' => [
            'channel_failovers_channel_id_index' => ['channel_id'],
            'channel_failovers_channel_failover_id_index' => ['channel_failover_id'],
        ],
        'channel_scrubber_log_channels' => [
            'channel_scrubber_log_channels_channel_id_index' => ['channel_id'],
            'channel_scrubber_log_channels_channel_scrubber_log_id_index' => ['channel_scrubber_log_id'],
        ],
        'channel_custom_playlist' => [
            'channel_custom_playlist_custom_playlist_id_channel_id_index' => ['custom_playlist_id', 'channel_id'],
        ],
        'series_custom_playlist' => [
            'series_custom_playlist_custom_playlist_id_series_id_index' => ['custom_playlist_id', 'series_id'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $tableName => $indexes) {
            foreach ($indexes as $name => $columns) {
                $this->dropInvalidIndex($name);

                if (Schema::hasIndex($tableName, $name)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($columns, $name): void {
                    $table->index($columns, $name)->online();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $tableName => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if (! Schema::hasIndex($tableName, $name)) {
                    continue;
                }

                Schema::table($tableName, function (Blueprint $table) use ($name): void {
                    $table->dropIndex($name);
                });
            }
        }
    }

    private function dropInvalidIndex(string $name): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $isInvalid = DB::table('pg_index')
            ->join('pg_class', 'pg_class.oid', '=', 'pg_index.indexrelid')
            ->where('pg_class.relname', $name)
            ->where('pg_index.indisvalid', false)
            ->exists();

        if ($isInvalid) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS "'.$name.'"');
        }
    }
};
