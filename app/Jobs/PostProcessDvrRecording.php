<?php

namespace App\Jobs;

use App\Enums\DvrRecordingStatus;
use App\Enums\DvrRuleType;
use App\Models\DvrRecording;
use App\Services\DvrPostProcessorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    /**
     * One post-processing run per recording at a time. The job is dispatched from the
     * proxy callback, the recorder's stop fallback and two "re-run" actions, so two
     * attempts can be queued for one recording; running them together would write the
     * same output file and temp directory. A duplicate that arrives while one is running
     * is dropped (that run finishes the work). If a worker dies the lock expires after
     * timeout + 5 min, before the queue re-delivers the job (retry_after is 130 min).
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->recordingId))->dontRelease()->expireAfter($this->timeout + 300)];
    }

    public function handle(DvrPostProcessorService $postProcessor): void
    {
        $recording = DvrRecording::with(['dvrSetting.playlist', 'user', 'channel'])->find($this->recordingId);

        if (! $recording) {
            Log::warning("PostProcessDvrRecording: recording {$this->recordingId} not found");
            $this->fail(new \Exception("PostProcessDvrRecording: recording {$this->recordingId} not found"));

            return;
        }

        // Skip cancelled recordings — they should not be post-processed
        if ($recording->status === DvrRecordingStatus::Cancelled) {
            // Delete "once" rules when the recording was cancelled — they're one-shot
            $rule = $recording->recordingRule;
            if ($rule && $rule->type === DvrRuleType::Once) {
                $rule->delete();
            }

            Log::info("PostProcessDvrRecording: recording {$this->recordingId} is Cancelled — skipping post-processing", [
                'recording_id' => $this->recordingId,
            ]);

            return;
        }

        $postProcessor->run($recording);
    }
}
