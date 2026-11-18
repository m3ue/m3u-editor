<?php

namespace App\Listeners;

use App\Enums\Status;
use App\Enums\SyncRunPhase;
use App\Events\SyncCompleted;
use App\Jobs\AutoSyncGroupsToCustomPlaylist;
use App\Jobs\GenerateEpgCache;
use App\Jobs\MergeChannels;
use App\Jobs\MergeEpisodes;
use App\Jobs\ProbeChannelStreams;
use App\Jobs\ProcessChannelScrubber;
use App\Jobs\RunCustomPlaylistProcessing;
use App\Jobs\RunPlaylistFindReplaceRules;
use App\Jobs\RunPlaylistSortAlpha;
use App\Jobs\RunPostProcess;
use App\Jobs\SyncPlexDvrJob;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Plugins\PluginHookDispatcher;
use App\Services\PlaylistService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

class SyncListener
{
    /**
     * Handle the event.
     */
    public function handle(SyncCompleted $event): void
    {
        if ($event->model instanceof Playlist) {
            $playlist = $event->model;
            $lastSync = $playlist->syncStatuses()->first();

            // Only run the following on completed syncs
            if ($playlist->status === Status::Completed) {
                $syncRun = $event->syncRunId
                    ? SyncRun::where('id', $event->syncRunId)
                        ->where('playlist_id', $playlist->id)
                        ->first()
                    : null;

                // Skip if the pipeline already ran find-replace / sort-alpha as a tracked phase
                if (! $syncRun?->isPhaseComplete(SyncRunPhase::FindReplace)) {
                    $this->dispatchNameProcessingPipeline($playlist);
                }

                // Handle channel merge & scrubbers if enabled.
                // Skip pieces already executed by the tracked pipeline (ChannelMerge runs
                // merge; LiveProbe runs scrubbers + probe — they share the LiveProbe phase).
                $this->dispatchChannelScanJobs(
                    $playlist,
                    skipMerge: (bool) $syncRun?->isPhaseComplete(SyncRunPhase::ChannelMerge),
                    skipLiveProbe: (bool) $syncRun?->isPhaseComplete(SyncRunPhase::LiveProbe),
                );

                // Merge series if enabled, unless the pipeline already ran it as a tracked phase
                if (! $syncRun?->isPhaseComplete(SyncRunPhase::SeriesMerge)) {
                    $seriesMergeJob = self::getSeriesMergeJob($playlist);
                    if ($seriesMergeJob) {
                        dispatch($seriesMergeJob);
                    }
                }

                // Skip if the pipeline already ran custom playlist sync as a tracked phase
                if (! $syncRun?->isPhaseComplete(SyncRunPhase::CustomPlaylistSync)) {
                    $this->dispatchAutoSyncToCustomPlaylistJobs($playlist);
                }

                // Sync Plex DVR channel maps (lineup may have changed)
                SyncPlexDvrJob::dispatchIfConfigured(trigger: 'playlist_sync');
            }

            // Handle Playlist post-processes
            $this->dispatchPostProcessJobs($event->model, $lastSync);
            if ($playlist->status === Status::Completed) {
                app(PluginHookDispatcher::class)->dispatch('playlist.synced', [
                    'playlist_id' => $playlist->id,
                    'user_id' => $playlist->user_id,
                ], [
                    'dry_run' => false,
                    'user_id' => $playlist->user_id,
                ]);
            }
        } elseif ($event->model instanceof CustomPlaylist) {
            $this->dispatchCustomPlaylistProcessing($event->model);
        } elseif ($event->model instanceof Epg) {
            // Handle EPG post-processes
            $this->dispatchPostProcessJobs($event->model);

            // Generate EPG cache if sync was successful
            if ($event->model->status === Status::Completed) {
                app(PluginHookDispatcher::class)->dispatch('epg.synced', [
                    'epg_id' => $event->model->id,
                    'user_id' => $event->model->user_id,
                ], [
                    'user_id' => $event->model->user_id,
                ]);

                $this->postProcessEpg($event->model);

                // Sync Plex DVR (EPG data changed, guide needs refresh)
                SyncPlexDvrJob::dispatchIfConfigured(trigger: 'epg_sync');
            }
        }
    }

