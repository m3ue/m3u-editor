<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Scopes\ExcludeAioFailoverClonesScope;
use App\Services\AioStreamsQualityParser;
use App\Services\AIOStreamsService;
use App\Settings\GeneralSettings;
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
        $this->onQueue('aiostreams-resolve');
    }

    public function uniqueId(): string
    {
        return 'resolve-aio-channel-'.$this->channelId;
    }

    public function handle(AioStreamsQualityParser $parser): void
    {
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

        $settings = app(GeneralSettings::class);
        $maxCandidates = $settings->aiostreams_max_failover_candidates ?? 3;

        $ranked = $parser->rank($streams);
        $selected = array_slice($ranked, 0, max(1, $maxCandidates));

        // Clear any previously created failover chain before writing the fresh one.
        $this->clearExistingFailovers($channel);

        $primary = $selected[0];
        $movieData = $channel->movie_data ?? [];
        $movieData['aiostreams'] = [
            'candidates' => array_map(fn (array $c) => $c['parsed'], $selected),
        ];

        $channel->update([
            'url' => $primary['stream']['url'] ?? null,
            'container_extension' => $primary['parsed']['container'] ?? $channel->container_extension,
            'movie_data' => $movieData,
            'aio_resolution_status' => count($selected) >= $maxCandidates ? 'resolved' : 'partial',
            'aio_last_resolved_at' => now(),
        ]);

        foreach (array_slice($selected, 1) as $index => $candidate) {
            // is_aio_failover_clone=true: a lightweight sibling Channel row that only
            // exists so ChannelFailover has a real row to point at. It must never show
            // up anywhere channels are listed for actual browsing/playback (Xtream API,
            // M3U export, VOD/relation-manager tables) — Channel's global scope
            // (ExcludeAioFailoverClonesScope) hides it everywhere except
            // failoverChannels(), which opts back in explicitly.
            $failoverChannel = Channel::create([
                'user_id' => $channel->user_id,
                'playlist_id' => $channel->playlist_id,
                'name' => $channel->name,
                'title' => $channel->title,
                'group_internal' => trim(($channel->group_internal ?? '').'-aio-'.($index + 1), '-'),
                'is_custom' => true,
                'is_vod' => $channel->is_vod,
                'is_aio_failover_clone' => true,
                'url' => $candidate['stream']['url'] ?? null,
                'container_extension' => $candidate['parsed']['container'] ?? null,
                'aio_integration_id' => $channel->aio_integration_id,
                'aio_item_id' => $channel->aio_item_id,
                'aio_type' => $channel->aio_type,
                'aio_resolution_status' => 'resolved',
                'aio_last_resolved_at' => now(),
            ]);

            ChannelFailover::create([
                'user_id' => $channel->user_id,
                'channel_id' => $channel->id,
                'channel_failover_id' => $failoverChannel->id,
                'sort' => $index + 1,
                'metadata' => $candidate['parsed'],
            ]);
        }
    }

    /**
     * Remove the previous failover chain (both the pivot rows and the
     * lightweight sibling Channel rows they point at) before re-resolving,
     * so repeated resolutions don't accumulate stale candidates.
     */
    protected function clearExistingFailovers(Channel $channel): void
    {
        $failovers = ChannelFailover::where('channel_id', $channel->id)->get();

        $failoverChannelIds = $failovers->pluck('channel_failover_id');

        ChannelFailover::where('channel_id', $channel->id)->delete();

        Channel::withoutGlobalScope(ExcludeAioFailoverClonesScope::class)
            ->whereIn('id', $failoverChannelIds)
            ->where('is_custom', true)
            ->whereNotNull('aio_integration_id')
            ->delete();
    }
}
