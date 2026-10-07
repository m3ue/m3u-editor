<?php

namespace App\Jobs;

use App\Enums\SyncRunPhase;
use App\Models\Playlist;
use App\Services\MediaSourceMatchService;
use App\Services\SyncPipelineService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rebuild a playlist's media_source_matches rows (provider items keyed to
 * same-user Emby/Jellyfin/Plex/local media items by external IDs).
 *
 * Runs from the sync pipeline (after TMDB IDs are populated, before STRM),
 * from a completed media-server sync, and when the playlist toggle flips.
 *
 * Deduplication is split in two because ShouldBeUnique alone can stall a
 * sync run: if an ad-hoc job (toggle flip, media sync) holds the unique lock
 * when the pipeline dispatches its phase job, Laravel silently drops the
 * pipeline job and completePhase() never runs, so the SyncRun hangs at
 * MediaSourceMatch. So:
 *  - uniqueId() separates pipeline runs from ad-hoc dispatches, and
 *  - WithoutOverlapping serializes the actual rebuilds per playlist, with
 *    releases (not silent drops) when they collide, bounded by retryUntil().
 */
class MatchMediaServerSources implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    public function __construct(
        public int $playlistId,
        public ?int $syncRunId = null,
        public ?SyncRunPhase $completionPhase = null,
    ) {}

    /**
     * Pipeline-scheduled runs dedupe against other runs of the same sync
     * only, never against an ad-hoc rebuild, whose lock would otherwise
     * silently drop this job and strand the SyncRun phase.
     */
    public function uniqueId(): string
    {
        return 'match-media-server-sources-'.$this->playlistId.':'.($this->syncRunId ?? 'adhoc');
    }

    /**
     * Serialize rebuilds per playlist. A colliding job is released back onto
     * the queue (not dropped) until the lock frees or retryUntil() expires.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->playlistId))
                ->releaseAfter(15)
                ->expireAfter(900),
        ];
    }

    /**
     * Bound releases so a long collision doesn't exhaust attempts.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(30);
    }

    /**
     * Execute the job.
     *
     * Always completes the pipeline phase (when scheduled via the pipeline),
     * even on early returns (playlist gone, toggle off) and on exceptions,
     * so the SyncRun timeline can advance past MediaSourceMatch.
     */
    public function handle(): void
    {
        try {
            $this->rebuild();
        } catch (Throwable $e) {
            Log::error('MatchMediaServerSources: unhandled error', [
                'playlist_id' => $this->playlistId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->completePipelinePhase();
        }
    }

    /**
     * Covers the paths where handle()'s finally block never runs: the worker
     * is killed at $timeout, or retryUntil() expires while the job is still
     * being released by WithoutOverlapping. completePhase() is idempotent.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('MatchMediaServerSources: job failed', [
            'playlist_id' => $this->playlistId,
            'error' => $exception->getMessage(),
        ]);

        $this->completePipelinePhase();
    }

    /**
     * Core rebuild logic, isolated from the phase-complete guarantee so the
     * `finally` block above remains simple.
     */
    private function rebuild(): void
    {
        $playlist = Playlist::find($this->playlistId);
        if (! $playlist) {
            Log::warning("MatchMediaServerSources: playlist {$this->playlistId} not found");

            return;
        }

        $result = app(MediaSourceMatchService::class)->rebuildForPlaylist($playlist);

        // Pipeline runs regenerate STRM files in their own later phase. Ad-hoc
        // rebuilds (toggle flip, media-server sync) would otherwise leave
        // "original"-url STRM files pointing at the old source until the
        // playlist's next sync.
        if ($this->syncRunId === null && $result['changed']) {
            $playlist->dispatchStrmRefresh();
        }
    }

    private function completePipelinePhase(): void
    {
        if ($this->syncRunId !== null && $this->completionPhase !== null) {
            app(SyncPipelineService::class)->completePhase(
                $this->syncRunId,
                $this->completionPhase,
            );
        }
    }
}
