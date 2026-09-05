<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * One-time data cleanup for issue #1482.
     *
     * A malformed Xtream get_series row with no series_id used to be imported anyway,
     * creating a Series with source_series_id = NULL. XtreamService::getSeriesInfo()
     * requires a string id, so fetching metadata for one of these throws a TypeError
     * that escapes fetchMetadata()'s catch block, kills the batch job, and strands the
     * whole SyncRun in the series_metadata phase forever ("stuck processing" on every
     * sync). ProcessM3uImportSeriesChunk now rejects series_id-less rows at ingest and
     * Series::fetchMetadata() skips a null source_series_id gracefully, but this bug
     * shipped to every self-hosted install before that fix, so existing installs can
     * already be carrying one of these broken rows. This backfills the fix for them.
     *
     * A NULL source_series_id is not always broken data, so the query below is scoped
     * tightly to exclude every legitimate case:
     *   - AIOStreams series (is_custom = true) never carry a source_series_id.
     *   - DVR-generated series (import_batch_no = 'dvr') are created with
     *     source_series_id = NULL deliberately (DvrVodIntegrationService).
     *   - Media server (Emby/Jellyfin/Plex) series always carry a computed
     *     crc32(...) source_series_id and are never NULL - metadata->media_server_id
     *     is checked too, as an explicit belt-and-suspenders exclusion.
     * What's left is exactly the malformed-Xtream-import case: is_custom = false,
     * not DVR, no media server tie, and genuinely no upstream id to ever fetch with.
     */
    public function up(): void
    {
        $candidates = DB::table('series')
            ->whereNull('source_series_id')
            ->where('is_custom', false)
            ->where(function ($query) {
                $query->whereNull('import_batch_no')
                    ->orWhere('import_batch_no', '!=', 'dvr');
            })
            ->whereNull('metadata->media_server_id')
            ->select('id', 'playlist_id', 'name')
            ->get();

        if ($candidates->isEmpty()) {
            return;
        }

        Log::info('Cleanup migration: removing series with no source_series_id (issue #1482)', [
            'count' => $candidates->count(),
            'series' => $candidates->map(fn ($s) => ['id' => $s->id, 'playlist_id' => $s->playlist_id, 'name' => $s->name])->all(),
        ]);

        $seriesIds = $candidates->pluck('id');

        // Cascade manually (episodes/seasons have no ON DELETE CASCADE from series
        // in every historical schema version) - same order as the 2026_03_02
        // deduplicate_series migration this mirrors.
        DB::table('episodes')->whereIn('series_id', $seriesIds)->delete();
        DB::table('seasons')->whereIn('series_id', $seriesIds)->delete();
        DB::table('series')->whereIn('id', $seriesIds)->delete();
    }

    /**
     * Irreversible: the deleted rows carried no usable upstream id, so there is
     * nothing to restore them from. The next sync will re-import any series that
     * the provider still legitimately offers with a valid series_id.
     */
    public function down(): void
    {
        //
    }
};
