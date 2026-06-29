<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['playlists', 'custom_playlists', 'merged_playlists'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->json('dummy_epg_fallback_order')
                    ->nullable()
                    ->after('dummy_epg_category');
            });
        }
    }

    public function down(): void
    {
        foreach (['playlists', 'custom_playlists', 'merged_playlists'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('dummy_epg_fallback_order');
            });
        }
    }
};
