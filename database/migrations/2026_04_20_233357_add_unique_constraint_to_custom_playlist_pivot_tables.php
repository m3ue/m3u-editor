<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Remove any duplicate rows before adding the unique constraints.
        // PostgreSQL uses ctid as a physical row identifier; SQLite uses rowid.
        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($isPgsql) {
            DB::statement('
                DELETE FROM channel_custom_playlist a
                USING channel_custom_playlist b
                WHERE a.ctid < b.ctid
                  AND a.channel_id = b.channel_id
                  AND a.custom_playlist_id = b.custom_playlist_id
            ');
            DB::statement('
                DELETE FROM series_custom_playlist a
                USING series_custom_playlist b
                WHERE a.ctid < b.ctid
                  AND a.series_id = b.series_id
                  AND a.custom_playlist_id = b.custom_playlist_id
            ');
        } else {
            DB::statement('
                DELETE FROM channel_custom_playlist
                WHERE rowid NOT IN (
                    SELECT MAX(rowid) FROM channel_custom_playlist
                    GROUP BY channel_id, custom_playlist_id
                )
            ');
            DB::statement('
                DELETE FROM series_custom_playlist
                WHERE rowid NOT IN (
                    SELECT MAX(rowid) FROM series_custom_playlist
                    GROUP BY series_id, custom_playlist_id
                )
            ');
        }

        Schema::table('channel_custom_playlist', function (Blueprint $table) {
            $table->unique(['channel_id', 'custom_playlist_id']);
        });

        Schema::table('series_custom_playlist', function (Blueprint $table) {
            $table->unique(['series_id', 'custom_playlist_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_custom_playlist', function (Blueprint $table) {
            $table->dropUnique(['channel_id', 'custom_playlist_id']);
        });

        Schema::table('series_custom_playlist', function (Blueprint $table) {
            $table->dropUnique(['series_id', 'custom_playlist_id']);
        });
    }
};
