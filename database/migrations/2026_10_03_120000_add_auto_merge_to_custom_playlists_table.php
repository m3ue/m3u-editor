<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->boolean('auto_merge_channels_enabled')->default(false)->after('processing_config');
            $table->jsonb('auto_merge_config')->nullable()->after('auto_merge_channels_enabled');
            $table->boolean('auto_merge_deactivate_failover')->default(false)->after('auto_merge_config');
        });
    }

    public function down(): void
    {
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn([
                'auto_merge_channels_enabled',
                'auto_merge_config',
                'auto_merge_deactivate_failover',
            ]);
        });
    }
};
