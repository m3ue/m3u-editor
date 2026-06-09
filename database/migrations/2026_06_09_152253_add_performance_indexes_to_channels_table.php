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
        Schema::table('channels', function (Blueprint $table) {
            // Speeds up resolveEpgChannelScope() JOIN path (playlist_id + epg_channel_id filter + join to epg_channels)
            $table->index(['playlist_id', 'enabled', 'epg_channel_id'], 'channels_playlist_enabled_epg_idx');

            // Speeds up resolveEpgChannelScope() stream_id path (playlist_id + stream_id filter)
            $table->index(['playlist_id', 'enabled', 'stream_id'], 'channels_playlist_enabled_stream_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex('channels_playlist_enabled_epg_idx');
            $table->dropIndex('channels_playlist_enabled_stream_idx');
        });
    }
};
