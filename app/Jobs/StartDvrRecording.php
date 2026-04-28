<?php

namespace App\Jobs;

use App\Enums\DvrRecordingStatus;
use App\Models\DvrRecording;
use App\Services\DvrRecorderService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * StartDvrRecording — Spawn an ffmpeg process for a scheduled recording.
 *
 * ShouldBeUnique prevents the scheduler from queuing a second start job for
 * the same recording while one is already pending or processing, removing the
 * need to pre-transition the recording's status before the job runs.
 */
class StartDvrRecording implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $recordingId)
    {
        $this->onQueue('dvr');
    }

    public function uniqueId(): string
    {
        return (string) $this->recordingId;
    }

    public function handle(DvrRecorderService $recorder): void
    {
        $recording = DvrRecording::with(['dvrSetting.playlist', 'user', 'channel'])->find($this->recordingId);

        if (! $recording) {
            Log::warning("StartDvrRecording: recording {$this->recordingId} not found");
            $this->fail(new \Exception("StartDvrRecording: recording {$this->recordingId} not found"));

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
