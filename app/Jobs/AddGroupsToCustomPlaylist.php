<?php

namespace App\Jobs;

use App\Models\Category;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\Tags\Tag;

class AddGroupsToCustomPlaylist implements ShouldQueue
{
    use Queueable;

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
        $relation = $isSeries ? 'series' : 'channels';
        $syncRelation = $isSeries ? 'series' : 'channels';

        $mode = $this->data['mode'] ?? 'select';
        $tagName = null;

        if ($mode === 'select') {
            $tagName = $this->data['category'] ?? null;
        } elseif ($mode === 'create') {
            $tagName = $this->data['new_category'] ?? null;
        }

        // For select/create modes, create the tag once upfront for all groups
        $sharedTag = null;
        if ($mode !== 'original' && $tagName) {
            $sharedTag = Tag::findOrCreate($tagName, $tagType);
            $playlist->attachTag($sharedTag);
        }

        $playlistTags = $playlist->tagsWithType($tagType);

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

            // Chunk through the group's items to avoid memory exhaustion on large groups
            $group->$relation()->chunkById(1000, function ($items) use ($playlist, $syncRelation, $playlistTags, $tag): void {
                $ids = $items->pluck('id')->all();
                $playlist->$syncRelation()->syncWithoutDetaching($ids);

                if ($tag) {
                    foreach ($items as $item) {
                        $item->detachTags($playlistTags);
                        $item->attachTag($tag);
                    }
                }
            });
        }

        Notification::make()
            ->success()
            ->title(__('Items added to custom playlist'))
            ->body(__('The selected items have been added to the chosen custom playlist.'))
            ->broadcast($user);

        Notification::make()
            ->success()
            ->title(__('Items added to custom playlist'))
            ->body(__('The selected items have been added to the chosen custom playlist.'))
            ->sendToDatabase($user);
    }
}
