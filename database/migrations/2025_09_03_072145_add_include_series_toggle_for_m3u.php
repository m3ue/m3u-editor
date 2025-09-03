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
            $table->boolean('include_series_in_m3u')->default(false)
                ->nullable()
                ->after('enable_proxy');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->boolean('include_series_in_m3u')->default(false)
                ->nullable()
                ->after('enable_proxy');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->boolean('include_series_in_m3u')->default(false)
                ->nullable()
                ->after('enable_proxy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('include_series_in_m3u');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('include_series_in_m3u');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('include_series_in_m3u');
        });
    }
};
