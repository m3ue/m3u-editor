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
        Schema::table('dvr_settings', function (Blueprint $table) {
            $table->foreignId('playlist_id')->nullable()->change();

            $table->foreignId('custom_playlist_id')
                ->nullable()
                ->after('playlist_id')
                ->constrained('custom_playlists')
                ->cascadeOnDelete();

            $table->foreignId('merged_playlist_id')
                ->nullable()
                ->after('custom_playlist_id')
                ->constrained('merged_playlists')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table) {
            $table->dropForeign(['custom_playlist_id']);
            $table->dropColumn('custom_playlist_id');

            $table->dropForeign(['merged_playlist_id']);
            $table->dropColumn('merged_playlist_id');

            $table->foreignId('playlist_id')->nullable(false)->change();
        });
    }
};
