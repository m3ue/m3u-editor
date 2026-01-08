<?php

namespace App\Jobs;

use App\Models\Playlist;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CopyPlaylist implements ShouldQueue
{
    use Queueable;

    public $copied = [];

    public $failed = [];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public array $playlists,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $playlist = $this->playlist;
        foreach ($this->playlists as $playlistId) {
            $copy = Playlist::find($playlistId);
            if ($copy) {
                $copied = $this->copyPlaylistToPlaylist($copy);
            } else {
                $this->failed[] = $playlistId;
                Notification::make()
                    ->title('Playlist Copy Error')
                    ->body('A Playlist was not found, it may have been removed before the copy was able to complete.')
                    ->danger()
                    ->send();
            }
        }

        // Send notification
        Notification::make()
            ->success()
            ->title('Playlist Copied')
            ->body("\"{$playlist->name}\" has been copied successfully.")
            ->broadcast($playlist->user);
        Notification::make()
            ->success()
            ->title('Playlist Copied')
            ->body("\"{$playlist->name}\" has been copied successfully to the following playlists: ".implode(', ', $this->copied))
            ->sendToDatabase($playlist->user);
    }

    /**
     * Copy the playlist to the given playlist.
     *
     * @return bool
     */
    private function copyPlaylistToPlaylist(Playlist $copy)
    {
        // Get the base playlist
        $playlist = $this->playlist;
        try {
            // @TODO: implement this

            // ...

            $this->copied[] = $copy->name;

            return true;
        } catch (Exception $e) {
            // ...
        }

        return false;
    }
}
