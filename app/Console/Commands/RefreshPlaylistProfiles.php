<?php

namespace App\Console\Commands;

use App\Jobs\RefreshPlaylistProfiles as RefreshPlaylistProfilesJob;
use App\Models\Playlist;
use Illuminate\Console\Command;

class RefreshPlaylistProfiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-playlist-profiles
        {--playlist= : Refresh provider profiles for a specific playlist (provide playlist ID as argument)}
        {--profile= : Refresh a specific provider profile (provide profile ID as argument)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh playlist provider profiles';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $playlistId = $this->option('playlist');
        $profileId = $this->option('profile');

        if ($profileId) {
            // Refresh a specific provider profile
            RefreshPlaylistProfilesJob::dispatch(null, $profileId);
            $this->info("Refreshing provider profile ID: {$profileId}");
        } elseif ($playlistId) {
            // Refresh all provider profiles for a specific playlist
            RefreshPlaylistProfilesJob::dispatch($playlistId, null);
            $this->info("Refreshing provider profiles for playlist ID: {$playlistId}");
        } else {
            $playlists = Playlist::where('profiles_enabled', true)->get();

            if ($playlists->isEmpty()) {
                // If no playlists with profiles enabled, nothing to do
                // Continue silently without logging to avoid log clutter if feature is not in use
                return;
            }

            // Refresh all provider profiles for all playlists
            RefreshPlaylistProfilesJob::dispatch();
            $this->info('Refreshing provider profiles for all playlists...');
        }

    }
}