    /**
     * Dispatch custom playlist processing after a completed custom playlist sync.
     */
    private function dispatchCustomPlaylistProcessing(CustomPlaylist $customPlaylist): void
    {
        if (! $customPlaylist->hasEnabledProcessingRules()) {
            return;
        }

        dispatch(new RunCustomPlaylistProcessing($customPlaylist));
    }

    /**
     * Group 1: Find & Replace → Sort Alpha.
     *
     * Sort alpha depends on channel names, which Find & Replace may change,
     * so these must run in sequence when both are enabled.
     */
    private function dispatchNameProcessingPipeline(Playlist $playlist): void
    {
        $hasFindReplace = collect($playlist->find_replace_rules ?? [])
            ->contains(fn ($r) => $r['enabled'] ?? false);
        $hasSortAlpha = collect($playlist->sort_alpha_config ?? [])
            ->contains(fn ($r) => $r['enabled'] ?? false);

        if ($hasFindReplace && $hasSortAlpha) {
            Bus::chain([
                new RunPlaylistFindReplaceRules($playlist),
                new RunPlaylistSortAlpha($playlist),
            ])->dispatch();
        } elseif ($hasFindReplace) {
            dispatch(new RunPlaylistFindReplaceRules($playlist));
        } elseif ($hasSortAlpha) {
            dispatch(new RunPlaylistSortAlpha($playlist));
        }
    }

    /**
     * Group 2. Merge Channels → Channel Scrubber.
     *
     * Merge uses `stream_id` and doesn't rely on name/titles, so can be run in parallel with name processing jobs.
     * However, channel scrubber jobs should be dispatched after the merge channels job completes,
     * to ensure they run against the updated channel list and avoid potential conflicts with the merge process.
     */
    private function dispatchChannelScanJobs(Playlist $playlist, bool $skipMerge = false, bool $skipLiveProbe = false): void
    {
        // Merge channels first (Live, then VOD, each when enabled)
        $mergeJobs = $skipMerge ? [] : self::getChannelMergeJobs($playlist);

        // Run scrubbers after merge completes (if enabled).
        // Scrubbers are bundled into the LiveProbe phase when the pipeline runs them,
        // so honor skipLiveProbe here too.
        $scrubberJobs = $skipLiveProbe
            ? []
            : $playlist->channelScrubbers()
                ->where('recurring', true)->get()
                ->map(fn ($scrubber) => new ProcessChannelScrubber($scrubber->id))
                ->toArray();

        // Run probe last so it waits for merge and scrubber jobs to complete.
        $probeJob = (! $skipLiveProbe && ($playlist->auto_probe_streams ?? false))
           ? (new ProbeChannelStreams(
               playlistId: $playlist->id,
               onlyUnprobed: (bool) ($playlist->auto_probe_streams_only_unprobed ?? true),
               includeDisabled: (bool) ($playlist->auto_probe_streams_include_disabled ?? false),
           ))
           : null;

        $chain = $mergeJobs;
        if (count($scrubberJobs) > 0) {
            $chain = array_merge($chain, $scrubberJobs);
        }
        if ($probeJob) {
            $chain[] = $probeJob;
        }
        if (count($chain) > 0) {
            Bus::chain($chain)->dispatch();
        }
    }

    /**
     * Dispatch post-processes for a model after sync.
     */
    private function dispatchPostProcessJobs(Playlist|Epg $model, mixed $lastSync = null): void
    {
        $model->postProcesses()
            ->where('event', 'synced')
            ->where('enabled', true)
            ->get()
            ->each(fn ($postProcess) => dispatch(new RunPostProcess($postProcess, $model, $lastSync)));
    }

