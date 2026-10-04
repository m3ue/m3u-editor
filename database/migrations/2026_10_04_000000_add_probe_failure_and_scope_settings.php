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
            $table->unsignedSmallInteger('auto_probe_vod_streams_retry_failed_days')->default(7)->after('auto_probe_vod_streams_include_disabled');
            $table->unsignedTinyInteger('auto_probe_vod_streams_failure_threshold')->default(80)->after('auto_probe_vod_streams_retry_failed_days');
            $table->string('auto_probe_series_scope')->default('all')->after('auto_probe_vod_streams_failure_threshold');
        });

        // Nullable with no default and no index/foreign key, so this is a metadata-only change
        // on Postgres and does not rewrite or long-lock the (large) episodes table.
        Schema::table('episodes', function (Blueprint $table) {
            $table->unsignedBigInteger('stream_stats_inferred_from_id')->nullable()->after('stream_stats_probed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn('stream_stats_inferred_from_id');
        });

        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn([
                'auto_probe_vod_streams_retry_failed_days',
                'auto_probe_vod_streams_failure_threshold',
                'auto_probe_series_scope',
            ]);
        });
    }
};
