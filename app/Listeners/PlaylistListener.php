<?php

namespace App\Listeners;

use App\Enums\Status;
use App\Events\PlaylistCreated;
use App\Events\PlaylistDeleted;
use App\Events\PlaylistUpdated;
use App\Jobs\RunPostProcess;
use App\Services\ProfileService;
use App\Sync\PlaylistSyncDispatcher;
use Illuminate\Support\Facades\Log;

class PlaylistListener
{
    /**
     * Handle the event.
     */
    public function handle(PlaylistCreated|PlaylistUpdated|PlaylistDeleted $event): void
    {
        // Check if created, updated, or deleted
        if ($event instanceof PlaylistCreated) {
            $this->handlePlaylistCreated($event);
        } elseif ($event instanceof PlaylistUpdated) {
            $this->handlePlaylistUpdated($event);
        } elseif ($event instanceof PlaylistDeleted) {
            $this->handlePlaylistDeleted($event);
        }
    }

    private function handlePlaylistCreated(PlaylistCreated $event)
    {
        $playlist = $event->playlist;

        // Network playlists don't need M3U import - they get content from assigned networks
        if ($playlist->is_network_playlist) {
            Log::info('Network playlist created, skipping M3U import', [
                'playlist_id' => $playlist->id,
                'name' => $playlist->name,
            ]);
            $playlist->update(['status' => Status::Completed]);

            return;
        }

        Log::info('Regular playlist created, dispatching M3U import', [
            'playlist_id' => $playlist->id,
            'name' => $playlist->name,
            'is_network_playlist' => $playlist->is_network_playlist,
        ]);

        // Create primary profile if profiles are enabled on new playlist
        if ($playlist->profiles_enabled) {
            $this->ensurePrimaryProfileExists($playlist);
        }

        app(PlaylistSyncDispatcher::class)->dispatch(
            playlist: $playlist,
            trigger: PlaylistSyncDispatcher::TRIGGER_PLAYLIST_CREATED,
            isNew: true,
        );
        $playlist->postProcesses()->where([
            ['event', 'created'],
            ['enabled', true],
        ])->get()->each(function ($postProcess) use ($playlist) {
            dispatch(new RunPostProcess($postProcess, $playlist));
        });
    }

    private function handlePlaylistUpdated(PlaylistUpdated $event)
    {
        $playlist = $event->playlist;

        // Sync the primary profile whenever profiles are enabled.
        // This creates the profile if missing, and updates its URL/credentials
        // when the playlist's provider has changed — fixing stale stream URLs.
        if ($playlist->profiles_enabled) {
            ProfileService::syncPrimaryProfile($playlist);
        }

        // Handle playlist updated event
        $playlist->postProcesses()->where([
            ['event', 'updated'],
            ['enabled', true],
        ])->get()->each(function ($postProcess) use ($playlist) {
            dispatch(new RunPostProcess($postProcess, $playlist));
        });
    }

    /**
     * Ensure a primary profile exists when profiles are enabled.
     */
    private function ensurePrimaryProfileExists($playlist): void
    {
        // Check if primary profile already exists
        $primaryExists = $playlist->profiles()->where('is_primary', true)->exists();

        if (! $primaryExists && $playlist->xtream_config) {
            ProfileService::createPrimaryProfile($playlist);
        }
    }

    private function handlePlaylistDeleted(PlaylistDeleted $event)
    {
        // Handle playlist deleted event
        $event->playlist->postProcesses()->where([
            ['event', 'deleted'],
            ['enabled', true],
        ])->get()->each(function ($postProcess) use ($event) {
            dispatch(new RunPostProcess($postProcess, $event->playlist));
        });
    }
}
