<?php

namespace App\Listeners;

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Jobs\GenerateEpgCache;
use App\Jobs\MergeChannels;
use App\Jobs\ProbeChannelStreams;
use App\Jobs\ProcessChannelScrubber;
use App\Jobs\RunPlaylistFindReplaceRules;
use App\Jobs\RunPlaylistSortAlpha;
use App\Jobs\RunPostProcess;
use App\Jobs\SyncPlexDvrJob;
use App\Models\Epg;
use App\Models\Playlist;
use App\Plugins\PluginHookDispatcher;
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
                // Handle saved find & replace rules and sort alpha if enabled
                $this->dispatchNameProcessingPipeline($playlist);

                // Handle channel merge & scrubbers if enabled
                $this->dispatchChannelScanJobs($playlist);

                // Sync Plex DVR channel maps (lineup may have changed)
                dispatch(new SyncPlexDvrJob(trigger: 'playlist_sync'));
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

                // Dispatch stream probing job if enabled for this playlist
                if ($playlist->auto_probe_streams ?? false) {
                    Bus::chain([new ProbeChannelStreams(playlistId: $playlist->id)])->dispatch();
                }
            }
        }
        if ($event->model instanceof Epg) {
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
                dispatch(new SyncPlexDvrJob(trigger: 'epg_sync'));
            }
        }
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
    private function dispatchChannelScanJobs(Playlist $playlist): void
    {
        $mergeJob = ($playlist->auto_merge_channels_enabled ?? false)
            ? $this->getMergeJob($playlist)
            : null;

        $scrubberJobs = $playlist->channelScrubbers()
            ->where('recurring', true)->get()
            ->map(fn ($scrubber) => new ProcessChannelScrubber($scrubber->id))
            ->toArray();

        if ($mergeJob) {
            Bus::chain([$mergeJob, ...$scrubberJobs])->dispatch();
        } elseif (! empty($scrubberJobs)) {
            Bus::chain($scrubberJobs)->dispatch();
        }
    }

    /**
     * Dispatch post-processes for a model after sync.
     */
    private function dispatchPostProcessJobs(Playlist|Epg $model, $lastSync = null): void
    {
        $model->postProcesses()->where([
            ['event', 'synced'],
            ['enabled', true],
        ])->get()->each(function ($postProcess) use ($model, $lastSync) {
            dispatch(new RunPostProcess(
                $postProcess,
                $model,
                $lastSync
            ));
        });
    }

    /**
     * Handle auto-merge channels after playlist sync.
     */
    private function getMergeJob(Playlist $playlist): ?MergeChannels
    {
        // Get auto-merge configuration
        $config = $playlist->auto_merge_config ?? [];
        $useResolution = $config['check_resolution'] ?? false;
        $forceCompleteRemerge = $config['force_complete_remerge'] ?? false;
        $preferCatchupAsPrimary = $config['prefer_catchup_as_primary'] ?? false;
        $newChannelsOnly = $config['new_channels_only'] ?? true; // Default to true for new channels only
        $preferredPlaylistId = $config['preferred_playlist_id'] ?? null;
        $failoverPlaylists = $config['failover_playlists'] ?? [];
        $deactivateFailover = (bool) ($playlist->auto_merge_deactivate_failover ?? false);

        // Build the playlists collection for merging
        // Start with the current playlist
        $playlists = collect([['playlist_failover_id' => $playlist->id]]);

        // Add any additional failover playlists from config
        if (! empty($failoverPlaylists)) {
            foreach ($failoverPlaylists as $failover) {
                $failoverId = is_array($failover) ? ($failover['playlist_failover_id'] ?? null) : $failover;
                if ($failoverId && $failoverId != $playlist->id) {
                    $playlists->push(['playlist_failover_id' => $failoverId]);
                }
            }
        }

        // Determine the preferred playlist ID (use configured one or fallback to current playlist)
        $effectivePlaylistId = $preferredPlaylistId ? (int) $preferredPlaylistId : $playlist->id;

        // Build weighted config if any weighted priority options are set
        $weightedConfig = $this->buildWeightedConfig($config);

        // Dispatch the merge job
        return new MergeChannels(
            user: $playlist->user,
            playlists: $playlists,
            playlistId: $effectivePlaylistId,
            checkResolution: $useResolution,
            deactivateFailoverChannels: $deactivateFailover,
            forceCompleteRemerge: $forceCompleteRemerge,
            preferCatchupAsPrimary: $preferCatchupAsPrimary,
            weightedConfig: $weightedConfig,
            newChannelsOnly: $newChannelsOnly,
            regexPatterns: ! empty($config['regex_patterns'] ?? []) ? $config['regex_patterns'] : null,
        );
    }

    /**
     * Build weighted config array from playlist config if any weighted options are set
     */
    private function buildWeightedConfig(array $config): ?array
    {
        // Check if any weighted priority options are configured
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
     * Post-process an EPG after a successful sync.
     */
    private function postProcessEpg(Epg $epg)
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
