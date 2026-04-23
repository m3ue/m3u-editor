<?php

namespace App\Jobs;

use App\Models\DvrRecording;
use App\Services\DvrPostProcessorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * PostProcessDvrRecording — Concat HLS → .ts, move to library, dispatch metadata.
 */
class PostProcessDvrRecording implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Allow time for large concat operations */
    public int $timeout = 3600;

    public function __construct(public readonly int $recordingId)
    {
        $this->onQueue('dvr-post');
    }

    public function handle(DvrPostProcessorService $postProcessor): void
    {
        $recording = DvrRecording::with(['dvrSetting.playlist', 'user', 'channel'])->find($this->recordingId);

        if (! $recording) {
            Log::warning("PostProcessDvrRecording: recording {$this->recordingId} not found");
            $this->fail(new \Exception("PostProcessDvrRecording: recording {$this->recordingId} not found"));

            return;
        }

        $postProcessor->run($recording);
    }
}