    /**
     * The channel merge jobs a sync runs: Live when auto_merge_channels_enabled is on,
     * then VOD when auto_merge_config.merge_vod is on.
     *
     * @return array<int, MergeChannels>
     */
    public static function getChannelMergeJobs(Playlist $playlist): array
    {
        $jobs = [];

        if ($playlist->auto_merge_channels_enabled ?? false) {
            $jobs[] = self::getMergeJob($playlist, 'live');
        }

        if (self::mergesVod($playlist)) {
            $jobs[] = self::getMergeJob($playlist, 'vod');
        }

        return $jobs;
    }

    public static function mergesVod(Playlist $playlist): bool
    {
        return (bool) ($playlist->auto_merge_config['merge_vod'] ?? false);
    }

    public static function mergesSeries(Playlist $playlist): bool
    {
        return (bool) ($playlist->auto_merge_config['merge_series'] ?? false);
    }

    /**
     * Build a MergeChannels job from playlist config for one content type. Live always
     * matches by stream ID; VOD uses the configured VOD Merge key.
     *
     * Public/static so SyncPipelineService and the channel scrubber can reuse this
     * builder. Callers decide whether that content type is enabled.
     */
    public static function getMergeJob(Playlist $playlist, string $contentType = 'live'): MergeChannels
    {
        $config = $playlist->auto_merge_config ?? [];
        $useResolution = $config['check_resolution'] ?? false;
        $forceCompleteRemerge = $config['force_complete_remerge'] ?? false;
        $preferCatchupAsPrimary = $config['prefer_catchup_as_primary'] ?? false;
        $newChannelsOnly = $config['new_channels_only'] ?? true;
        $deactivateFailover = (bool) ($playlist->auto_merge_deactivate_failover ?? false);
        $scrubberAwareMasterSelection = (bool) ($config['scrubber_aware_master_selection'] ?? false);

        ['playlists' => $playlists, 'playlistId' => $effectivePlaylistId] = self::mergeSources($playlist);

        return new MergeChannels(
            user: $playlist->user,
            playlists: $playlists,
            playlistId: $effectivePlaylistId,
            checkResolution: $useResolution,
            deactivateFailoverChannels: $deactivateFailover,
            forceCompleteRemerge: $forceCompleteRemerge,
            preferCatchupAsPrimary: $preferCatchupAsPrimary,
            weightedConfig: self::buildWeightedConfig($config),
            newChannelsOnly: $newChannelsOnly,
            regexPatterns: ! empty($config['regex_patterns'] ?? []) ? $config['regex_patterns'] : null,
            fallbackMergeConfig: PlaylistService::buildMergeFallbackConfig($config),
            contentType: $contentType,
            mergeKey: $contentType === 'vod' ? ($config['merge_key'] ?? 'stream_id') : 'stream_id',
            scrubberAwareMasterSelection: $scrubberAwareMasterSelection,
        );
    }

    /**
     * Build a MergeEpisodes job when the playlist opts in to merging series after sync.
     * Uses the same preferred and failover playlists as the channel auto-merge, with the
     * merge's defaults so existing primaries stay stable from sync to sync.
     */
    public static function getSeriesMergeJob(Playlist $playlist): ?MergeEpisodes
    {
        if (! self::mergesSeries($playlist)) {
            return null;
        }

        ['playlists' => $playlists, 'playlistId' => $effectivePlaylistId] = self::mergeSources($playlist);

        return new MergeEpisodes(
            user: $playlist->user,
            playlists: $playlists,
            playlistId: $effectivePlaylistId,
        );
    }

