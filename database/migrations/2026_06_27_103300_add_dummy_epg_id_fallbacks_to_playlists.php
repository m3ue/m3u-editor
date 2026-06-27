<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['playlists', 'custom_playlists', 'merged_playlists'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->json('dummy_epg_id_fallbacks')
                    ->nullable()
                    ->after('dummy_epg');
            });
        }
    }

    public function down(): void
    {
        foreach (['playlists', 'custom_playlists', 'merged_playlists'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('dummy_epg_id_fallbacks');
            });
        }
    }
};
