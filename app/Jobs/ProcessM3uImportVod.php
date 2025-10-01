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

        // Fetch metadata, if enabled
        if ($playlist->auto_fetch_vod_metadata) {
            dispatch(new ProcessVodChannels(
                playlist: $playlist,
                updateProgress: false // Don't update playlist progress
            ));
        }

        // Sync stream files, if enabled
        if ($playlist->sync_stream_files_on_import) {
            // Process stream file syncing
            dispatch(new SyncVodStrmFiles(
                playlist: $playlist
            ));
        }

        // All done! Nothing else to do ;)
    }
}
