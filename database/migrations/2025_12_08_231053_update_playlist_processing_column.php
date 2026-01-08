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
            $table->dropColumn('processing');
            $table->jsonb('processing')->default(json_encode([
                'live_processing' => false,
                'vod_processing' => false,
                'series_processing' => false,
            ]));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('processing');
            $table->boolean('processing')->default(false);
        });
    }
};
