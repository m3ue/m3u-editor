<?php

namespace App\Jobs;

use App\Models\CustomPlaylist;
use App\Services\PlaylistService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;
use Throwable;

class AddItemsToCustomPlaylist implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * Create a new job instance.
     *
     * @param  array<int>  $itemIds  IDs of Channel or Series records to add
     * @param  array<string, mixed>  $data  Form data: mode, category, new_category
     */
    public function __construct(
        public int $userId,
        public array $itemIds,
        public int $customPlaylistId,
        public array $data,
        public string $type = 'channel',
    ) {
        $this->onQueue('import');
    }

    /**
     * Execute the job.
     *
     * Resolves (and pre-creates) the tags the items need, then fans the actual
     * pivot/tag writes out to parallel AddItemsToCustomPlaylistChunk jobs via
     * Bus::batch(), so very large selections (100k+ items) finish quickly and
     * never exceed a single job's timeout.
     */
    public function handle(): void
    {
        $playlist = CustomPlaylist::findOrFail($this->customPlaylistId);

        $meta = PlaylistService::resolveCustomPlaylistRelationMeta($playlist, $this->type);
        $sharedTag = PlaylistService::resolveSharedTagForMode($playlist, $this->data, $meta['tagType']);
        $mode = $this->data['mode'] ?? 'select';

        $chunkJobs = $mode === 'original'
            ? $this->buildOriginalNameChunkJobs($meta, $playlist)
            : $this->buildChunkJobs($this->itemIds, $sharedTag?->id);

        PlaylistService::dispatchCustomPlaylistBatch(
            chunkJobs: $chunkJobs,
            batchName: 'add-items-to-custom-playlist',
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

    /**
     * Split item IDs into chunk jobs that all apply the same (optional) tag.
     *
     * @param  array<int>  $itemIds
     * @return array<int, AddItemsToCustomPlaylistChunk>
     */
    private function buildChunkJobs(array $itemIds, ?int $tagId): array
    {
        $chunkJobs = [];
        foreach (array_chunk($itemIds, PlaylistService::CUSTOM_PLAYLIST_CHUNK_SIZE) as $chunk) {
            $chunkJobs[] = new AddItemsToCustomPlaylistChunk(
                customPlaylistId: $this->customPlaylistId,
                itemIds: $chunk,
                tagId: $tagId,
                type: $this->type,
            );
        }

        return $chunkJobs;
    }

    /**
     * Build chunk jobs for mode 'original': bucket the items by their own group/
     * category name, resolved from each item's own row so the selection can span
     * many groups. Names are looked up in bounded slices but bucketed across the
     * whole selection, so interleaved group names do not fragment into many tiny
     * chunk jobs. The tags are created and attached to the playlist here, before
     * the parallel chunk jobs run, because Tag::findOrCreate is not race-safe.
     * Items without a group/category name are still added, just untagged.
     *
     * @param  array<string, mixed>  $meta
     * @return array<int, AddItemsToCustomPlaylistChunk>
     */
    private function buildOriginalNameChunkJobs(array $meta, CustomPlaylist $playlist): array
    {
        $itemIdsByName = [];
        $untaggedItemIds = [];

        foreach (array_chunk($this->itemIds, PlaylistService::CUSTOM_PLAYLIST_CHUNK_SIZE) as $chunk) {
            $namesByItemId = $meta['isSeries']
                ? DB::table('series')
                    ->join('categories', 'series.category_id', '=', 'categories.id')
                    ->whereIn('series.id', $chunk)
                    ->pluck('categories.name', 'series.id')
                : DB::table('channels')
                    ->whereIn('id', $chunk)
                    ->pluck('group', 'id');

            foreach ($chunk as $itemId) {
                $name = $namesByItemId[$itemId] ?? null;
                if ($name === null || trim((string) $name) === '') {
                    $untaggedItemIds[] = $itemId;
                } else {
                    $itemIdsByName[$name][] = $itemId;
                }
            }
        }

        $chunkJobs = [];
        foreach ($itemIdsByName as $name => $ids) {
            $tag = Tag::findOrCreate((string) $name, $meta['tagType']);
            $playlist->attachTag($tag);

            $chunkJobs = [...$chunkJobs, ...$this->buildChunkJobs($ids, $tag->id)];
        }

        return [...$chunkJobs, ...$this->buildChunkJobs($untaggedItemIds, null)];
    }
}
