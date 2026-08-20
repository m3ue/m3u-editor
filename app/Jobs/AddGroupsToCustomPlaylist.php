<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Services\PlaylistService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\Tags\Tag;
use Throwable;

class AddGroupsToCustomPlaylist implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * Create a new job instance.
     *
     * @param  array<int>  $groupIds  IDs of Group or Category records to process
     * @param  array<string, mixed>  $data  Form data: mode, category, new_category
     */
    public function __construct(
        public int $userId,
        public array $groupIds,
        public int $customPlaylistId,
        public array $data,
        public string $type = 'channel',
    ) {
        $this->onQueue('import');
    }

    /**
     * Execute the job.
     *
     * Resolves each group's tag and item IDs up front (tags are created here
     * because Tag::findOrCreate is not race-safe across parallel workers), then
     * fans the pivot/tag writes out to parallel AddItemsToCustomPlaylistChunk
     * jobs via Bus::batch(), so very large groups (100k+ channels) finish
     * quickly and never exceed a single job's timeout.
     */
    public function handle(): void
    {
        $playlist = CustomPlaylist::findOrFail($this->customPlaylistId);

        $meta = PlaylistService::resolveCustomPlaylistRelationMeta($playlist, $this->type);
        $sharedTag = PlaylistService::resolveSharedTagForMode($playlist, $this->data, $meta['tagType']);
        $mode = $this->data['mode'] ?? 'select';

        $chunkJobs = [];
        foreach ($this->groupIds as $groupId) {
            $group = $meta['isSeries']
                ? Category::find($groupId)
                : Group::find($groupId);

            if (! $group) {
                continue;
            }

            // For 'original' mode, derive the tag name from the group/category model
            $tag = $sharedTag;
            if ($mode === 'original') {
                $originalName = $group->name ?? $group->name_internal ?? null;
                if ($originalName === null || trim((string) $originalName) === '') {
                    continue;
                }
                $tag = Tag::findOrCreate($originalName, $meta['tagType']);
                $playlist->attachTag($tag);
            }

            // Chunk through the group's item IDs (key column only, no full models) to keep
            // the reads memory-bounded on large groups
            $relation = $group->{$meta['relation']}();
            $relation->select($relation->getRelated()->getQualifiedKeyName())->chunkById(PlaylistService::CUSTOM_PLAYLIST_CHUNK_SIZE, function ($items) use (&$chunkJobs, $tag): void {
                $chunkJobs[] = new AddItemsToCustomPlaylistChunk(
                    customPlaylistId: $this->customPlaylistId,
                    itemIds: $items->pluck('id')->all(),
                    tagId: $tag?->id,
                    type: $this->type,
                );
            });
        }

        PlaylistService::dispatchCustomPlaylistBatch(
            chunkJobs: $chunkJobs,
            batchName: 'add-groups-to-custom-playlist',
            userId: $this->userId,
            completedTitle: __('Items added to custom playlist'),
            completedBody: __('The selected items have been added to the chosen custom playlist.'),
            failedTitle: __('Failed to add items to custom playlist'),
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
            title: __('Failed to add items to custom playlist'),
            body: __('Please view your notifications for details.'),
            databaseBody: $exception->getMessage(),
        );
    }
}
