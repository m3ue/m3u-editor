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
        Schema::table('channel_scrubbers', function (Blueprint $table) {
            $table->boolean('rebuild_failovers_after_scan')
                ->default(false)
                ->after('enable_live');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_scrubbers', function (Blueprint $table) {
            $table->dropColumn('rebuild_failovers_after_scan');
        });
    }
};
