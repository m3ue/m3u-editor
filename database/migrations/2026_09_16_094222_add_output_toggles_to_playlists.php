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
            $table->boolean('hdhr_enabled')->default(true);
            $table->boolean('m3u_enabled')->default(true);
            $table->boolean('xapi_enabled')->default(true);
            $table->boolean('xmltv_enabled')->default(true);
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->boolean('hdhr_enabled')->default(true);
            $table->boolean('m3u_enabled')->default(true);
            $table->boolean('xapi_enabled')->default(true);
            $table->boolean('xmltv_enabled')->default(true);
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->boolean('hdhr_enabled')->default(true);
            $table->boolean('m3u_enabled')->default(true);
            $table->boolean('xapi_enabled')->default(true);
            $table->boolean('xmltv_enabled')->default(true);
        });
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->boolean('hdhr_enabled')->default(true);
            $table->boolean('m3u_enabled')->default(true);
            $table->boolean('xapi_enabled')->default(true);
            $table->boolean('xmltv_enabled')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('hdhr_enabled');
            $table->dropColumn('m3u_enabled');
            $table->dropColumn('xapi_enabled');
            $table->dropColumn('xmltv_enabled');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('hdhr_enabled');
            $table->dropColumn('m3u_enabled');
            $table->dropColumn('xapi_enabled');
            $table->dropColumn('xmltv_enabled');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('hdhr_enabled');
            $table->dropColumn('m3u_enabled');
            $table->dropColumn('xapi_enabled');
            $table->dropColumn('xmltv_enabled');
        });
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->dropColumn('hdhr_enabled');
            $table->dropColumn('m3u_enabled');
            $table->dropColumn('xapi_enabled');
            $table->dropColumn('xmltv_enabled');
        });
    }
};
