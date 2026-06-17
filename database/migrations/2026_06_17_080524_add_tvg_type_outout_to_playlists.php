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
            $table->boolean('output_tvg_type')->default(false)->after('dummy_epg');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->boolean('output_tvg_type')->default(false)->after('dummy_epg');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->boolean('output_tvg_type')->default(false)->after('dummy_epg');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('output_tvg_type');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('output_tvg_type');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('output_tvg_type');
        });
    }
};
