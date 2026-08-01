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
        Schema::table('series', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false)->after('playlist_id');
            $table->foreignId('aio_integration_id')->nullable()->after('is_custom')
                ->constrained('media_server_integrations')->nullOnDelete();
            $table->string('aio_item_id')->nullable()->after('aio_integration_id');
            $table->string('aio_type')->nullable()->after('aio_item_id');
            $table->string('aio_resolution_status')->nullable()->after('aio_type');
            $table->timestamp('aio_last_resolved_at')->nullable()->after('aio_resolution_status');

            $table->index(['aio_integration_id', 'aio_item_id', 'aio_type'], 'series_aio_lookup_index');
        });

        Schema::table('seasons', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false)->after('playlist_id');
        });

        Schema::table('episodes', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false)->after('playlist_id');
            $table->string('aio_item_id')->nullable()->after('is_custom');
            $table->timestamp('aio_air_date')->nullable()->after('aio_item_id');
            $table->string('aio_resolution_status')->nullable()->after('aio_air_date');
            $table->timestamp('aio_last_resolved_at')->nullable()->after('aio_resolution_status');
            // True for the lightweight sibling Episode rows ResolveAioStreamsEpisode creates
            // to represent failover candidates (see EpisodeFailover) — these carry the same
            // series/season/episode_num as the real episode purely so the failover pivot can
            // point at a real row, and must stay invisible everywhere episodes are listed for
            // actual playback (Xtream API, M3U export, series browse) to avoid duplicate-looking
            // episodes. Episode::episodes()-style queries exclude these via a global scope.
            $table->boolean('is_aio_failover_clone')->default(false)->after('aio_last_resolved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->dropIndex('series_aio_lookup_index');
            $table->dropConstrainedForeignId('aio_integration_id');
            $table->dropColumn(['is_custom', 'aio_item_id', 'aio_type', 'aio_resolution_status', 'aio_last_resolved_at']);
        });

        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn('is_custom');
        });

        Schema::table('episodes', function (Blueprint $table) {
            $table->dropColumn(['is_custom', 'aio_item_id', 'aio_air_date', 'aio_resolution_status', 'aio_last_resolved_at', 'is_aio_failover_clone']);
        });
    }
};
