<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Channels: VOD/Live filtering by user (XtreamApiController, PlaylistController)
        Schema::table('channels', function (Blueprint $table) {
            $table->index(['user_id', 'is_vod', 'enabled'], 'idx_channels_user_vod_enabled');
            $table->index(['playlist_id', 'enabled', 'is_vod'], 'idx_channels_playlist_enabled_vod');
            $table->index('custom_playlist_id', 'idx_channels_custom_playlist_id');
        });

        // Groups: queried by (user_id, playlist_id), (playlist_id, type), (playlist_id, enabled)
        Schema::table('groups', function (Blueprint $table) {
            $table->index(['user_id', 'playlist_id'], 'idx_groups_user_playlist');
            $table->index(['playlist_id', 'type'], 'idx_groups_playlist_type');
            $table->index(['playlist_id', 'enabled'], 'idx_groups_playlist_enabled');
        });

        // Series (42K rows): queried by (user_id, playlist_id), (category_id)
        Schema::table('series', function (Blueprint $table) {
            $table->index(['user_id', 'playlist_id'], 'idx_series_user_playlist');
            $table->index('category_id', 'idx_series_category_id');
        });

        // EPG channels (72K rows): relationship lookups by epg_id
        Schema::table('epg_channels', function (Blueprint $table) {
            $table->index('epg_id', 'idx_epg_channels_epg_id');
        });

        // Playlist sync status logs (315K rows): all queries filter by (sync_id, type, status)
        Schema::table('playlist_sync_status_logs', function (Blueprint $table) {
            $table->index(
                ['playlist_sync_status_id', 'type', 'status'],
                'idx_sync_logs_status_type'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex('idx_channels_user_vod_enabled');
            $table->dropIndex('idx_channels_playlist_enabled_vod');
            $table->dropIndex('idx_channels_custom_playlist_id');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex('idx_groups_user_playlist');
            $table->dropIndex('idx_groups_playlist_type');
            $table->dropIndex('idx_groups_playlist_enabled');
        });

        Schema::table('series', function (Blueprint $table) {
            $table->dropIndex('idx_series_user_playlist');
            $table->dropIndex('idx_series_category_id');
        });

        Schema::table('epg_channels', function (Blueprint $table) {
            $table->dropIndex('idx_epg_channels_epg_id');
        });

        Schema::table('playlist_sync_status_logs', function (Blueprint $table) {
            $table->dropIndex('idx_sync_logs_status_type');
        });
    }
};
