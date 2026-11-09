<?php

namespace App\Jobs;

use App\Models\ViewerWatchProgress;
use App\Models\WatchHistoryIntegration;
use App\Services\WatchHistory\WatchHistoryException;
use App\Services\WatchHistory\WatchHistoryScrobbler;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Pushes one progress row to one Trakt / SIMKL account once the row has
 * stopped changing. Unique per (account, row), so the saves a player makes
 * every few seconds collapse into this one job, which waits by releasing
 * itself until the row has been quiet for WatchHistoryScrobbler::QUIET_SECONDS.
 */
class PushWatchHistoryProgress implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Keep the lock across a long film's worth of releases.
     */
    public int $uniqueFor = 21600;

    public function __construct(
        public int $integrationId,
        public int $progressId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->integrationId}:{$this->progressId}";
    }

    /**
     * Releasing to wait out playback counts as an attempt, so bound the job
     * by time instead of tries.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function handle(WatchHistoryScrobbler $scrobbler): void
    {
        $integration = WatchHistoryIntegration::query()->pushing()->find($this->integrationId);
        $progress = ViewerWatchProgress::with(['channel', 'episode.series'])->find($this->progressId);

        if (! $integration || ! $progress) {
            return;
        }

        $quietFor = (int) $progress->updated_at->diffInSeconds(now(), true);
        if ($quietFor < WatchHistoryScrobbler::QUIET_SECONDS) {
            $this->release(WatchHistoryScrobbler::QUIET_SECONDS - $quietFor + 1);

            return;
        }

        try {
            $scrobbler->push($integration, $progress);
        } catch (WatchHistoryException $exception) {
            if ($exception->retryAfter !== null) {
                $this->release($exception->retryAfter);

                return;
            }

            if (! $exception->signedOut) {
                $integration->forceFill(['last_error' => $exception->getMessage()])->save();
            }

            Log::info('Watch history push failed', [
                'integration_id' => $this->integrationId,
                'progress_id' => $this->progressId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
