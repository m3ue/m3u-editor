<?php

namespace App\Console\Commands;

use App\Enums\SyncRunStatus;
use App\Jobs\CreateBackup;
use App\Models\SyncRun;
use App\Settings\GeneralSettings;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackup;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination as SpatieBackupDestination;

class RunScheduledBackups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:run-scheduled-backups';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run backups based on your settings';

    /**
     * Execute the console command.
     */
    public function handle(GeneralSettings $settings)
    {
        if ($settings->auto_backup_database) {
            // Check if due yet
            $isDue = (new CronExpression($settings->auto_backup_database_schedule))->isDue();
            if ($isDue) {
                // On SQLite, a concurrent sync write can cause the dump to produce an
                // empty file (exit 0, no output), which then breaks ZipArchive finalization.
                // Skip this tick and let the next scheduler run handle it instead.
                if (DB::connection()->getDriverName() === 'sqlite') {
                    $hasActiveSyncs = SyncRun::where('status', SyncRunStatus::Running->value)->exists();
                    if ($hasActiveSyncs) {
                        Log::info('[backup] Skipped scheduled backup: Sync in progress, will retry on next schedule.');
                        $this->warn('Skipping backup: Sync is in progress. Will retry on next schedule.');

                        return;
                    }
                }

                $this->info('Running scheduled backups...');

                // Check if we'll be over the max backups after execution
                $max = $settings->auto_backup_database_max_backups;
                if ($max && $max > 0) {
                    $data = [];
                    foreach (FilamentSpatieLaravelBackup::getDisks() as $disk) {
                        $data = array_merge($data, FilamentSpatieLaravelBackup::getBackupDestinationData($disk));
                    }

                    // Order by backup date
                    $data = collect($data)->sortByDesc('date');
                    if ($data->count() >= $max) {
                        $toDelete = $data->slice($max - 1);
                        foreach ($toDelete as $record) {
                            $this->info("Deleting old backup: {$record['path']}");
                            SpatieBackupDestination::create($record['disk'], config('backup.backup.name'))
                                ->backups()
                                ->first(function (Backup $backup) use ($record) {
                                    return $backup->path() === $record['path'];
                                })
                                ->delete();
                        }
                    }
                }

                // Run the backup
                app('Illuminate\Contracts\Bus\Dispatcher')->dispatch(new CreateBackup);
            }
        }
    }
}
