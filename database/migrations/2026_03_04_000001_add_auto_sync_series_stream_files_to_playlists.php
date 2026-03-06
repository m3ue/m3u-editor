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
        Schema::table('playlists', function (Blueprint $table) {
            $table->boolean('auto_sync_series_stream_files')->default(false)
                ->after('auto_fetch_series_metadata');
        });

        // Data migration: if auto_fetch_series_metadata is true, also enable stream file sync
        // so existing users are not surprised by the split in functionality.
        DB::table('playlists')
            ->where('auto_fetch_series_metadata', true)
            ->update(['auto_sync_series_stream_files' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('auto_sync_series_stream_files');
        });
    }
};
