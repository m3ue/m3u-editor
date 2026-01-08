<?php

namespace App\Listeners;

use App\Events\StreamingStarted;
use Illuminate\Support\Facades\Log;

class StreamingStartListener
{
    /**
     * Handle the event.
     */
    public function handle(StreamingStarted $event): void
    {
        Log::channel('ffmpeg')->info('StreamingStarted event fired for playlist: '.$event->uuid);

        // Additional logic can be added here if needed...
    }
}
