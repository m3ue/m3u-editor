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
     */
    public function __construct(
        public Playlist $playlist,
        public bool $isNew,
        public string $batchNo
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $playlist = $this->playlist;

        $jobs = [];

        // Fetch metadata, if enabled
        if ($playlist->auto_fetch_vod_metadata) {
            $jobs[] = new ProcessVodChannels(
                playlist: $playlist,
                updateProgress: false // Don't update playlist progress
            );
        }

        // Sync stream files, if enabled
        if ($playlist->auto_sync_vod_stream_files) {
            // Process stream file syncing
            $jobs[] = new SyncVodStrmFiles(
                playlist: $playlist
            );
        }

        // Dispatch jobs in sequence
        if (! empty($jobs)) {
            Bus::chain($jobs)->dispatch();
        }

        // All done! Nothing else to do ;)
    }
}
