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
        foreach (['playlists', 'custom_playlists', 'merged_playlists'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->boolean('sort_by_channel_number')->after('force_channel_numbering')->default(false);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['playlists', 'custom_playlists', 'merged_playlists'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('sort_by_channel_number');
            });
        }
    }
};
