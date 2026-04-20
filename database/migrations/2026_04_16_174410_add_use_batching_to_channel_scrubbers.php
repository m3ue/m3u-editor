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
        Schema::table('channel_scrubbers', function (Blueprint $table) {
            $table->boolean('use_batching')->default(false)->after('check_method');
            $table->unsignedSmallInteger('probe_timeout')->default(10)->after('use_batching');
            $table->boolean('disable_dead')->default(true)->after('probe_timeout');
            $table->boolean('enable_live')->default(false)->after('disable_dead');
        });

        Schema::table('channel_scrubber_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('live_count')->default(0)->after('disabled_count');
        });

        // Backfill live_count for existing completed runs: channel_count - dead_count = live_count.
        DB::statement('UPDATE channel_scrubber_logs SET live_count = CASE WHEN channel_count > dead_count THEN channel_count - dead_count ELSE 0 END WHERE status = \'completed\'');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channel_scrubbers', function (Blueprint $table) {
            $table->dropColumn(['use_batching', 'probe_timeout', 'disable_dead', 'enable_live']);
        });

        Schema::table('channel_scrubber_logs', function (Blueprint $table) {
            $table->dropColumn('live_count');
        });
    }
};
