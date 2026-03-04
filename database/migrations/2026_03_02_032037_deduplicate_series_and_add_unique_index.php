<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Removes duplicate series/seasons/episodes created by concurrent SyncMediaServer jobs,
     * then adds a unique index to prevent future duplicates.
     */
    public function up(): void
    {
        // Step 1: Find duplicate series groups (same playlist_id + source_series_id)
        $duplicateGroups = DB::table('series')
            ->select('source_series_id', 'playlist_id', DB::raw('count(*) as cnt'))
            ->whereNotNull('source_series_id')
            ->groupBy('source_series_id', 'playlist_id')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplicateGroups->isNotEmpty()) {
            Log::info('Deduplicate migration: Found duplicate series groups', [
                'count' => $duplicateGroups->count(),
            ]);

            foreach ($duplicateGroups as $group) {
                // Keep the series with the most recent updated_at (the one maintained by the latest sync)
                $seriesToKeep = DB::table('series')
                    ->where('source_series_id', $group->source_series_id)
                    ->where('playlist_id', $group->playlist_id)
                    ->orderByDesc('updated_at')
                    ->first();

                if (! $seriesToKeep) {
                    continue;
                }

                // Get IDs of duplicate series to remove
                $duplicateSeriesIds = DB::table('series')
                    ->where('source_series_id', $group->source_series_id)
                    ->where('playlist_id', $group->playlist_id)
                    ->where('id', '!=', $seriesToKeep->id)
                    ->pluck('id');

                if ($duplicateSeriesIds->isEmpty()) {
                    continue;
                }

                // Delete episodes belonging to duplicate series
                DB::table('episodes')
                    ->whereIn('series_id', $duplicateSeriesIds)
                    ->delete();

                // Delete seasons belonging to duplicate series
                DB::table('seasons')
                    ->whereIn('series_id', $duplicateSeriesIds)
                    ->delete();

                // Delete the duplicate series themselves
                DB::table('series')
                    ->whereIn('id', $duplicateSeriesIds)
                    ->delete();
            }

            Log::info('Deduplicate migration: Cleanup complete');
        }

        // Step 2: Add unique index to prevent future duplicates
        // Use a partial unique index (only where source_series_id IS NOT NULL)
        // since NULL values should not be constrained
        Schema::table('series', function (Blueprint $table) {
            $table->unique(['playlist_id', 'source_series_id'], 'series_playlist_source_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->dropUnique('series_playlist_source_unique');
        });
    }
};
