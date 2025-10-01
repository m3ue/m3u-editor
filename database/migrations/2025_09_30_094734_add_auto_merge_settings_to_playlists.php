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
            $table->boolean('auto_merge_channels_enabled')->default(false)->after('auto_sync');
            $table->boolean('auto_merge_deactivate_failover')->default(false)->after('auto_merge_channels_enabled');
            $table->jsonb('auto_merge_config')->nullable()->after('auto_merge_deactivate_failover');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn(['auto_merge_channels_enabled', 'auto_merge_deactivate_failover', 'auto_merge_config']);
        });
    }
};
