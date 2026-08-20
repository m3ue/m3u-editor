<?php

namespace App\Jobs;

use App\Models\CustomPlaylist;
use App\Services\PlaylistService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DetachItemsFromCustomPlaylist implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * Create a new job instance.
     *
     * @param  array<int>  $itemIds  IDs of Channel or Series records to detach
     */
    public function __construct(
        public int $userId,
        public array $itemIds,
        public int $customPlaylistId,
        public string $type = 'channel',
    ) {
        $this->onQueue('import');
    }

    /**
     * Execute the job.
     *
     * Fans the pivot/tag deletes out to parallel DetachItemsFromCustomPlaylistChunk
     * jobs via Bus::batch(), so detaching very large selections (100k+ items)
     * finishes quickly and never exceeds a single job's timeout.
     */
    public function handle(): void
    {
        CustomPlaylist::findOrFail($this->customPlaylistId);

        $chunkJobs = [];
        foreach (array_chunk($this->itemIds, PlaylistService::CUSTOM_PLAYLIST_CHUNK_SIZE) as $chunk) {
            $chunkJobs[] = new DetachItemsFromCustomPlaylistChunk(
                customPlaylistId: $this->customPlaylistId,
                itemIds: $chunk,
                type: $this->type,
            );
        }

        PlaylistService::dispatchCustomPlaylistBatch(
            chunkJobs: $chunkJobs,
            batchName: 'detach-items-from-custom-playlist',
            userId: $this->userId,
            completedTitle: __('Items detached from custom playlist'),
            completedBody: __('The selected items have been detached from the custom playlist.'),
            failedTitle: __('Failed to detach items from custom playlist'),
        );
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        PlaylistService::notifyCustomPlaylistOperation(
            userId: $this->userId,
            success: false,
            title: __('Failed to detach items from custom playlist'),
            body: __('Please view your notifications for details.'),
            databaseBody: $exception->getMessage(),
        );
    }
}
