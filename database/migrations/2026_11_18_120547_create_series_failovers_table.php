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
        Schema::create('series_failovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('series_failover_id')->constrained('series')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedInteger('sort')->nullable()->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['series_id', 'series_failover_id']);
            $table->index('series_failover_id');
        });

        $this->enableVodAutoMergeForTmdbKeyPlaylists();
    }

    /**
     * Auto-merge used to pick Live or VOD from the VOD Merge key: Stream ID merged Live
     * only and TMDB ID merged VOD only. VOD now has its own toggle (merge_vod), so turn
     * it on where auto-merge is on with the TMDB ID key. Live stays on for them, as it
     * always should have been.
     */
    private function enableVodAutoMergeForTmdbKeyPlaylists(): void
    {
        DB::table('playlists')
            ->select(['id', 'auto_merge_config'])
            ->where('auto_merge_channels_enabled', true)
            ->whereNotNull('auto_merge_config')
            ->chunkById(500, function ($playlists): void {
                foreach ($playlists as $playlist) {
                    $config = json_decode((string) $playlist->auto_merge_config, true);

                    if (! is_array($config) || ($config['merge_key'] ?? null) !== 'tmdb_id' || array_key_exists('merge_vod', $config)) {
                        continue;
                    }

                    $config['merge_vod'] = true;

                    DB::table('playlists')
                        ->where('id', $playlist->id)
                        ->update(['auto_merge_config' => json_encode($config)]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('series_failovers');
    }
};
