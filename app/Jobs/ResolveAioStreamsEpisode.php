<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Services\AioStreamsCandidateService;
use App\Services\AioStreamsQualityParser;
use App\Services\AIOStreamsService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Episode counterpart to ResolveAioStreamsChannel — resolves (or re-resolves)
 * the current best stream URLs for an AIOStreams-backed custom Episode,
 * storing the top candidate on the episode itself and the rest as an ordered
 * EpisodeFailover chain (already used for live playback failover today).
 *
 * Uses ShouldBeUniqueUntilProcessing rather than ShouldBeUnique: the
 * empty-result retry below self-dispatches (with the same uniqueId) from
 * INSIDE handle(). A plain ShouldBeUnique lock is held until the job
 * finishes, so that self-redispatch could never acquire it — the retry
 * would be silently dropped and the episode would stay 'pending' forever.
 * UntilProcessing releases the lock as soon as this job starts running,
 * before the retry dispatch happens.
 */
class ResolveAioStreamsEpisode implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $backoff = 10;

    public int $uniqueFor = 120;

    public function __construct(
        public int $episodeId,
        public int $attempt = 1,
    ) {
        // With AIOStreams disabled its supervisor isn't running, so the job stays on
        // the default queue where handle() drops it instead of waiting forever.
        if (config('services.aiostreams.enabled', true)) {
            $this->onQueue('aiostreams-resolve');
        }
    }

    public function uniqueId(): string
    {
        return 'resolve-aio-episode-'.$this->episodeId;
    }

    public function handle(AioStreamsQualityParser $parser): void
    {
        if (! config('services.aiostreams.enabled', true)) {
            return;
        }

        $episode = Episode::find($this->episodeId);

        if (! $episode || ! $episode->is_custom || ! $episode->aio_item_id) {
            return;
        }

        $series = $episode->series;
        $integration = $series?->aioIntegration;
        if (! $integration) {
            Log::warning('ResolveAioStreamsEpisode: integration missing', ['episode_id' => $episode->id]);

            return;
        }

        try {
            $result = AIOStreamsService::make($integration)->fetchStreams('series', $episode->aio_item_id);
        } catch (\Throwable $e) {
            Log::warning('ResolveAioStreamsEpisode: fetchStreams failed', [
                'episode_id' => $episode->id,
                'exception' => $e->getMessage(),
            ]);
            $result = ['streams' => []];
        }

        $streams = $result['streams'] ?? [];

        if (empty($streams)) {
            if ($this->attempt < 3) {
                self::dispatch($this->episodeId, $this->attempt + 1)->delay(now()->addSeconds(20));

                return;
            }

            $episode->update(['aio_resolution_status' => 'failed']);

            return;
        }

        $candidates = new AioStreamsCandidateService($parser);

        // Prefers the user's manual pick (re-matched against the fresh results)
        // over the item's match profile ranking, so a link refresh doesn't undo it.
        [$selected, $manualKeys] = $candidates->selectForAutomaticResolution($episode, $streams);

        // Only possible when the item's profile is set to leave it unresolved
        // rather than fall back: streams exist, but none pass its filters. The
        // hourly pending-candidates sweep retries 'failed' items, so a matching
        // release is picked up once one appears.
        if ($selected === []) {
            $episode->update(['aio_resolution_status' => 'failed']);

            return;
        }

        $candidates->apply($episode, $selected, $manualKeys);
    }
}
