<?php

namespace App\Listeners;

use App\Events\EpgCreated;
use App\Events\EpgDeleted;
use App\Events\EpgUpdated;
use App\Jobs\ProcessEpgImport;
use App\Jobs\RunPostProcess;

class EpgListener
{
    /**
     * Handle the event.
     */
    public function handle(EpgCreated|EpgUpdated|EpgDeleted $event): void
    {
        // Check if created, updated, or deleted
        if ($event instanceof EpgCreated) {
            $this->handleEpgCreated($event);
        } elseif ($event instanceof EpgUpdated) {
            $this->handleEpgUpdated($event);
        } elseif ($event instanceof EpgDeleted) {
            $this->handleEpgDeleted($event);
        }
    }

    private function handleEpgCreated(EpgCreated $event)
    {
        dispatch(new ProcessEpgImport($event->epg));
        $event->epg->postProcesses()->where([
            ['event', 'created'],
            ['enabled', true],
        ])->get()->each(function ($postProcess) use ($event) {
            dispatch(new RunPostProcess($postProcess, $event->epg));
        });
    }

    private function handleEpgUpdated(EpgUpdated $event)
    {
        // Handle EPG updated event
        $event->epg->postProcesses()->where([
            ['event', 'updated'],
            ['enabled', true],
        ])->get()->each(function ($postProcess) use ($event) {
            dispatch(new RunPostProcess($postProcess, $event->epg));
        });
    }

    private function handleEpgDeleted(EpgDeleted $event)
    {
        // Handle EPG deleted event
        $event->epg->postProcesses()->where([
            ['event', 'deleted'],
            ['enabled', true],
        ])->get()->each(function ($postProcess) use ($event) {
            dispatch(new RunPostProcess($postProcess, $event->epg));
        });
    }
}
