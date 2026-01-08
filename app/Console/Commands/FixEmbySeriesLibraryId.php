<?php

namespace App\Console\Commands;

use App\Models\Playlist;
use Illuminate\Console\Command;

class FixEmbySeriesLibraryId extends Command
{
    protected $signature = 'emby:fix-series-library {playlist_id} {library_id}';

    protected $description = 'Fix the Emby Series library ID for a playlist';

    public function handle()
    {
        $playlistId = $this->argument('playlist_id');
        $libraryId = $this->argument('library_id');

        $playlist = Playlist::find($playlistId);

        if (! $playlist) {
            $this->error("Playlist with ID {$playlistId} not found!");

            return 1;
        }

        $embyConfig = $playlist->emby_config ?? [];

        if (! isset($embyConfig['series'])) {
            $this->error("This playlist doesn't have Emby Series configuration!");

            return 1;
        }

        $this->info('Current Series library ID: '.($embyConfig['series']['library_id'] ?? 'not set'));

        $embyConfig['series']['library_id'] = $libraryId;
        $playlist->emby_config = $embyConfig;
        $playlist->save();

        $this->info("✓ Updated Series library ID to: {$libraryId}");
        $this->info("You can now use the 'Sync Emby Series' button in the GUI.");

        return 0;
    }
}
