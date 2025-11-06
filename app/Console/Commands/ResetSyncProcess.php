<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Jobs\GenerateEpgCache;
use App\Jobs\ProcessEpgImport;
use App\Jobs\ProcessM3uImport;
use App\Models\Playlist;
use App\Models\Epg;
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
        $hungPlaylists = Playlist::where('status', '!=', Status::Completed);
        $hungEpgs = Epg::where('status', '!=', Status::Completed);

        if ($hungPlaylists->count() === 0 && $hungEpgs->count() === 0) {
            $this->info('✅ No stuck Playlists or EPGs found.');
            return Command::SUCCESS;
        }

        // Flush the cache to prevent any stale data issues
        $this->call('queue:clear');

        foreach ($hungPlaylists->cursor() as $playlist) {
            $this->info("🔄 Resetting stuck Playlist(s): {$playlist->name}");

            // Restart the sync process
            if ($playlist->auto_sync) {
                $this->line("  → Restarting sync for \"{$playlist->name}\"");
                dispatch(new ProcessM3uImport($playlist, force: true));
            } else {
                $playlist->update([
                    'processing' => false,
                    'status' => Status::Pending,
                    'errors' => null,
                    'progress' => 0,
                    'series_progress' => 0,
                ]);
            }

            // Notify the user
            Notification::make()
                ->warning()
                ->title("Playlist Sync Reset: \"{$playlist->name}\"")
                ->body("The Playlist sync appeared to be stuck and has been reset."
                    . ($playlist->auto_sync ? " A new sync has been started automatically." : " Please manually restart the sync if needed."))
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
                        'errors' => "Cache generation appeared to hang and was reset.",
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
                        'errors' => "Import appeared to hang and was reset. Please try syncing again.",
                        'progress' => 100,
                    ]);
                }
            }

            // Notify the user
            Notification::make()
                ->warning()
                ->title("EPG Processing Reset: \"{$epg->name}\"")
                ->body("The EPG appeared to be stuck in {$phase} phase and has been reset. " .
                    ($epg->auto_sync ? "A new sync has been started automatically." : "Please manually restart the sync if needed."))
                ->broadcast($epg->user)
                ->sendToDatabase($epg->user);
        }

        return Command::SUCCESS;
    }
}
