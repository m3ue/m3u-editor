<?php

namespace App\Jobs;

use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Series;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Tags\Tag;

class DuplicateCustomPlaylist implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CustomPlaylist $playlist,
        public ?string $name = null,
    ) {}

    public function handle(): void
    {
        DB::beginTransaction();
        try {
            $playlist = $this->playlist;
            $oldUuid = $playlist->uuid;
            $now = now();

            // Create the new custom playlist
            $newPlaylist = $playlist->replicate(except: [
                'id',
                'name',
                'uuid',
                'short_urls_enabled',
                'short_urls',
            ]);
            $newPlaylist->name = $this->name ?? $playlist->name.' (Copy)';
            $newPlaylist->uuid = Str::orderedUuid()->toString();
            $newPlaylist->created_at = $now;
            $newPlaylist->updated_at = $now;
            $newPlaylist->saveQuietly();

            $newUuid = $newPlaylist->uuid;

            // Recreate group tags scoped to the new playlist UUID
            $groupTagMap = [];
            foreach ($playlist->groups()->orderBy('order_column')->get() as $oldTag) {
                $newTag = Tag::findOrCreate("{$oldTag->name}", $newUuid);
                $newTag->order_column = $oldTag->order_column;
                $newTag->save();
                $newPlaylist->attachTag($newTag);
                $groupTagMap[$oldTag->id] = $newTag;
            }

            // Recreate category tags scoped to the new playlist UUID
            $categoryTagMap = [];
            foreach ($playlist->categories()->orderBy('order_column')->get() as $oldTag) {
                $newTag = Tag::findOrCreate("{$oldTag->name}", "{$newUuid}-category");
                $newTag->order_column = $oldTag->order_column;
                $newTag->save();
                $newPlaylist->attachTag($newTag);
                $categoryTagMap[$oldTag->id] = $newTag;
            }

            // Copy the playlist's bouquets. Tag names are recreated byte-identical
            // under the new UUID, so the name-based selections copy verbatim. Alias
            // attachments are deliberately NOT copied: the duplicate has no aliases,
            // and attaching its bouquets to the source's aliases would violate the
            // same-target invariant.
            Bouquet::where('custom_playlist_id', $playlist->id)
                ->cursor()
                ->each(function (Bouquet $bouquet) use ($newPlaylist, $now): void {
                    $copy = $bouquet->replicate(except: ['id']);
                    $copy->custom_playlist_id = $newPlaylist->id;
                    $copy->created_at = $now;
                    $copy->updated_at = $now;
                    // saveQuietly() bypasses the Bouquet::saving ownership guard by design — the
                    // copy keeps the source bouquet's user_id, and $newPlaylist replicated the
                    // same user_id, so the invariant already holds.
                    $copy->saveQuietly();
                });

            // Copy channel links, preserving pivot data and group tag assignments
            DB::table('channel_custom_playlist')
                ->where('custom_playlist_id', $playlist->id)
                ->orderBy('channel_id')
                ->chunk(500, function ($pivotRows) use ($newPlaylist, $groupTagMap, $oldUuid): void {
                    $channelIds = $pivotRows->pluck('channel_id')->all();

                    DB::table('channel_custom_playlist')->insert(
                        $pivotRows->map(fn ($row) => [
                            'channel_id' => $row->channel_id,
                            'custom_playlist_id' => $newPlaylist->id,
                            'channel_number' => $row->channel_number,
                            'sort' => $row->sort,
                        ])->all()
                    );

                    if (! empty($groupTagMap)) {
                        Channel::whereIn('id', $channelIds)
                            ->with(['tags' => fn ($q) => $q->where('type', $oldUuid)])
                            ->get()
                            ->each(function (Channel $channel) use ($groupTagMap): void {
                                $oldTag = $channel->tags->first();
                                if ($oldTag && isset($groupTagMap[$oldTag->id])) {
                                    $channel->attachTag($groupTagMap[$oldTag->id]);
                                }
                            });
                    }
                });

            // Copy series links and category tag assignments
            DB::table('series_custom_playlist')
                ->where('custom_playlist_id', $playlist->id)
                ->orderBy('series_id')
                ->chunk(500, function ($pivotRows) use ($newPlaylist, $categoryTagMap, $oldUuid): void {
                    $seriesIds = $pivotRows->pluck('series_id')->all();

                    DB::table('series_custom_playlist')->insert(
                        array_map(fn ($id) => [
                            'series_id' => $id,
                            'custom_playlist_id' => $newPlaylist->id,
                        ], $seriesIds)
                    );

                    if (! empty($categoryTagMap)) {
                        Series::whereIn('id', $seriesIds)
                            ->with(['tags' => fn ($q) => $q->where('type', $oldUuid.'-category')])
                            ->get()
                            ->each(function (Series $series) use ($categoryTagMap): void {
                                $oldTag = $series->tags->first();
                                if ($oldTag && isset($categoryTagMap[$oldTag->id])) {
                                    $series->attachTag($categoryTagMap[$oldTag->id]);
                                }
                            });
                    }
                });

            DB::commit();

            Notification::make()
                ->success()
                ->title('Custom Playlist Duplicated')
                ->body("\"{$playlist->name}\" has been duplicated successfully.")
                ->broadcast($playlist->user);
            Notification::make()
                ->success()
                ->title('Custom Playlist Duplicated')
                ->body("\"{$playlist->name}\" has been duplicated successfully, new playlist: \"{$newPlaylist->name}\"")
                ->sendToDatabase($playlist->user);
        } catch (Exception $e) {
            DB::rollBack();

            logger()->error("Error duplicating \"{$this->playlist->name}\": {$e->getMessage()}");

            Notification::make()
                ->danger()
                ->title("Error duplicating \"{$this->playlist->name}\"")
                ->body('Please view your notifications for details.')
                ->broadcast($this->playlist->user);
            Notification::make()
                ->danger()
                ->title("Error duplicating \"{$this->playlist->name}\"")
                ->body($e->getMessage())
                ->sendToDatabase($this->playlist->user);
        }
    }
}
