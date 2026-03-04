<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            // Tracks whether the user explicitly wants the broadcast running.
            // broadcast_enabled = feature toggle (can the network broadcast?)
            // broadcast_requested = user intent (should it be running now?)
            $table->boolean('broadcast_requested')->default(false)->after('broadcast_enabled');
        });

        // Preserve existing behavior: if broadcast_enabled was true, set broadcast_requested to true
        // so existing networks don't suddenly stop being able to auto-start
        DB::table('networks')
            ->where('broadcast_enabled', true)
            ->update(['broadcast_requested' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn('broadcast_requested');
        });
    }
};
