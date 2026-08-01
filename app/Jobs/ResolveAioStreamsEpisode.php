<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Models\EpisodeFailover;
use App\Models\Scopes\ExcludeAioFailoverClonesScope;
use App\Services\AioStreamsQualityParser;
use App\Services\AIOStreamsService;
use App\Settings\GeneralSettings;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        $this->onQueue('aiostreams-resolve');
    }

    public function uniqueId(): string
    {
        return 'resolve-aio-episode-'.$this->episodeId;
    }

    public function handle(AioStreamsQualityParser $parser): void
    {
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

        $settings = app(GeneralSettings::class);
        $maxCandidates = $settings->aiostreams_max_failover_candidates ?? 3;

        $ranked = $parser->rank($streams);
        $selected = array_slice($ranked, 0, max(1, $maxCandidates));

        $this->clearExistingFailovers($episode);

        $primary = $selected[0];
        $info = $episode->info ?? [];
        $info['aiostreams'] = [
            'candidates' => array_map(fn (array $c) => $c['parsed'], $selected),
        ];

        $episode->update([
            'url' => $primary['stream']['url'] ?? null,
            'container_extension' => $primary['parsed']['container'] ?? $episode->container_extension,
            'info' => $info,
            // Re-enable an episode that was disabled at creation for being unaired
            // ('scheduled') — it's now playable now that a stream has resolved.
            'enabled' => true,
            'aio_resolution_status' => count($selected) >= $maxCandidates ? 'resolved' : 'partial',
            'aio_last_resolved_at' => now(),
        ]);

        foreach (array_slice($selected, 1) as $index => $candidate) {
            // is_aio_failover_clone=true: a lightweight sibling Episode row that only
            // exists so EpisodeFailover has a real row to point at. It carries the same
            // series/season/episode_num as the real episode, so it must never show up
            // anywhere episodes are listed for actual browsing/playback (Xtream API,
            // M3U export, series browse) — Episode's global scope
            // (ExcludeAioFailoverClonesScope) hides it everywhere except
            // failoverEpisodes(), which opts back in explicitly.
            $failoverEpisode = Episode::create([
                'user_id' => $episode->user_id,
                'playlist_id' => $episode->playlist_id,
                'series_id' => $episode->series_id,
                'season_id' => $episode->season_id,
                'title' => $episode->title,
                'episode_num' => $episode->episode_num,
                'season' => $episode->season,
                'import_batch_no' => Str::orderedUuid()->toString(),
                'is_custom' => true,
                'is_aio_failover_clone' => true,
                'url' => $candidate['stream']['url'] ?? null,
                'container_extension' => $candidate['parsed']['container'] ?? null,
                'aio_item_id' => $episode->aio_item_id,
                'aio_resolution_status' => 'resolved',
                'aio_last_resolved_at' => now(),
            ]);

            EpisodeFailover::create([
                'user_id' => $episode->user_id,
                'episode_id' => $episode->id,
                'episode_failover_id' => $failoverEpisode->id,
                'sort' => $index + 1,
                'metadata' => $candidate['parsed'],
            ]);
        }
    }

    protected function clearExistingFailovers(Episode $episode): void
    {
        $failovers = EpisodeFailover::where('episode_id', $episode->id)->get();

        $failoverEpisodeIds = $failovers->pluck('episode_failover_id');

        EpisodeFailover::where('episode_id', $episode->id)->delete();

        Episode::withoutGlobalScope(ExcludeAioFailoverClonesScope::class)
            ->whereIn('id', $failoverEpisodeIds)
            ->where('is_custom', true)
            ->whereNotNull('aio_item_id')
            ->delete();
    }
}
