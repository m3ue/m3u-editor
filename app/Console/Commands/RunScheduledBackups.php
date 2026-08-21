<?php

namespace App\Console\Commands;

use App\Jobs\CreateBackup;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Console\Command;
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
                $this->info('Running scheduled backups...');

                $max = $settings->auto_backup_database_max_backups;
                $deleteAfterDays = $settings->auto_backup_database_delete_after_days;
                if ($max > 0 || $deleteAfterDays > 0) {
                    $data = [];
                    foreach (FilamentSpatieLaravelBackup::getDisks() as $disk) {
                        $data = array_merge($data, FilamentSpatieLaravelBackup::getBackupDestinationData($disk));
                    }

                    // Order by backup date
                    $data = collect($data)->sortByDesc('date');

                    // Determine which backups should be deleted for exceeding the max backups count...
                    $toDelete = collect();
                    if ($max > 0 && $data->count() >= $max) {
                        $toDelete = $data->slice($max - 1);
                    }

                    // ...and which should be deleted for being older than the configured retention period
                    if ($deleteAfterDays > 0) {
                        $cutoff = now()->subDays($deleteAfterDays);
                        $expired = $data->filter(fn ($record) => Carbon::parse($record['date'])->lt($cutoff));
                        $toDelete = $toDelete->merge($expired)->unique('path');
                    }

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

                // Run the backup
                app('Illuminate\Contracts\Bus\Dispatcher')->dispatch(new CreateBackup);
            }
        }
    }
}
