<?php

namespace App\Jobs;

use App\Models\DvrRecording;
use App\Services\DvrRecorderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * StartDvrRecording — Spawn an ffmpeg process for a scheduled recording.
 */
class StartDvrRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $recordingId)
    {
        $this->onQueue('dvr');
    }

    public function handle(DvrRecorderService $recorder): void
    {
        $recording = DvrRecording::find($this->recordingId);

        if (! $recording) {
            Log::warning("StartDvrRecording: recording {$this->recordingId} not found");

            return;
        }

        $recorder->start($recording);
    }
}
