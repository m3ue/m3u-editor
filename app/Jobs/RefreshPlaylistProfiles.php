<?php

namespace App\Jobs;

use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Services\ProfileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshPlaylistProfiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?int $playlistId = null,
        public ?int $profileId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Refresh a specific profile
        if ($this->profileId) {
            $profile = PlaylistProfile::find($this->profileId);
            if ($profile) {
                $this->refreshProfile($profile);
            }

            return;
        }

        // Refresh all profiles for a specific playlist
        if ($this->playlistId) {
            $playlist = Playlist::find($this->playlistId);
            if ($playlist && $playlist->profiles_enabled) {
                $this->refreshPlaylistProfiles($playlist);
            }

            return;
        }

        // Refresh all profiles for all playlists with profiles enabled
        $this->refreshAllProfiles();
    }

    /**
     * Refresh all profiles across all playlists.
     */
    protected function refreshAllProfiles(): void
    {
        $playlists = Playlist::where('profiles_enabled', true)->get();

        Log::info('Starting refresh of all playlist profiles', [
            'playlist_count' => $playlists->count(),
        ]);

        foreach ($playlists as $playlist) {
            $this->refreshPlaylistProfiles($playlist);
        }

        Log::info('Completed refresh of all playlist profiles');
    }

    /**
     * Refresh all profiles for a playlist.
     */
    protected function refreshPlaylistProfiles(Playlist $playlist): void
    {
        $profiles = $playlist->profiles()->get();

        Log::info("Refreshing profiles for playlist {$playlist->id}", [
            'playlist_name' => $playlist->name,
            'profile_count' => $profiles->count(),
        ]);

        foreach ($profiles as $profile) {
            $this->refreshProfile($profile);

            // Add a small delay between API calls to avoid rate limiting
            usleep(500000); // 500ms
        }
    }

    /**
     * Refresh a single profile.
     */
    protected function refreshProfile(PlaylistProfile $profile): void
    {
        try {
            $success = ProfileService::refreshProfile($profile);

            if ($success) {
                Log::info("Successfully refreshed profile {$profile->id}", [
                    'name' => $profile->name,
                    'playlist_id' => $profile->playlist_id,
                ]);

                // Check for expiration warnings
                $this->checkExpirationWarning($profile);
            } else {
                Log::warning("Failed to refresh profile {$profile->id}", [
                    'name' => $profile->name,
                    'playlist_id' => $profile->playlist_id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Error refreshing profile {$profile->id}", [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if profile is expiring soon and log a warning.
     */
    protected function checkExpirationWarning(PlaylistProfile $profile): void
    {
        $info = $profile->provider_info;

        if (! $info || ! isset($info['user_info']['exp_date'])) {
            return;
        }

        $expDate = $info['user_info']['exp_date'];

        // If exp_date is a Unix timestamp
        if (is_numeric($expDate)) {
            $expiresAt = \Carbon\Carbon::createFromTimestamp($expDate);
        } else {
            $expiresAt = \Carbon\Carbon::parse($expDate);
        }

        $daysUntilExpiry = now()->diffInDays($expiresAt, false);

        if ($daysUntilExpiry <= 0) {
            Log::warning("Profile {$profile->id} has EXPIRED", [
                'name' => $profile->name,
                'expired_at' => $expiresAt->toDateString(),
            ]);

            // Auto-disable expired profiles
            if ($profile->enabled) {
                $profile->update(['enabled' => false]);
                Log::info("Auto-disabled expired profile {$profile->id}");
            }
        } elseif ($daysUntilExpiry <= 7) {
            Log::warning("Profile {$profile->id} expires in {$daysUntilExpiry} days", [
                'name' => $profile->name,
                'expires_at' => $expiresAt->toDateString(),
            ]);
        }
    }
}
