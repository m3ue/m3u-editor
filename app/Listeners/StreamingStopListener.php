<?php

namespace App\Listeners;

use App\Events\StreamingStopped;
use Illuminate\Support\Facades\Log;

class StreamingStopListener
{
    /**
     * Handle the event.
     */
    public function handle(StreamingStopped $event): void
    {
        Log::channel('ffmpeg')->info('StreamingStopped event fired for playlist: '.$event->playlistId);

        // Additional logic can be added here if needed...
    }
}
