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
        Schema::table('playlists', function (Blueprint $table) {
            $table->boolean('auto_probe_streams_only_unprobed')->default(true)->after('auto_probe_streams');
            $table->boolean('auto_probe_streams_include_disabled')->default(false)->after('auto_probe_streams_only_unprobed');
            $table->boolean('auto_probe_vod_streams_only_unprobed')->default(true)->after('auto_probe_vod_streams');
            $table->boolean('auto_probe_vod_streams_include_disabled')->default(false)->after('auto_probe_vod_streams_only_unprobed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn([
                'auto_probe_streams_only_unprobed',
                'auto_probe_streams_include_disabled',
                'auto_probe_vod_streams_only_unprobed',
                'auto_probe_vod_streams_include_disabled',
            ]);
        });
    }
};
