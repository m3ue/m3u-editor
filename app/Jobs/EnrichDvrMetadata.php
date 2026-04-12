<?php

namespace App\Jobs;

use App\Models\DvrRecording;
use App\Services\DvrMetadataEnricherService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * EnrichDvrMetadata — Fetch TMDB / TVMaze metadata for a completed recording.
 */
class EnrichDvrMetadata implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $recordingId)
    {
        $this->onQueue('dvr-meta');
    }

    public function handle(DvrMetadataEnricherService $enricher): void
    {
        $recording = DvrRecording::find($this->recordingId);

        if (! $recording) {
            Log::warning("EnrichDvrMetadata: recording {$this->recordingId} not found");

            return;
        }

        $enricher->enrich($recording);

        IntegrateDvrRecordingToVod::dispatch($recording->id);
    }
}
