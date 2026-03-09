<?php

namespace App\Console\Commands;

use App\Models\Playlist;
use App\Services\M3uProxyService;
use App\Services\ProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ProfileConnectionWorker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'profiles:worker
                            {--once : Run a single reconcile pass and exit}
                            {--interval=10 : Seconds between reconcile ticks}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Continuously reconcile profile connection counts against m3u-proxy active streams';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $runOnce = $this->option('once');
        $interval = (int) $this->option('interval');

        if ($runOnce) {
            return $this->runOnce();
        }

        $this->info('Starting profile connection worker (Ctrl+C to stop)...');
        $this->info("Tick interval: {$interval} seconds");

        $backoff = 1;

        while (true) {
            try {
                $corrected = $this->reconcileAll();

                if ($corrected > 0) {
                    $this->info("Corrected {$corrected} profile connection count(s)");
                }

                $backoff = 1;

                sleep($interval);
            } catch (\Throwable $e) {
                Log::error('Profile connection worker exception', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $this->error("Worker error: {$e->getMessage()} — backing off {$backoff}s");

                sleep($backoff);
                $backoff = min($backoff * 2, 60);
            }
        }
    }

    /**
     * Run a single reconcile pass and return exit code.
     */
    protected function runOnce(): int
    {
        $this->info('Running single reconcile pass...');
        $corrected = $this->reconcileAll(verbose: true);
        $this->info("Done. Corrected {$corrected} profile connection count(s).");

        return self::SUCCESS;
    }

    /**
     * Reconcile all profiles_enabled playlists.
     *
     * @return int Number of profiles whose counts were corrected.
     */
    protected function reconcileAll(bool $verbose = false): int
    {
        $playlists = Playlist::where('profiles_enabled', true)->get();

        if ($playlists->isEmpty()) {
            return 0;
        }

        $totalCorrected = 0;

        foreach ($playlists as $playlist) {
            $totalCorrected += $this->reconcilePlaylist($playlist, $verbose);
        }

        return $totalCorrected;
    }

    /**
     * Reconcile connection counts for a single playlist.
     *
     * @return int Number of profiles whose counts were corrected.
     */
    protected function reconcilePlaylist(Playlist $playlist, bool $verbose = false): int
    {
        $activeStreams = M3uProxyService::getPlaylistActiveStreams($playlist);

        // API failure — skip rather than zero out counts
        if ($activeStreams === null) {
            if ($verbose) {
                $this->warn("  [{$playlist->name}] m3u-proxy unavailable — skipping");
            }

            return 0;
        }

        // Build profile_id => active stream count from proxy ground truth
        $profileStreamCounts = [];

        foreach ($activeStreams as $stream) {
            $profileId = $stream['metadata']['provider_profile_id'] ?? null;

            if ($profileId) {
                $profileStreamCounts[$profileId] = ($profileStreamCounts[$profileId] ?? 0) + ($stream['client_count'] ?? 1);
            }
        }

        $corrected = 0;
        $profiles = $playlist->profiles()->get();

        foreach ($profiles as $profile) {
            $redisCount = ProfileService::getConnectionCount($profile);
            $proxyCount = $profileStreamCounts[$profile->id] ?? 0;

            if ($redisCount === $proxyCount) {
                continue;
            }

            $key = "playlist_profile:{$profile->id}:connections";

            try {
                Redis::set($key, $proxyCount);
                $corrected++;

                Log::info('Profile connection worker corrected count', [
                    'profile_id' => $profile->id,
                    'playlist_id' => $playlist->id,
                    'old_count' => $redisCount,
                    'new_count' => $proxyCount,
                ]);

                if ($verbose) {
                    $this->warn("  [{$playlist->name}] Profile '{$profile->name}' (ID: {$profile->id}): Redis={$redisCount} → Proxy={$proxyCount}");
                }
            } catch (\Exception $e) {
                $this->error("  [{$playlist->name}] Failed to update Redis for profile {$profile->id}: {$e->getMessage()}");
            }
        }

        return $corrected;
    }
}
