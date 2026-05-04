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

    public function __construct(public readonly int $recordingId)
    {
        $this->onQueue('dvr-meta');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(DvrMetadataEnricherService $enricher): void
    {
        $recording = DvrRecording::with(['dvrSetting.playlist', 'user', 'channel'])->find($this->recordingId);

        if (! $recording) {
            Log::warning("EnrichDvrMetadata: recording {$this->recordingId} not found");
            $this->fail(new \Exception("EnrichDvrMetadata: recording {$this->recordingId} not found"));

            return;
        }

        Log::info("DVR metadata enrichment starting for recording {$recording->id}", [
            'recording_id' => $recording->id,
            'title' => $recording->title,
        ]);

        $recording->update(['post_processing_step' => 'Enriching metadata']);

        $enricher->enrich($recording);

        // Refresh dvrSetting in case enrichment touched it.
        $recording->refresh()->loadMissing('dvrSetting');

        if ($recording->dvrSetting?->generate_nfo_files) {
            Log::info("DVR metadata enrichment complete for recording {$recording->id} — dispatching NFO generation", [
                'recording_id' => $recording->id,
            ]);

            GenerateDvrNfo::dispatch($recording->id)->onQueue('dvr-meta');
        }

        Log::info("DVR metadata enrichment complete for recording {$recording->id} — dispatching VOD integration", [
            'recording_id' => $recording->id,
        ]);

        IntegrateDvrRecordingToVod::dispatch($recording->id)->onQueue('dvr-post');
    }
}
