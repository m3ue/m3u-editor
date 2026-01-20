<?php

namespace App\Console\Commands;

use App\Models\Playlist;
use App\Services\M3uProxyService;
use App\Services\ProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ReconcileProfileConnections extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'profiles:reconcile {--playlist= : Specific playlist ID to reconcile}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile profile connection counts with active streams from m3u-proxy';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $playlistId = $this->option('playlist');

        if ($playlistId) {
            $playlists = Playlist::where('id', $playlistId)
                ->where('profiles_enabled', true)
                ->get();
        } else {
            $playlists = Playlist::where('profiles_enabled', true)->get();
        }

        if ($playlists->isEmpty()) {

            return self::SUCCESS;
        }

        $this->info("Reconciling connection counts for {$playlists->count()} playlist(s)...");

        $proxyService = new M3uProxyService;

        foreach ($playlists as $playlist) {
            $this->reconcilePlaylist($playlist, $proxyService);
        }

        $this->info('Reconciliation complete.');

        return self::SUCCESS;
    }

    /**
     * Reconcile connection counts for a single playlist.
     */
    protected function reconcilePlaylist(Playlist $playlist, M3uProxyService $proxyService): void
    {
        $this->line("  Playlist: {$playlist->name} (ID: {$playlist->id})");

        // Get active streams from m3u-proxy for this playlist
        $activeStreams = M3uProxyService::getPlaylistActiveStreams($playlist);

        // Build a map of profile_id => active stream count
        $profileStreamCounts = [];

        foreach ($activeStreams as $stream) {
            $profileId = $stream['metadata']['provider_profile_id'] ?? null;

            if ($profileId) {
                $profileStreamCounts[$profileId] = ($profileStreamCounts[$profileId] ?? 0) + ($stream['client_count'] ?? 1);
            }
        }

        // Get all profiles for this playlist
        $profiles = $playlist->profiles()->get();

        foreach ($profiles as $profile) {
            $redisCount = ProfileService::getConnectionCount($profile);
            $proxyCount = $profileStreamCounts[$profile->id] ?? 0;

            if ($redisCount !== $proxyCount) {
                $this->warn("    Profile '{$profile->name}' (ID: {$profile->id}): Redis={$redisCount}, Proxy={$proxyCount} - Correcting...");

                // Reset the count in Redis
                $key = "playlist_profile:{$profile->id}:connections";

                try {
                    Redis::set($key, $proxyCount);

                    Log::info('Reconciled profile connection count', [
                        'profile_id' => $profile->id,
                        'playlist_id' => $playlist->id,
                        'old_count' => $redisCount,
                        'new_count' => $proxyCount,
                    ]);
                } catch (\Exception $e) {
                    $this->error("      Failed to update Redis: {$e->getMessage()}");
                }
            } else {
                $this->line("    Profile '{$profile->name}' (ID: {$profile->id}): OK ({$redisCount} connections)");
            }
        }
    }
}
