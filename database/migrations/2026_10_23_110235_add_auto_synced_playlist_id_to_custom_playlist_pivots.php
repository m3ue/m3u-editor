<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinguishes a pivot row AutoSyncGroupsToCustomPlaylist added from a manual
     * "Add to Custom Playlist" one, so its full-sync cleanup can remove exactly what
     * it previously added instead of guessing from the item's current group (issue #1695).
     * Null means manual, or added before this column existed.
     */
    public function up(): void
    {
        Schema::table('channel_custom_playlist', function (Blueprint $table) {
            $table->unsignedBigInteger('auto_synced_playlist_id')->nullable()->after('custom_playlist_id');
        });

        Schema::table('series_custom_playlist', function (Blueprint $table) {
            $table->unsignedBigInteger('auto_synced_playlist_id')->nullable()->after('custom_playlist_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_custom_playlist', function (Blueprint $table) {
            $table->dropColumn('auto_synced_playlist_id');
        });

        Schema::table('series_custom_playlist', function (Blueprint $table) {
            $table->dropColumn('auto_synced_playlist_id');
        });
    }
};
