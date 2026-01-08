<?php

namespace App\Listeners;

use App\Events\PlaylistCreated;
use App\Events\PlaylistDeleted;
use App\Events\PlaylistUpdated;
use App\Jobs\ProcessM3uImport;
use App\Jobs\RunPostProcess;

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
        dispatch(new ProcessM3uImport(playlist: $event->playlist, isNew: true));
        $event->playlist->postProcesses()->where([
            ['event', 'created'],
            ['enabled', true],
        ])->get()->each(function ($postProcess) use ($event) {
            dispatch(new RunPostProcess($postProcess, $event->playlist));
        });
    }

    private function handlePlaylistUpdated(PlaylistUpdated $event)
    {
        // Handle playlist updated event
        $event->playlist->postProcesses()->where([
            ['event', 'updated'],
            ['enabled', true],
        ])->get()->each(function ($postProcess) use ($event) {
            dispatch(new RunPostProcess($postProcess, $event->playlist));
        });
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
