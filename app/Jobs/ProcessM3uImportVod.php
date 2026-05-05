<?php

namespace App\Jobs;

use App\Models\Playlist;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

class ProcessM3uImportVod implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param  ShouldQueue|null  $completionJob  Job dispatched at the very end of the VOD
     *                                           pipeline. Either FireSyncCompletedEvent (VOD-only)
     *                                           or TriggerSeriesImport (VOD→Series sequential).
     */
    public function __construct(
        public Playlist $playlist,
        public bool $isNew,
        public string $batchNo,
        public ?ShouldQueue $completionJob = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $playlist = $this->playlist;

        if ($playlist->auto_fetch_vod_metadata) {
            // Metadata fetch dispatches its own internal chain (ProcessVodChannelsChunk × N →
            // ProcessVodChannelsComplete). ProcessVodChannelsComplete will then dispatch TMDB
            // fetch and SyncVodStrmFiles in sequence once all chunks are done — no race condition.
            dispatch(new ProcessVodChannels(
                playlist: $playlist,
                updateProgress: false,
                completionJob: $this->completionJob,
            ));
        } elseif ($playlist->auto_sync_vod_stream_files) {
            // No metadata fetch, but stream file sync was requested. Dispatch directly since
            // ProcessVodChannelsComplete won't run (no metadata chain).
            $hasFindReplaceRules = collect($playlist->find_replace_rules ?? [])
                ->contains(fn (array $rule): bool => $rule['enabled'] ?? false);

            $strmJobs = $hasFindReplaceRules
                ? [new RunPlaylistFindReplaceRules($playlist), new SyncVodStrmFiles(playlist: $playlist)]
                : [new SyncVodStrmFiles(playlist: $playlist)];

            if ($this->completionJob) {
                $strmJobs[] = $this->completionJob;
            }

            Bus::chain($strmJobs)->dispatch();
        }

        // All done! Nothing else to do ;)
    }
}
