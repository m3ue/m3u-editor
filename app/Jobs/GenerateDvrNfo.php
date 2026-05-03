<?php

namespace App\Jobs;

use App\Models\DvrRecording;
use App\Services\NfoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * GenerateDvrNfo — Write Kodi/Jellyfin/Plex compatible .nfo sidecar files
 * for a completed DVR recording.
 *
 * Behaviour:
 *   - Skips silently when the playlist's DvrSetting has generate_nfo_files=false.
 *   - For series-shaped recordings, writes both episodedetails.nfo (next to the
 *     recording file) and tvshow.nfo (in the parent series folder).
 *   - For movie-shaped recordings, writes a single movie.nfo next to the file.
 *   - Idempotent — NfoService skips writes when the on-disk content is identical.
 *   - Non-fatal on failure: logs and exits, never throws back into the queue
 *     pipeline (post-processing must keep moving).
 */
class GenerateDvrNfo implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 30;

    public function __construct(public readonly int $recordingId)
    {
        $this->onQueue('dvr-meta');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(NfoService $nfo): void
    {
        $recording = DvrRecording::with(['dvrSetting'])->find($this->recordingId);

        if (! $recording) {
            Log::warning("GenerateDvrNfo: recording {$this->recordingId} not found");

            return;
        }

        $setting = $recording->dvrSetting;
        if (! $setting) {
            Log::debug("GenerateDvrNfo: recording {$recording->id} has no DvrSetting — skipping");

            return;
        }

        if (! $setting->generate_nfo_files) {
            Log::debug("GenerateDvrNfo: NFO generation disabled for recording {$recording->id}");

            return;
        }

        if (empty($recording->file_path)) {
            Log::warning("GenerateDvrNfo: recording {$recording->id} has no file_path — skipping");

            return;
        }

        $disk = $setting->storage_disk ?: config('dvr.storage_disk', 'local');

        try {
            if ($nfo->isDvrRecordingSeries($recording)) {
                Log::info("GenerateDvrNfo: writing episode + tvshow NFO for recording {$recording->id}");

                $nfo->generateDvrEpisodeNfo($recording, $disk);
                $nfo->generateDvrShowNfo($recording, $disk);
            } else {
                Log::info("GenerateDvrNfo: writing movie NFO for recording {$recording->id}");

                $nfo->generateDvrMovieNfo($recording, $disk);
            }
        } catch (\Throwable $e) {
            Log::error("GenerateDvrNfo: unexpected error for recording {$recording->id}: {$e->getMessage()}", [
                'exception' => $e,
            ]);
        }
    }
}
