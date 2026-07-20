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
        Schema::table('playlist_request_settings', function (Blueprint $table) {
            $table->dropUnique(['playlist_id']);
            $table->foreignId('playlist_id')->nullable()->change();

            $table->foreignId('custom_playlist_id')
                ->nullable()
                ->unique()
                ->after('playlist_id')
                ->constrained('custom_playlists')
                ->cascadeOnDelete();

            $table->foreignId('merged_playlist_id')
                ->nullable()
                ->unique()
                ->after('custom_playlist_id')
                ->constrained('merged_playlists')
                ->cascadeOnDelete();
        });

        Schema::table('playlist_request_settings', function (Blueprint $table) {
            $table->unique('playlist_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlist_request_settings', function (Blueprint $table) {
            $table->dropUnique(['playlist_id']);

            $table->dropForeign(['custom_playlist_id']);
            $table->dropColumn('custom_playlist_id');

            $table->dropForeign(['merged_playlist_id']);
            $table->dropColumn('merged_playlist_id');

            $table->foreignId('playlist_id')->nullable(false)->change();
            $table->unique('playlist_id');
        });
    }
};
