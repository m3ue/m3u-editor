<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\Playlist;
use App\Sync\PlaylistSyncDispatcher;
use Cron\CronExpression;
use Illuminate\Console\Command;

class RefreshPlaylist extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-playlist {playlist?} {force?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh playlist in batch (or specific playlist when ID provided)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $playlistId = $this->argument('playlist');
        if ($playlistId) {
            $force = $this->argument('force') ?? false;
            $this->info("Refreshing playlist with ID: {$playlistId}");
            $playlist = Playlist::findOrFail($playlistId);
            app(PlaylistSyncDispatcher::class)->dispatch(
                playlist: $playlist,
                trigger: PlaylistSyncDispatcher::TRIGGER_CONSOLE_REFRESH,
                force: (bool) $force,
            );
            $this->info('Dispatched playlist for refresh');
        } else {
            $this->info('Refreshing all playlists');
            // Auto-reset stuck playlists (processing for too long)
            $stuckMinutes = (int) config('dev.stuck_processing_minutes', 120);

            Playlist::query()
                ->where('status', Status::Processing)
                ->where('updated_at', '<', now()->subMinutes($stuckMinutes))
                ->each(function (Playlist $playlist) {
                    $playlist->update([
                        'status' => Status::Pending,
                        'synced' => null,
                        'processing' => [
                            ...$playlist->processing ?? [],
                            'live_processing' => false,
                            'vod_processing' => false,
                            'series_processing' => false,
                        ],
                    ]);
                });

            // Get all playlists that are not currently processing
            // Exclude network playlists as they don't have M3U sources
            $playlists = Playlist::query()->where([
                ['status', '!=', Status::Processing],
                ['auto_sync', '=', true],
                ['is_network_playlist', '=', false],
            ]);

            $totalPlaylists = $playlists->count();
            if ($totalPlaylists === 0) {
                $this->info('No playlists available for refresh');

                return;
            }

            $count = 0;
            $failedRetryCooldown = (int) config('dev.failed_retry_cooldown_minutes', 30);
            $playlists->get()->each(function (Playlist $playlist) use (&$count, $failedRetryCooldown) {
                $cronExpression = new CronExpression($playlist->sync_interval);

                // Gate failed retries behind a cooldown to prevent CPU runaway
                $isFailed = $playlist->status === Status::Failed;
                $cooldownPassed = $playlist->updated_at->diffInMinutes(now()) >= $failedRetryCooldown;

                if ($isFailed && ! $cooldownPassed) {
                    return;
                }

                $force = $isFailed;
                $lastRun = $force ? now()->subYears(1) : ($playlist->synced ?? now()->subYears(1));
                $nextDue = $cronExpression->getNextRunDate($lastRun->toDateTimeImmutable());

                if (now() >= $nextDue) {
                    $count++;
                    app(PlaylistSyncDispatcher::class)->dispatch(
                        playlist: $playlist,
                        trigger: PlaylistSyncDispatcher::TRIGGER_CONSOLE_REFRESH_SCHEDULED,
                        force: $force,
                    );
                }
            });
            $this->info('Dispatched '.$count.' playlists for refresh');
        }

    }
}
