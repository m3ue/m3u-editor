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
        foreach (['custom_playlists', 'merged_playlists'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedTinyInteger('dummy_epg_days')->nullable()->after('dummy_epg_length');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['custom_playlists', 'merged_playlists'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('dummy_epg_days');
            });
        }
    }
};
