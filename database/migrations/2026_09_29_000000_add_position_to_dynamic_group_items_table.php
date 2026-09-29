<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TMDB rank of each member (0 = first result), written by SyncDynamicGroups
     * so dynamic groups list their members in TMDB's order (trending rank,
     * popularity, ...). Existing rows default to 0 until the next sync.
     */
    public function up(): void
    {
        Schema::table('dynamic_group_items', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('dynamic_group_items', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
