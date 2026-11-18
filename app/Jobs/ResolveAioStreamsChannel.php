<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\AioStreamsCandidateService;
use App\Services\AioStreamsQualityParser;
use App\Services\AIOStreamsService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Resolves (or re-resolves) the current best stream URLs for an
 * AIOStreams-backed custom VOD Channel, storing the top candidate as the
 * channel's own url and the rest as an ordered ChannelFailover chain so
 * M3uProxyService::resolveFailoverUrl() can fail over between them at
 * playback time without any AIOStreams-specific proxy logic.
 *
 * Uses ShouldBeUniqueUntilProcessing rather than ShouldBeUnique: the
 * empty-result retry below self-dispatches (with the same uniqueId) from
 * INSIDE handle(). A plain ShouldBeUnique lock is held until the job
 * finishes, so that self-redispatch could never acquire it — the retry
 * would be silently dropped and the channel would stay 'pending' forever.
 * UntilProcessing releases the lock as soon as this job starts running,
 * before the retry dispatch happens.
 */
class ResolveAioStreamsChannel implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $backoff = 10;

    /**
     * Debounce window: prevents duplicate resolution work for the same
     * channel piling up from repeated triggers (manual retry, exhaustion
     * hook, safety-net job) within a short window.
     */
    public int $uniqueFor = 120;

    public function __construct(
        public int $channelId,
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
        return 'resolve-aio-channel-'.$this->channelId;
    }

    public function handle(AioStreamsQualityParser $parser): void
    {
        if (! config('services.aiostreams.enabled', true)) {
            return;
        }

        $channel = Channel::find($this->channelId);

        if (! $channel || ! $channel->is_custom || ! $channel->aio_integration_id || ! $channel->aio_item_id) {
            return;
        }

        $integration = $channel->aioIntegration;
        if (! $integration) {
            Log::warning('ResolveAioStreamsChannel: integration missing', ['channel_id' => $channel->id]);

            return;
        }

        try {
            $result = AIOStreamsService::make($integration)->fetchStreams($channel->aio_type ?? 'movie', $channel->aio_item_id);
        } catch (\Throwable $e) {
            Log::warning('ResolveAioStreamsChannel: fetchStreams failed', [
                'channel_id' => $channel->id,
                'exception' => $e->getMessage(),
            ]);
            $result = ['streams' => []];
        }

        $streams = $result['streams'] ?? [];

        if (empty($streams)) {
            // AIOStreams sometimes returns no results on a first attempt but
            // succeeds shortly after (addon warm-up race, not a network error).
            if ($this->attempt < 3) {
                self::dispatch($this->channelId, $this->attempt + 1)->delay(now()->addSeconds(20));

                return;
            }

            $channel->update(['aio_resolution_status' => 'failed']);

            return;
        }

        $candidates = new AioStreamsCandidateService($parser);

        // Prefers the user's manual pick (re-matched against the fresh results)
        // over the item's match profile ranking, so a link refresh doesn't undo it.
        [$selected, $manualKeys] = $candidates->selectForAutomaticResolution($channel, $streams);

        // Only possible when the item's profile is set to leave it unresolved
        // rather than fall back: streams exist, but none pass its filters. The
        // hourly pending-candidates sweep retries 'failed' items, so a matching
        // release is picked up once one appears.
        if ($selected === []) {
            $channel->update(['aio_resolution_status' => 'failed']);

            return;
        }

        $candidates->apply($channel, $selected, $manualKeys);
    }
}
