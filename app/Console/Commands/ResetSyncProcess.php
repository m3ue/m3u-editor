<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Enums\SyncRunStatus;
use App\Jobs\GenerateEpgCache;
use App\Jobs\ProcessEpgImport;
use App\Jobs\ProcessM3uImport;
use App\Models\Epg;
use App\Models\Playlist;
use App\Services\SyncPipelineService;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

class ResetSyncProcess extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reset-sync-process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset sync process for Playlists or EPGs that may be stuck';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Find any Playlists or EPGs that are not marked as Completed (but should be) and reset their status to allow them to be reprocessed.
        $hungPlaylists = Playlist::where('status', '!=', Status::Completed)
            ->orWhereHas('syncRuns', function ($query) {
                $query->where('status', SyncRunStatus::Running->value);
            });
        $hungEpgs = Epg::where('status', '!=', Status::Completed);

        // Reset queue to clear out any potentially stuck jobs that may be contributing to the issue
        $this->resetQueue();

        // If no hung Playlists or EPGs are found, exit early
        if ($hungPlaylists->count() === 0 && $hungEpgs->count() === 0) {
            $this->info('✅ No stuck Playlists or EPGs found.');

            return Command::SUCCESS;
        }

        foreach ($hungPlaylists->cursor() as $playlist) {
            $this->info("🔄 Resetting stuck Playlist(s): {$playlist->name}");

            // Fail any stale Running SyncRuns so startImport() creates a fresh run
            // rather than attaching to the stale one and silently skipping the import.
            $playlist->syncRuns()
                ->where('status', SyncRunStatus::Running->value)
                ->update([
                    'status' => SyncRunStatus::Failed->value,
                    'finished_at' => now(),
                ]);

            // Restart the sync process
            if ($playlist->auto_sync) {
                $this->line("  → Restarting sync for \"{$playlist->name}\"");
                $syncRun = app(SyncPipelineService::class)->startImport($playlist, trigger: 'reset_sync_process');
                dispatch(new ProcessM3uImport($playlist, force: true, syncRunId: $syncRun->id));
            } else {
                $playlist->update([
                    'processing' => [
                        ...$playlist->processing ?? [],
                        'live_processing' => false,
                        'vod_processing' => false,
                        'series_processing' => false,
                    ],
                    'status' => Status::Pending,
                    'errors' => null,
                    'progress' => 0,
                    'series_progress' => 0,
                    'vod_progress' => 0,
                ]);
            }

            // Notify the user
            Notification::make()
                ->warning()
                ->title("Playlist Sync Reset: \"{$playlist->name}\"")
                ->body('The Playlist sync appeared to be stuck and has been reset.'
                    .($playlist->auto_sync ? ' A new sync has been started automatically.' : ' Please manually restart the sync if needed.'))
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        }

        foreach ($hungEpgs->cursor() as $epg) {
            $this->info("🔄 Resetting stuck EPG(s): {$epg->name}");
            // Determine the appropriate status to set based on processing_phase if available
            $phase = $epg->processing_phase ?? ($epg->synced !== null ? 'cache' : 'import');

            if ($phase === 'cache') {
                // Optionally restart cache generation
                if ($epg->auto_sync) {
                    $this->line("  → Restarting cache generation for \"{$epg->name}\"");
                    dispatch(new GenerateEpgCache($epg->uuid, notify: true));
                } else {
                    $epg->update([
                        'status' => Status::Failed,
                        'processing' => false,
                        'processing_started_at' => null,
                        'processing_phase' => null,
                        'is_cached' => false,
                        'errors' => 'Cache generation appeared to hang and was reset.',
                    ]);
                }
            } else {
                // Optionally restart import
                if ($epg->auto_sync) {
                    $this->line("  → Restarting import for \"{$epg->name}\"");
                    dispatch(new ProcessEpgImport($epg, force: true));
                } else {
                    $epg->update([
                        'status' => Status::Failed,
                        'processing' => false,
                        'processing_started_at' => null,
                        'processing_phase' => null,
                        'errors' => 'Import appeared to hang and was reset. Please try syncing again.',
                        'progress' => 100,
                    ]);
                }
            }

            // Notify the user
            Notification::make()
                ->warning()
                ->title("EPG Processing Reset: \"{$epg->name}\"")
                ->body("The EPG appeared to be stuck in {$phase} phase and has been reset. ".
                    ($epg->auto_sync ? 'A new sync has been started automatically.' : 'Please manually restart the sync if needed.'))
                ->broadcast($epg->user)
                ->sendToDatabase($epg->user);
        }

        return Command::SUCCESS;
    }

    private function resetQueue()
    {
        // Clear the queue to prevent any stale data issues
        $this->call('queue:clear', [
            '--force' => true,
        ]);
        $this->call('queue:clear', [
            '--queue' => 'import',
            '--force' => true,
        ]);
        $this->call('queue:clear', [
            '--queue' => 'file_sync',
            '--force' => true,
        ]);
    }
}
