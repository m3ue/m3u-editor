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

        if ($playlist->auto_fetch_vod_metadata) {
            // Metadata fetch dispatches its own internal chain (ProcessVodChannelsChunk × N →
            // ProcessVodChannelsComplete). ProcessVodChannelsComplete will then dispatch TMDB
            // fetch and SyncVodStrmFiles in sequence once all chunks are done — no race condition.
            dispatch(new ProcessVodChannels(
                playlist: $playlist,
                updateProgress: false
            ));
        } elseif ($playlist->auto_sync_vod_stream_files) {
            // No metadata fetch, but stream file sync was requested. Dispatch directly since
            // ProcessVodChannelsComplete won't run (no metadata chain).
            dispatch(new SyncVodStrmFiles(playlist: $playlist));
        }

        // All done! Nothing else to do ;)
    }
}
