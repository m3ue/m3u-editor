<?php

namespace App\Jobs;

use App\Models\DvrRecording;
use App\Services\DvrRecorderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * StopDvrRecording — Gracefully stop a running ffmpeg recording.
 * After stopping, the recording transitions to POST_PROCESSING and
 * PostProcessDvrRecording is dispatched automatically.
 */
class StopDvrRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Allow extra time for graceful stop + SIGKILL fallback */
    public int $timeout = 60;

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

        // Reload to get updated status, then dispatch post-processor
        $recording->refresh();

        PostProcessDvrRecording::dispatch($recording->id)->onQueue('dvr-post');
    }
}
