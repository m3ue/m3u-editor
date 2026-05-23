<?php

namespace App\Jobs;

use App\Models\DvrRecording;
use App\Services\DvrRecorderService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * StopDvrRecording — Signal the proxy to stop a running DVR broadcast.
 *
 * The proxy handles graceful FFmpeg shutdown and fires a callback to
 * DvrCallbackController when done, which dispatches PostProcessDvrRecording.
 * No blocking sleep loops needed here.
 *
 * ShouldBeUnique prevents the scheduler from queuing a second stop job for the
 * same recording on back-to-back ticks, removing the need to pre-transition the
 * recording's status before the job runs.
 */
class StopDvrRecording implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

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
            Log::warning("StopDvrRecording: recording {$this->recordingId} not found");
            $this->fail(new \Exception("StopDvrRecording: recording {$this->recordingId} not found"));

            return;
        }

        $recorder->stop($recording);
    }
}
