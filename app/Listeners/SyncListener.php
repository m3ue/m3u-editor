<?php

namespace App\Listeners;

use Throwable;
use App\Enums\PlaylistSourceType;
use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Jobs\GenerateEpgCache;
use App\Jobs\MapPlaylistChannelsToEpg;
use App\Jobs\MergeChannels;
use App\Jobs\RunPostProcess;
use App\Models\Epg;
use App\Models\EpgMap;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

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
                $this->handleAutoMergeChannels($playlist);
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
     */
    private function handleAutoMergeChannels(\App\Models\Playlist $playlist): void
    {
        try {
            // Get auto-merge configuration
            $config = $playlist->auto_merge_config ?? [];
            $useResolution = $config['check_resolution'] ?? false;
            $forceCompleteRemerge = $config['force_complete_remerge'] ?? false;
            $deactivateFailover = $playlist->auto_merge_deactivate_failover;

            // Create a collection containing only the current playlist for merging within itself
            $playlists = collect([['playlist_failover_id' => $playlist->id]]);

            // Dispatch the merge job
            dispatch(new MergeChannels(
                user: $playlist->user,
                playlists: $playlists,
                playlistId: $playlist->id,
                checkResolution: $useResolution,
                deactivateFailoverChannels: $deactivateFailover,
                forceCompleteRemerge: $forceCompleteRemerge
            ));
        } catch (Throwable $e) {
            // Log error and send notification
            logger()->error('Auto-merge failed for playlist: ' . $playlist->name, [
                'playlist_id' => $playlist->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
     * Post-process an EPG after a successful sync.
     */
    private function postProcessEpg(Epg $epg)
    {
        // Update status to Processing (so UI components will continue to refresh) and dispatch cache job
        // Note: processing_started_at and processing_phase will be set by GenerateEpgCache job
        $epg->update([
            'status' => Status::Processing,
            'processing_started_at' => null,
            'processing_phase' => null,
        ]);

        // Dispatch cache generation job
        dispatch(new GenerateEpgCache($epg->uuid, notify: true));
    }
}
