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
        // Per-integration defaults: a small table, so real foreign keys are fine.
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->foreignId('aiostreams_movie_profile_id')->nullable()
                ->constrained('aio_streams_match_profiles')->nullOnDelete();
            $table->foreignId('aiostreams_series_profile_id')->nullable()
                ->constrained('aio_streams_match_profiles')->nullOnDelete();
        });

        // Per-title overrides. Deliberately a plain nullable column with no foreign
        // key or index: channels/series are large and written continuously by the
        // import job chains, and on Postgres adding a constraint (or a non-concurrent
        // index) scans and locks the whole table. A nullable column without a default
        // is a catalog-only change. AioStreamsMatchProfile clears stale references
        // itself when a profile is deleted, and a dangling id just falls back to the
        // integration default.
        Schema::table('channels', function (Blueprint $table) {
            $table->unsignedBigInteger('aio_match_profile_id')->nullable();
        });

        Schema::table('series', function (Blueprint $table) {
            $table->unsignedBigInteger('aio_match_profile_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->dropColumn('aio_match_profile_id');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('aio_match_profile_id');
        });

        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aiostreams_series_profile_id');
            $table->dropConstrainedForeignId('aiostreams_movie_profile_id');
        });
    }
};
