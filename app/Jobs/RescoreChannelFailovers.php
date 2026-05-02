<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Playlist;
use App\Services\Channels\ChannelMergeScorer;
use App\Traits\ProviderRequestDelay;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-score failover groups for a playlist and re-sort each group's failovers
 * so the highest-scoring source sits at sort=0.
 *
 * The master channel is intentionally never promoted or replaced. For
 * "smart channel" masters (custom channel with no URL), the existing
 * PlaylistUrlService::getChannelUrl() falls back to the first failover, so
 * re-ordering the failovers is enough to switch the effective stream URL.
 * For real-channel masters, this job simply re-orders failovers — the master
 * remains in place.
 */
class RescoreChannelFailovers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, ProviderRequestDelay, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60 * 60;

    /**
     * @param  int  $playlistId  Playlist whose failover groups should be rescored
     * @param  array<int, int>|null  $channelIds  Optional master-channel filter (manual scoped runs)
     */
    public function __construct(
        public int $playlistId,
        public ?array $channelIds = null,
    ) {}

    /**
     * Prevent concurrent runs against the same playlist. If a job is already in
     * flight, additional dispatches (manual + scheduled, or two manual clicks)
     * are dropped — scoring is idempotent and re-probing the same upstream URLs
     * twice would just waste provider quota. Lock auto-releases at the job
     * timeout so a crashed worker doesn't hold it indefinitely.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->playlistId))
                ->releaseAfter($this->timeout)
                ->expireAfter($this->timeout),
        ];
    }

    public function handle(): void
    {
        $playlist = Playlist::find($this->playlistId);
        if (! $playlist) {
            Log::warning("RescoreChannelFailovers: playlist {$this->playlistId} not found");

            return;
        }

        $masterIds = ChannelFailover::query()
            ->whereHas('channel', fn ($q) => $q->where('playlist_id', $playlist->id))
            ->when($this->channelIds, fn ($q, $ids) => $q->whereIn('channel_id', $ids))
            ->distinct()
            ->pluck('channel_id');

        if ($masterIds->isEmpty()) {
            $playlist->update(['last_failover_rescore_at' => now()]);

            return;
        }

        $stalenessDays = (int) ($playlist->failover_rescore_staleness_days ?? 7);
        $staleBefore = $stalenessDays > 0 ? Carbon::now()->subDays($stalenessDays) : null;
        $probeTimeout = (int) ($playlist->probe_timeout ?? 15);

        $scorer = $this->buildScorer($playlist);

        // Eager-load masters with their failover channels in one query so the
        // per-iteration loop doesn't N+1.
        $masters = Channel::query()
            ->with('failoverChannels')
            ->whereIn('id', $masterIds)
            ->get();

        foreach ($masters as $master) {
            $failovers = $master->failoverChannels;
            if ($failovers->isEmpty()) {
                continue;
            }

            $this->ensureFreshStats($master, $staleBefore, $probeTimeout);
            foreach ($failovers as $failover) {
                $this->ensureFreshStats($failover, $staleBefore, $probeTimeout);
            }

            $this->reorderFailovers($master, $failovers, $scorer);
        }

        $playlist->update(['last_failover_rescore_at' => now()]);
    }

    /**
     * Build a scorer using the playlist's auto_merge_config, falling back to
     * a sensible default of [resolution, fps, bitrate, codec] when no config
     * is set.
     */
    protected function buildScorer(Playlist $playlist): ChannelMergeScorer
    {
        $config = $playlist->auto_merge_config ?? [];

        $rawAttributes = $config['priority_attributes'] ?? null;
        $priorityOrder = empty($rawAttributes)
            ? ['resolution', 'fps', 'bitrate', 'codec']
            : ChannelMergeScorer::normalizePriorityOrder($rawAttributes);

        $groupPriorityCache = [];
        foreach ($config['group_priorities'] ?? [] as $group) {
            if (isset($group['group_id'], $group['weight'])) {
                $groupPriorityCache[(int) $group['group_id']] = (int) $group['weight'];
            }
        }

        return new ChannelMergeScorer(
            priorityOrder: $priorityOrder,
            playlistPriority: [$playlist->id => 0],
            groupPriorityCache: $groupPriorityCache,
            preferredCodec: $config['prefer_codec'] ?? null,
            priorityKeywords: $config['priority_keywords'] ?? [],
        );
    }

    /**
     * Re-probe a channel if its stats are missing or older than the staleness window.
     */
    protected function ensureFreshStats(Channel $channel, ?CarbonInterface $staleBefore, int $probeTimeout): void
    {
        if (! $channel->probe_enabled) {
            return;
        }

        $needsReprobe = $channel->stream_stats_probed_at === null
            || ($staleBefore !== null && $channel->stream_stats_probed_at->lt($staleBefore));

        if (! $needsReprobe) {
            return;
        }

        try {
            $stats = $this->withProviderThrottling(
                fn () => $channel->probeStreamStats($probeTimeout)
            );

            if (! empty($stats)) {
                $channel->updateQuietly([
                    'stream_stats' => $stats,
                    'stream_stats_probed_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning("RescoreChannelFailovers: probe failed for channel {$channel->id}: {$e->getMessage()}");
        }
    }

    /**
     * Score every failover and update channel_failovers.sort so the best
     * failover sits at sort=0. The master is intentionally not scored or altered.
     *
     * Each failover's score and per-attribute breakdown is persisted into
     * channel_failovers.metadata so the rationale stays inspectable later.
     *
     * @param  Collection<int, Channel>  $failovers
     */
    protected function reorderFailovers(Channel $master, $failovers, ChannelMergeScorer $scorer): void
    {
        $scored = $failovers->map(fn (Channel $failover) => [
            'failover_id' => $failover->id,
            'score' => $scorer->score($failover),
            'breakdown' => $scorer->scoreBreakdown($failover),
        ])->sortByDesc('score')->values();

        $rankedAt = now()->toIso8601String();
        foreach ($scored as $index => $row) {
            ChannelFailover::query()
                ->where('channel_id', $master->id)
                ->where('channel_failover_id', $row['failover_id'])
                ->update([
                    'sort' => $index,
                    'metadata' => [
                        'score' => $row['score'],
                        'attribute_scores' => $row['breakdown'],
                        'priority_order' => array_keys($row['breakdown']),
                        'ranked_at' => $rankedAt,
                    ],
                ]);
        }
    }
}
