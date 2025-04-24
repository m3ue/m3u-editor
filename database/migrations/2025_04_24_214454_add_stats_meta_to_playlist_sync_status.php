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
        Schema::table('playlist_sync_statuses', function (Blueprint $table) {
            $table->json('sync_stats')->nullable()->after('playlist_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlist_sync_statuses', function (Blueprint $table) {
            $table->dropColumn('sync_stats');
        });
    }
};
