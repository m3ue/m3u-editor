<?php

namespace App\Jobs;

use App\Models\CustomPlaylist;
use App\Services\PlaylistService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DetachItemsFromCustomPlaylistChunk implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * Create a new job instance.
     *
     * @param  array<int>  $itemIds  IDs of the Channel or Series records in this chunk
     */
    public function __construct(
        public int $customPlaylistId,
        public array $itemIds,
        public string $type = 'channel',
    ) {}

    /**
     * Execute the job.
     *
     * Set-based deletes on disjoint item IDs, so chunks of the same batch can run
     * in parallel across queue workers.
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $playlist = CustomPlaylist::find($this->customPlaylistId);
        if (! $playlist) {
            return;
        }

        $meta = PlaylistService::resolveCustomPlaylistRelationMeta($playlist, $this->type);
        $playlistTagIds = $playlist->{$meta['tagFunction']}()->pluck('tags.id')->all();

        DB::table('taggables')
            ->where('taggable_type', $meta['itemModel'])
            ->whereIn('taggable_id', $this->itemIds)
            ->whereIn('tag_id', $playlistTagIds)
            ->delete();

        DB::table($meta['pivotTable'])
            ->where($meta['pivotForeignKey'], $this->customPlaylistId)
            ->whereIn($meta['pivotRelatedKey'], $this->itemIds)
            ->delete();
    }
}
