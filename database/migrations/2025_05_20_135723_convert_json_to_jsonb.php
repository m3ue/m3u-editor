<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        // Use explicit USING casts — required when source column type is text (e.g. migrated from
        // SQLite). Automatic json→jsonb cast would work for fresh Postgres installs, but
        // text→jsonb requires USING to avoid a datatype mismatch error.
        $conversions = [
            'playlists' => ['import_prefs', 'xtream_config', 'xtream_status', 'short_urls'],
            'custom_playlists' => ['short_urls'],
            'merged_playlists' => ['short_urls'],
            'channels' => ['extvlcopt', 'kodidrop'],
            'post_processes' => ['metadata'],
            'playlist_sync_statuses' => ['sync_stats'],
            'playlist_sync_status_logs' => ['meta'],
        ];

        foreach ($conversions as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement(
                    "ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE jsonb USING \"{$column}\"::jsonb"
                );
            }
        }
    }
};
