<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Provider-supplied stream type (M3U `tvg-type` or Xtream `stream_type`), refreshed on
     * every sync. The user-editable `tvg_type` column stays the override on top of it.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('stream_type')->nullable()->after('tvg_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('stream_type');
        });
    }
};