    /**
     * The playlists an auto-merge covers (this playlist plus its failover playlists)
     * and the playlist preferred as master.
     *
     * @return array{playlists: Collection<int, array{playlist_failover_id: int|string}>, playlistId: int}
     */
    private static function mergeSources(Playlist $playlist): array
    {
        $config = $playlist->auto_merge_config ?? [];
        $preferredPlaylistId = $config['preferred_playlist_id'] ?? null;

        $playlists = collect([['playlist_failover_id' => $playlist->id]]);

        foreach ($config['failover_playlists'] ?? [] as $failover) {
            $failoverId = is_array($failover) ? ($failover['playlist_failover_id'] ?? null) : $failover;
            if ($failoverId && $failoverId != $playlist->id) {
                $playlists->push(['playlist_failover_id' => $failoverId]);
            }
        }

        return [
            'playlists' => $playlists,
            'playlistId' => $preferredPlaylistId ? (int) $preferredPlaylistId : $playlist->id,
        ];
    }

    /**
     * Build weighted config array from playlist config if any weighted options are set
     */
    private static function buildWeightedConfig(array $config): ?array
    {
        $hasWeightedOptions = ! empty($config['priority_attributes'])
            || ! empty($config['group_priorities'])
            || ! empty($config['priority_keywords'])
            || isset($config['prefer_codec'])
            || ($config['exclude_disabled_groups'] ?? false);

        if (! $hasWeightedOptions) {
            return null; // Use legacy behavior
        }

        return [
            'priority_attributes' => $config['priority_attributes'] ?? null,
            'group_priorities' => $config['group_priorities'] ?? [],
            'priority_keywords' => $config['priority_keywords'] ?? [],
            'prefer_codec' => $config['prefer_codec'] ?? null,
            'exclude_disabled_groups' => $config['exclude_disabled_groups'] ?? false,
        ];
    }

    /**
     * Dispatch auto-sync jobs to push configured source groups into custom playlists.
     * Runs after each successful playlist sync.
     */
    private function dispatchAutoSyncToCustomPlaylistJobs(Playlist $playlist): void
    {
        $rules = collect($playlist->auto_sync_to_custom_config ?? [])
            ->filter(fn (array $rule): bool => $rule['enabled'] ?? false);

        if ($rules->isEmpty()) {
            return;
        }

        foreach ($rules as $rule) {
            $customPlaylistId = (int) ($rule['custom_playlist_id'] ?? 0);
            $groupIds = array_map('intval', (array) ($rule['groups'] ?? []));
            $type = $rule['type'] === 'series_categories' ? 'series' : 'channel';
            $syncMode = $rule['sync_mode'] ?? 'full_sync';
            $data = [
                'mode' => $rule['mode'] ?? 'original',
                'category' => $rule['category'] ?? null,
                'new_category' => $rule['new_category'] ?? null,
            ];

            if (! $customPlaylistId || empty($groupIds)) {
                continue;
            }

            dispatch(new AutoSyncGroupsToCustomPlaylist(
                userId: $playlist->user_id,
                playlistId: $playlist->id,
                groupIds: $groupIds,
                customPlaylistId: $customPlaylistId,
                data: $data,
                type: $type,
                syncMode: $syncMode,
                channelIds: $type === 'series' ? [] : array_map('intval', (array) ($rule['channels'] ?? [])),
                channelNamePatterns: $type === 'series' ? [] : array_values(array_filter((array) ($rule['channel_name_patterns'] ?? []), 'is_string')),
            ));
        }
    }

    /**
     * Post-process an EPG after a successful sync.
     */
    private function postProcessEpg(Epg $epg): void
    {
        // Update status to Processing (so UI components will continue to refresh) and dispatch cache job
        // IMPORTANT: Set is_cached to false to prevent race condition where users
        // try to read the EPG cache (JSON files) while it's being regenerated
        // Note: Playlist EPG cache files (XML) are NOT cleared here - they remain available
        // for users until the new cache is generated, preventing fallback to slow XML reader
        // Note: processing_started_at and processing_phase will be set by GenerateEpgCache job
        $epg->update([
            'status' => Status::Processing,
            'is_cached' => false,
            'cache_meta' => null,
            'processing_started_at' => null,
            'processing_phase' => null,
        ]);

        // Dispatch cache generation job
        dispatch(new GenerateEpgCache($epg->uuid, notify: true));
    }
}
