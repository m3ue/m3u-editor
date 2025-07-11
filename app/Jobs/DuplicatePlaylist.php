<?php

namespace App\Jobs;

use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DuplicatePlaylist implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public ?string $name = null,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::beginTransaction();
        try {
            // Get the base playlist
            $playlist = $this->playlist;

            // Create a new playlist
            $newPlaylist = $playlist->replicate(except: [
                'id',
                'name',
                'uuid',
                'short_urls_enabled',
                'short_urls'
            ]);
            $newPlaylist->name = $this->name ?? $playlist->name . ' (Copy)';
            $newPlaylist->uuid = Str::orderedUuid()->toString();
            $newPlaylist->created_at = now();
            $newPlaylist->updated_at = now();
            $newPlaylist->saveQuietly(); // Don't fire model events to prevent auto sync

            // Copy the groups
            foreach ($playlist->groups()->get() as $group) {
                $newGroup = $group->replicate(except: [
                    'id',
                    'playlist_id',
                ]);
                $newGroup->playlist_id = $newPlaylist->id;
                $newGroup->created_at = now();
                $newGroup->updated_at = now();
                $newGroup->save();

                // Copy the group channels
                foreach ($group->channels()->get() as $channel) {
                    $newChannel = $channel->replicate(except: [
                        'id',
                        'group_id',
                        'playlist_id',
                    ]);
                    $newChannel->group_id = $newGroup->id;
                    $newChannel->playlist_id = $newPlaylist->id;
                    $newChannel->created_at = now();
                    $newChannel->updated_at = now();
                    $newChannel->save();
                }
            }

            // Note: Since PlaylistAuth can now only be assigned to one model at a time,
            // we skip copying auth assignments when duplicating playlists.
            // Users will need to manually assign auth to the duplicated playlist if needed.
            // This prevents conflicts with the unique constraint.

            // @TODO: Copy the uploaded file
            // // Copy uploaded file
            // if ($playlist->uploads && Storage::disk('local')->exists($playlist->uploads)) {
            //     // Copy the file to the new playlist
            //     Storage::disk('local')->copy($playlist->uploads, $newPlaylist->filePath);
            //     $newPlaylist->uploads = $newPlaylist->filePath;
            // }

            // Commit DB changes
            DB::commit();

            // Send notification
            Notification::make()
                ->success()
                ->title('Playlist Duplicated')
                ->body("\"{$playlist->name}\" has been duplicated successfully.")
                ->broadcast($playlist->user);
            Notification::make()
                ->success()
                ->title('Playlist Duplicated')
                ->body("\"{$playlist->name}\" has been duplicated successfully, new playlist: \"{$newPlaylist->name}\"")
                ->sendToDatabase($playlist->user);
        } catch (\Exception $e) {
            DB::rollBack();

            // Log the exception
            logger()->error("Error duplicating \"{$this->playlist->name}\": {$e->getMessage()}");

            // Send notification
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
