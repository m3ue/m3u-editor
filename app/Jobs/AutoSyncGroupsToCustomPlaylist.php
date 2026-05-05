<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Spatie\Tags\Tag;

class AutoSyncGroupsToCustomPlaylist implements ShouldQueue
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
     * @param  string  $syncMode  'full_sync' removes channels no longer in source groups; 'add_only' never removes
     */
    public function __construct(
        public int $userId,
        public int $playlistId,
        public array $groupIds,
        public int $customPlaylistId,
        public array $data,
        public string $type = 'channel',
        public string $syncMode = 'full_sync',
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $playlist = CustomPlaylist::findOrFail($this->customPlaylistId);
        $user = User::findOrFail($this->userId);

        $isSeries = $this->type === 'series';
        $tagType = $isSeries ? $playlist->uuid.'-category' : $playlist->uuid;
        $syncRelation = $isSeries ? 'series' : 'channels';

        $mode = $this->data['mode'] ?? 'original';
        $tagName = null;

        if ($mode === 'select') {
            $tagName = $this->data['category'] ?? null;
        } elseif ($mode === 'create') {
            $tagName = $this->data['new_category'] ?? null;
        }

        // For select/create modes, create or find the shared tag once upfront for all groups
        $sharedTag = null;
        if ($mode !== 'original' && $tagName) {
            $sharedTag = Tag::findOrCreate($tagName, $tagType);
            $playlist->attachTag($sharedTag);
        }

        $playlistTags = $playlist->tagsWithType($tagType);

        // Resolve pivot table metadata once so we can use insertOrIgnore inside
        // the chunk loop — avoids loading the entire pivot table on every chunk.
        $relation = $playlist->$syncRelation();
        $pivotTable = $relation->getTable();
        $pivotForeignKey = $relation->getForeignPivotKeyName();
        $pivotRelatedKey = $relation->getRelatedPivotKeyName();

        foreach ($this->groupIds as $groupId) {
            $group = $isSeries
                ? Category::find($groupId)
                : Group::find($groupId);

            if (! $group) {
                continue;
            }

            // For 'original' mode, derive the tag name from the group/category model
            $tag = $sharedTag;
            if ($mode === 'original') {
                $originalName = $group->name ?? $group->name_internal ?? null;
                if (! $originalName) {
                    continue;
                }
                $tag = Tag::findOrCreate($originalName, $tagType);
                $playlist->attachTag($tag);
            }

            // Chunk through the group's items to avoid memory exhaustion on large groups.
            // Use insertOrIgnore instead of syncWithoutDetaching so we never load the
            // entire pivot table into PHP memory on each iteration, and existing pivot
            // values (channel_number, sort) are preserved by the conflict-ignore path.
            $group->$syncRelation()->chunkById(1000, function ($items) use ($pivotTable, $pivotForeignKey, $pivotRelatedKey, $playlistTags, $tag): void {
                DB::table($pivotTable)->insertOrIgnore(
                    $items->map(fn ($item): array => [
                        $pivotForeignKey => $this->customPlaylistId,
                        $pivotRelatedKey => $item->id,
                    ])->all()
                );

                if ($tag) {
                    foreach ($items as $item) {
                        $item->detachTags($playlistTags);
                        $item->attachTag($tag);
                    }
                }
            });
        }

        // Full sync: delete pivot rows for items that were previously in the source
        // groups (or have since lost their group assignment) but are no longer there.
        // Done entirely in the database — no PHP-level ID collection or diff needed.
        if ($this->syncMode === 'full_sync') {
            $itemTable = $isSeries ? 'series' : 'channels';
            $groupColumn = $isSeries ? 'category_id' : 'group_id';

            DB::table($pivotTable)
                ->where($pivotForeignKey, $this->customPlaylistId)
                ->whereIn($pivotRelatedKey, function ($query) use ($itemTable, $groupColumn): void {
                    // Items attached to this custom playlist that came from a source group
                    // (or have since lost their group assignment)
                    $query->select('id')
                        ->from($itemTable)
                        ->where('playlist_id', $this->playlistId)
                        ->where(function ($q) use ($groupColumn): void {
                            $q->whereIn($groupColumn, $this->groupIds)
                                ->orWhereNull($groupColumn);
                        });
                })
                ->whereNotIn($pivotRelatedKey, function ($query) use ($itemTable, $groupColumn): void {
                    // Items currently present in the source groups
                    $query->select('id')
                        ->from($itemTable)
                        ->whereIn($groupColumn, $this->groupIds);
                })
                ->delete();
        }

        Notification::make()
            ->success()
            ->title(__('Auto-sync to custom playlist completed'))
            ->body(__('Groups have been synced to the custom playlist for ":playlist".', ['playlist' => $playlist->name]))
            ->broadcast($user);

        Notification::make()
            ->success()
            ->title(__('Auto-sync to custom playlist completed'))
            ->body(__('Groups have been synced to the custom playlist for ":playlist".', ['playlist' => $playlist->name]))
            ->sendToDatabase($user);
    }
}
