<?php

namespace App\Jobs;

use App\Models\Playlist;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
            // fetch in sequence once all chunks are done. STRM sync (if enabled) is now
            // orchestrated post-sync via StrmSyncPhase rather than chained here.
            dispatch(new ProcessVodChannels(
                playlist: $playlist,
                updateProgress: false,
                completionJob: $this->completionJob,
            ));
        } elseif ($this->completionJob) {
            // No metadata fetch needed; STRM sync (if enabled) is handled by
            // StrmSyncPhase post-sync. Just fire the completion job so the
            // sync pipeline can advance.
            dispatch($this->completionJob);
        }

        // All done! Nothing else to do ;)
    }
}
