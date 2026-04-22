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

        Log::info("DVR metadata enrichment starting for recording {$recording->id}", [
            'recording_id' => $recording->id,
            'title' => $recording->title,
        ]);

        $recording->update(['post_processing_step' => 'Enriching metadata']);

        $enricher->enrich($recording);

        Log::info("DVR metadata enrichment complete for recording {$recording->id} — dispatching VOD integration", [
            'recording_id' => $recording->id,
        ]);

        IntegrateDvrRecordingToVod::dispatch($recording->id);
    }
}
