<?php

namespace App\Jobs;

use App\Models\DvrRecording;
use App\Services\DvrVodIntegrationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * IntegrateDvrRecordingToVod — Queued job that converts a completed DVR recording into a VOD entry.
 *
 * Dispatched by EnrichDvrMetadata after metadata enrichment completes,
 * or directly by DvrPostProcessorService when metadata enrichment is disabled.
 */
class IntegrateDvrRecordingToVod implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $recordingId)
    {
        $this->onQueue('dvr-post');
    }

    public function handle(DvrVodIntegrationService $integrator): void
    {
        $recording = DvrRecording::with(['dvrSetting.playlist', 'user', 'channel'])->find($this->recordingId);

        if (! $recording) {
            Log::warning("IntegrateDvrRecordingToVod: recording {$this->recordingId} not found");
            $this->fail(new \Exception("IntegrateDvrRecordingToVod: recording {$this->recordingId} not found"));

            return;
        }

        Log::info("DVR VOD integration starting for recording {$recording->id}", [
            'recording_id' => $recording->id,
            'title' => $recording->title,
        ]);

        $recording->update(['post_processing_step' => 'Creating VOD entry']);

        $integrator->integrateRecording($recording);

        // Clear the step label now that the full pipeline is done
        $recording->update(['post_processing_step' => null]);

        // Re-broadcast the current status so connected TV clients know the
        // library entry now exists. The completion broadcast fired from
        // DvrPostProcessorService goes out the instant status flips to
        // Completed, *before* this queued job has a chance to create the
        // Series/Episode/Channel rows — so without this second push, clients
        // refreshed on the first push and saw nothing. broadcastStatus() is
        // safe to call with an unchanged status (issue #1403).
        $recording->broadcastStatus();

        Log::info("DVR VOD integration complete for recording {$recording->id}", [
            'recording_id' => $recording->id,
        ]);
    }
}
