<?php

namespace App\Jobs;

use App\Models\DvrRecording;
use App\Services\DvrRecorderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * StopDvrRecording — Signal the proxy to stop a running DVR broadcast.
 *
 * The proxy handles graceful FFmpeg shutdown and fires a callback to
 * DvrCallbackController when done, which dispatches PostProcessDvrRecording.
 * No blocking sleep loops needed here.
 */
class StopDvrRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public int $recordingId)
    {
        $this->onQueue('dvr');
    }

    public function handle(DvrRecorderService $recorder): void
    {
        $recording = DvrRecording::find($this->recordingId);

        if (! $recording) {
            Log::warning("StopDvrRecording: recording {$this->recordingId} not found");

            return;
        }

        $recorder->stop($recording);
    }
}
