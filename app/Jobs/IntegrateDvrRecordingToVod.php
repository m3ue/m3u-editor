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

    public function __construct(public int $recordingId)
    {
        $this->onQueue('dvr-post');
    }

    public function handle(DvrVodIntegrationService $integrator): void
    {
        $recording = DvrRecording::find($this->recordingId);

        if (! $recording) {
            Log::warning("IntegrateDvrRecordingToVod: recording {$this->recordingId} not found");

            return;
        }

        $integrator->integrateRecording($recording);
    }
}
