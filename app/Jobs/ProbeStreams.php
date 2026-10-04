<?php

namespace App\Jobs;

use App\Enums\SeriesProbeScope;
use App\Enums\SyncRunPhase;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SyncPipelineService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProbeStreams implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 60 * 60;

    public $deleteWhenMissingModels = true;

    /**
     * @param  ?bool  $onlyUnprobed  When true, only probe VOD channels and episodes that have
     *                               never been probed (stream_stats_probed_at IS NULL). Null
     *                               defers to the playlist's auto_probe_vod_streams_only_unprobed
     *                               setting (default true). Manual re-probe via UI bulk actions
     *                               bypasses this filter by dispatching ProbeStreamsChunk
     *                               directly with explicit IDs.
     * @param  ?bool  $includeDisabled  When true, include disabled VOD channels and episodes
     *                                  while still honoring probe_enabled. Null defers to the
     *                                  playlist's auto_probe_vod_streams_include_disabled setting
     *                                  (default false).
     */
    public function __construct(
        public int $playlistId,
        public ?bool $onlyUnprobed = null,
        public ?bool $includeDisabled = null,
        public ?int $syncRunId = null,
        public bool $isSeriesProbe = false,
    ) {}

    public function handle(): void
    {
        $start = now();

        $playlist = Playlist::find($this->playlistId);
        if (! $playlist) {
            Log::warning("ProbeStreams: Playlist {$this->playlistId} not found.");
            $this->completeProbePhaseIfTracked();

            return;
        }

        $probeTimeout = $playlist->probe_timeout ?? 15;
        $useBatching = (bool) ($playlist->probe_use_batching ?? false);
        $onlyUnprobed = $this->onlyUnprobed ?? (bool) ($playlist->auto_probe_vod_streams_only_unprobed ?? true);
        $includeDisabled = $this->includeDisabled ?? (bool) ($playlist->auto_probe_vod_streams_include_disabled ?? false);
        $retryFailedAfterDays = max(0, (int) ($playlist->auto_probe_vod_streams_retry_failed_days ?? 7));
        $failureThreshold = (int) ($playlist->auto_probe_vod_streams_failure_threshold ?? 0);
        $seriesScope = $playlist->auto_probe_series_scope ?? SeriesProbeScope::All;
        $probeRunKey = (string) Str::uuid();

        // The sync pipeline runs a VOD phase and a series phase back to back; each one probes
        // only its own content so failed items are not probed (and timed out) twice per sync.
        $vodChannelIds = [];
        $episodeIds = [];
        $episodeSiblings = [];
        $inferredCount = 0;

        if ($this->isSeriesProbe) {
            $episodeQuery = Episode::where('playlist_id', $this->playlistId)
                ->eligibleForProbe();

            if (! $includeDisabled) {
                $episodeQuery->where('enabled', true);
            }

            $groupColumn = $seriesScope->groupColumn();

            if ($groupColumn === null) {
                if ($onlyUnprobed) {
                    // Episodes with stats inferred by an earlier sampled run are measured
                    // individually once the playlist is switched back to all episodes.
                    $episodeQuery->where(fn (Builder $q) => $q
                        ->dueForProbe($retryFailedAfterDays)
                        ->orWhereNotNull('stream_stats_inferred_from_id'));
                }

                $episodeIds = $episodeQuery->pluck('id')->toArray();
            } else {
                if ($onlyUnprobed) {
                    $episodeQuery->dueForProbe($retryFailedAfterDays);
                }

                [$episodeIds, $episodeSiblings, $inferredCount] = $this->planSampledEpisodes($episodeQuery, $groupColumn, $onlyUnprobed);
            }
        } else {
            $vodChannelQuery = Channel::where('playlist_id', $this->playlistId)
                ->where('is_vod', true)
                ->eligibleForProbe();

            if (! $includeDisabled) {
                $vodChannelQuery->where('enabled', true);
            }

            if ($onlyUnprobed) {
                $vodChannelQuery->dueForProbe($retryFailedAfterDays);
            }

            $vodChannelIds = $vodChannelQuery->pluck('id')->toArray();
        }

        $totalChannels = count($vodChannelIds);
        $totalEpisodes = count($episodeIds);
        $total = $totalChannels + $totalEpisodes;

        if ($total === 0) {
            Log::info("ProbeStreams: No probe-eligible VOD channels or episodes found for playlist {$this->playlistId}. inferred={$inferredCount}");
            $this->completeProbePhaseIfTracked();

            return;
        }

        Log::info("ProbeStreams: Starting. playlist={$this->playlistId}, channels={$totalChannels}, episodes={$totalEpisodes}, inferred={$inferredCount}, scope={$seriesScope->value}, batching=".($useBatching ? 'yes' : 'no'));

        $user = $playlist->user;
        if ($user) {
            Notification::make()
                ->info()
                ->title(__('VOD stream probing started'))
                ->body(__('Probing :total VOD channel(s) and episode(s). You will be notified when complete.', ['total' => $total]))
                ->broadcast($user)
                ->sendToDatabase($user);
        }

        $chunkJobs = [];

        foreach (array_chunk($vodChannelIds, 50) as $chunk) {
            $chunkJobs[] = new ProbeStreamsChunk(
                channelIds: $chunk,
                episodeIds: [],
                probeTimeout: $probeTimeout,
                probeRunKey: $probeRunKey,
                failureThreshold: $failureThreshold,
            );
        }

        foreach (array_chunk($episodeIds, 50) as $chunk) {
            $chunkJobs[] = new ProbeStreamsChunk(
                channelIds: [],
                episodeIds: $chunk,
                probeTimeout: $probeTimeout,
                probeRunKey: $probeRunKey,
                failureThreshold: $failureThreshold,
                episodeSiblings: array_intersect_key($episodeSiblings, array_flip($chunk)),
            );
        }

        $complete = new ProbeStreamsComplete(
            playlistId: $this->playlistId,
            total: $total,
            start: $start,
            syncRunId: $this->syncRunId,
            isSeriesProbe: $this->isSeriesProbe,
            probeRunKey: $probeRunKey,
            failureThreshold: $failureThreshold,
        );

        try {
            if ($useBatching) {
                $this->dispatchAsBatch($chunkJobs, $complete, $playlist);
            } else {
                $this->dispatchAsChain($chunkJobs, $complete, $playlist);
            }

            Log::info('ProbeStreams: Dispatch complete.');
        } catch (Throwable $e) {
            Log::error("ProbeStreams: Dispatch failed — {$e->getMessage()}", [
                'exception' => $e,
                'playlist_id' => $this->playlistId,
            ]);
            $this->notifyFailed($playlist, $e->getMessage());
            $this->completeProbePhaseIfTracked();
        }
    }

    private function completeProbePhaseIfTracked(): void
    {
        if ($this->syncRunId) {
            $phase = $this->isSeriesProbe ? SyncRunPhase::SeriesProbe : SyncRunPhase::VodProbe;
            app(SyncPipelineService::class)->completePhase($this->syncRunId, $phase);
        }
    }

    /**
     * Sampled series probing (SeriesProbeScope::Season / ::Series): probe only the first
     * candidate episode of each group and let ProbeStreamsChunk copy its stats to the rest of
     * the group's candidates. In incremental mode, groups that already have a measured episode
     * need no probe at all; their new or due candidates inherit that measurement right here.
     *
     * Candidates are streamed in group order and resolved in batches of groups, so memory stays
     * bounded by one batch plus the id lists the chunk jobs carry anyway.
     *
     * @param  Builder<Episode>  $candidateQuery
     * @return array{0: array<int>, 1: array<int, array<int>>, 2: int} [sample ids, sample id => sibling ids, inferred count]
     */
    private function planSampledEpisodes(Builder $candidateQuery, string $groupColumn, bool $onlyUnprobed): array
    {
        $sampleIds = [];
        $siblings = [];
        $inferredCount = 0;
        $groups = [];

        $flush = function () use (&$groups, &$sampleIds, &$siblings, &$inferredCount, $groupColumn, $onlyUnprobed): void {
            if ($groups === []) {
                return;
            }

            // Any episode with stats of its own is a valid source for its group; MIN(id) just
            // picks one deterministically without loading every measured row.
            $sourceIds = $onlyUnprobed
                ? Episode::query()
                    ->where('playlist_id', $this->playlistId)
                    ->whereIn($groupColumn, array_keys($groups))
                    ->whereNotNull('stream_stats')
                    ->whereNull('stream_stats_inferred_from_id')
                    ->groupBy($groupColumn)
                    ->select($groupColumn)
                    ->selectRaw('MIN(id) as source_id')
                    ->pluck('source_id', $groupColumn)
                    ->all()
                : [];

            $inheritFromSource = [];
            foreach ($groups as $groupId => $candidateIds) {
                $sourceId = $sourceIds[$groupId] ?? null;

                if ($sourceId !== null) {
                    $inheritFromSource[(int) $sourceId] = $candidateIds;

                    continue;
                }

                $sampleId = array_shift($candidateIds);
                $sampleIds[] = $sampleId;
                $siblings[$sampleId] = $candidateIds;
            }

            if ($inheritFromSource !== []) {
                Episode::query()
                    ->whereKey(array_keys($inheritFromSource))
                    ->get(['id', 'stream_stats', 'stream_stats_probed_at', 'stream_stats_inferred_from_id'])
                    ->each(function (Episode $source) use ($inheritFromSource, &$inferredCount): void {
                        $inferredCount += $source->shareStreamStatsWith($inheritFromSource[$source->id] ?? []);
                    });
            }

            $groups = [];
        };

        $candidates = $candidateQuery
            ->orderBy($groupColumn)
            ->orderBy('season')
            ->orderBy('episode_num')
            ->orderBy('id')
            ->toBase()
            ->select(['id', $groupColumn])
            ->cursor();

        foreach ($candidates as $row) {
            $groupId = $row->{$groupColumn};

            if (! isset($groups[$groupId]) && count($groups) >= 500) {
                $flush();
            }

            $groups[$groupId][] = (int) $row->id;
        }

        $flush();

        return [$sampleIds, $siblings, $inferredCount];
    }

    private function dispatchAsBatch(
        array $chunkJobs,
        ProbeStreamsComplete $complete,
        Playlist $playlist,
    ): void {
        $userId = $playlist->user?->id;

        $batch = Bus::batch($chunkJobs)
            ->then(function () use ($complete) {
                dispatch($complete);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($userId) {
                Log::error("ProbeStreams batch failed: {$e->getMessage()}");
                self::notifyUserOfFailure($userId, $e->getMessage());
            })
            ->onConnection('redis')
            ->onQueue('import')
            ->allowFailures()
            ->dispatch();

        Log::info("ProbeStreams: Batch dispatched. id={$batch->id}, total={$batch->totalJobs}");
    }

    private function dispatchAsChain(
        array $chunkJobs,
        ProbeStreamsComplete $complete,
        Playlist $playlist,
    ): void {
        $userId = $playlist->user?->id;

        Bus::chain([
            ...$chunkJobs,
            $complete,
        ])
            ->onConnection('redis')
            ->onQueue('import')
            ->catch(function (Throwable $e) use ($userId) {
                Log::error("ProbeStreams chain failed: {$e->getMessage()}");
                self::notifyUserOfFailure($userId, $e->getMessage());
            })
            ->dispatch();

        Log::info('ProbeStreams: Chain dispatched.');
    }

    private function notifyFailed(Playlist $playlist, string $message): void
    {
        Log::error("ProbeStreams failed: {$message}");
        self::notifyUserOfFailure($playlist->user?->id, $message);
    }

    private static function notifyUserOfFailure(?int $userId, string $message): void
    {
        if (! $userId) {
            return;
        }

        $user = User::find($userId);
        if (! $user) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('VOD stream probing failed'))
            ->body($message)
            ->broadcast($user)
            ->sendToDatabase($user);
    }

    public function failed(Throwable $exception): void
    {
        Log::error("ProbeStreams orchestrator failed: {$exception->getMessage()}");
    }
}
