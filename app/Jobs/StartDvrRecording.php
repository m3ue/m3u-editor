<?php

namespace App\Jobs;

use App\Enums\DvrRecordingStatus;
use App\Models\DvrRecording;
use App\Services\DvrRecorderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        try {
            $recorder->start($recording);
        } catch (Throwable $e) {
            Log::error("StartDvrRecording: recording {$this->recordingId} failed to start — {$e->getMessage()}");

            $recording->update([
                'status' => DvrRecordingStatus::Failed->value,
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
