<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Enums\SyncRunStatus;
use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Services\SyncPipelineService;
use App\Settings\GeneralSettings;
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
            $syncRun = app(SyncPipelineService::class)->startImport($playlist, trigger: 'console_refresh');
            dispatch(new ProcessM3uImport($playlist, (bool) $force, syncRunId: $syncRun->id));
            $this->info('Dispatched playlist for refresh');
        } else {
            $this->info('Refreshing all playlists');
            // Auto-reset stuck playlists (processing for too long).
            //
            // Active syncs touch updated_at via periodic progress updates, so a stale
            // updated_at reliably signals a genuinely hung or orphaned sync. When we
            // reset a playlist we also fail any stale Running SyncRuns so the next
            // startImport() call creates a fresh run instead of attaching to the stale one.
            $stuckMinutes = (int) config('dev.stuck_processing_minutes', 240);
            $stuckThreshold = now()->subMinutes($stuckMinutes);

            Playlist::query()
                ->where('status', Status::Processing)
                ->where('updated_at', '<', $stuckThreshold)
                ->each(function (Playlist $playlist) {
                    $playlist->syncRuns()
                        ->where('status', SyncRunStatus::Running->value)
                        ->update([
                            'status' => SyncRunStatus::Failed->value,
                            'finished_at' => now(),
                        ]);

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
            $failedRetryCooldown = app(GeneralSettings::class)->failedRetryCooldownMinutes();
            $pipeline = app(SyncPipelineService::class);
            $playlists->get()->each(function (Playlist $playlist) use (&$count, $failedRetryCooldown, $pipeline) {
                $cronExpression = $playlist->syncCronExpression();

                // Gate failed retries behind a cooldown to prevent CPU runaway
                $isFailed = $playlist->status === Status::Failed;
                $cooldownPassed = $playlist->updated_at->diffInMinutes(now()) >= $failedRetryCooldown;

                if ($isFailed && ! $cooldownPassed) {
                    return;
                }

                // Retry a failed sync right away while it has auto resync attempts left
                if ($isFailed && $playlist->auto_resync_on_failure && $playlist->resync_attempt < $playlist->auto_resync_retries) {
                    $playlist->update(['resync_attempt' => $playlist->resync_attempt + 1]);

                    $count++;
                    $syncRun = $pipeline->startImport($playlist, trigger: 'scheduled_refresh');
                    dispatch(new ProcessM3uImport($playlist, true, syncRunId: $syncRun->id));

                    return;
                }

                // Otherwise wait for the next scheduled sync (for a failed playlist, counted from its last attempt)
                $lastRun = $playlist->synced ?? now()->subYears(1);
                $nextDue = $cronExpression->getNextRunDate($lastRun->toDateTimeImmutable());

                if (now() >= $nextDue) {
                    // Each scheduled sync gets a fresh set of retry attempts
                    if ($playlist->resync_attempt > 0) {
                        $playlist->update(['resync_attempt' => 0]);
                    }

                    $count++;
                    $syncRun = $pipeline->startImport($playlist, trigger: 'scheduled_refresh');
                    dispatch(new ProcessM3uImport($playlist, $isFailed, syncRunId: $syncRun->id));
                }
            });
            $this->info('Dispatched '.$count.' playlists for refresh');
        }

    }
}
