<?php

namespace App\Listeners;

use App\Events\PlaylistCreated;
use App\Events\PlaylistDeleted;
use App\Events\PlaylistUpdated;
use App\Jobs\ProcessM3uImport;
use App\Jobs\RunPostProcess;
use App\Services\ProfileService;

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

        // Create primary profile if profiles are enabled on new playlist
        if ($playlist->profiles_enabled) {
            $this->ensurePrimaryProfileExists($playlist);
        }

        dispatch(new ProcessM3uImport(playlist: $playlist, isNew: true));
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

        // Handle primary profile creation when profiles are enabled
        // Check both when the setting changes AND when it's already enabled (to fix missing profiles)
        if ($playlist->profiles_enabled) {
            $this->ensurePrimaryProfileExists($playlist);
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
