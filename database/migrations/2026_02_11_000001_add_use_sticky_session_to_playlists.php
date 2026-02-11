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
            $table->boolean('use_sticky_session')->default(false)
                ->nullable()
                ->after('strict_live_ts');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->boolean('use_sticky_session')->default(false)
                ->nullable()
                ->after('strict_live_ts');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->boolean('use_sticky_session')->default(false)
                ->nullable()
                ->after('strict_live_ts');
        });
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->boolean('use_sticky_session')->default(false)
                ->nullable()
                ->after('strict_live_ts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('use_sticky_session');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('use_sticky_session');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('use_sticky_session');
        });
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->dropColumn('use_sticky_session');
        });
    }
};
