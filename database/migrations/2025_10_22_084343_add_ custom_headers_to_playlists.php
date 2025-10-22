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
            $table->jsonb('custom_headers')->nullable()->after('stream_profile_id');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->jsonb('custom_headers')->nullable()->after('stream_profile_id');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->jsonb('custom_headers')->nullable()->after('stream_profile_id');
        });
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->jsonb('custom_headers')->nullable()->after('stream_profile_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('custom_headers');
        });
        Schema::table('custom_playlists', function (Blueprint $table) {
            $table->dropColumn('custom_headers');
        });
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('custom_headers');
        });
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->dropColumn('custom_headers');
        });
    }
};
