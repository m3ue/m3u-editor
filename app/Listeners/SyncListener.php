<?php

namespace App\Listeners;

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Jobs\GenerateEpgCache;
use App\Jobs\MergeChannels;
use App\Jobs\RunPostProcess;
use App\Models\Epg;
use Filament\Notifications\Notification;
use Throwable;

class SyncListener
{
    /**
     * Handle the event.
     */
    public function handle(SyncCompleted $event): void
    {
        if ($event->model instanceof \App\Models\Playlist) {
            $playlist = $event->model;
            $lastSync = $playlist->syncStatuses()->first();

            // Handle auto-merge channels if enabled
            if ($playlist->auto_merge_channels_enabled && $playlist->status === Status::Completed) {
                $this->handleAutoMergeChannels($playlist, $event->newChannelIds);
            }

            // Handle post-processes
            $playlist->postProcesses()->where([
                ['event', 'synced'],
                ['enabled', true],
            ])->get()->each(function ($postProcess) use ($playlist, $lastSync) {
                dispatch(new RunPostProcess(
                    $postProcess,
                    $playlist,
                    $lastSync
                ));
            });
        }
        if ($event->model instanceof \App\Models\Epg) {
            $event->model->postProcesses()->where([
                ['event', 'synced'],
                ['enabled', true],
            ])->get()->each(function ($postProcess) use ($event) {
                dispatch(new RunPostProcess(
                    $postProcess,
                    $event->model
                ));
            });

            // Generate EPG cache if sync was successful
            if ($event->model->status === Status::Completed) {
                $this->postProcessEpg($event->model);
            }
        }
    }

    /**
     * Handle auto-merge channels after playlist sync.
     *
     * @param  array<int>|null  $newChannelIds  Optional array of new channel IDs from sync
     */
    private function handleAutoMergeChannels(\App\Models\Playlist $playlist, ?array $newChannelIds = null): void
    {
        try {
            // Get auto-merge configuration
            $config = $playlist->auto_merge_config ?? [];
            $useResolution = $config['check_resolution'] ?? false;
            $forceCompleteRemerge = $config['force_complete_remerge'] ?? false;
            $preferCatchupAsPrimary = $config['prefer_catchup_as_primary'] ?? false;
            $newChannelsOnly = $config['new_channels_only'] ?? true; // Default to true for new channels only
            $preferredPlaylistId = $config['preferred_playlist_id'] ?? null;
            $failoverPlaylists = $config['failover_playlists'] ?? [];
            $deactivateFailover = $playlist->auto_merge_deactivate_failover;

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

            // Determine which channel IDs to pass (only if new_channels_only is enabled)
            $channelIdsToMerge = ($newChannelsOnly && ! empty($newChannelIds)) ? $newChannelIds : null;

            // If new_channels_only is enabled but no new channels, skip merge
            if ($newChannelsOnly && empty($newChannelIds)) {
                return;
            }

            // Build weighted config if any weighted priority options are set
            $weightedConfig = $this->buildWeightedConfig($config);

            // Dispatch the merge job
            dispatch(new MergeChannels(
                user: $playlist->user,
                playlists: $playlists,
                playlistId: $effectivePlaylistId,
                checkResolution: $useResolution,
                deactivateFailoverChannels: $deactivateFailover,
                forceCompleteRemerge: $forceCompleteRemerge,
                preferCatchupAsPrimary: $preferCatchupAsPrimary,
                newChannelIds: $channelIdsToMerge,
                weightedConfig: $weightedConfig,
            ));
        } catch (Throwable $e) {
            // Log error and send notification
            logger()->error('Auto-merge failed for playlist: '.$playlist->name, [
                'playlist_id' => $playlist->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            Notification::make()
                ->title('Auto-merge failed')
                ->body("Failed to auto-merge channels for playlist \"{$playlist->name}\": {$e->getMessage()}")
                ->danger()
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        }
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
