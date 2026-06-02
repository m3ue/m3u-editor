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
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->boolean('disable_m3u_xtream_format')->default(false)->after('auto_channel_increment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merged_playlists', function (Blueprint $table) {
            $table->dropColumn('disable_m3u_xtream_format');
        });
    }
};
