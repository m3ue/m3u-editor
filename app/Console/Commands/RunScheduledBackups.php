<?php

namespace App\Console\Commands;

use App\Jobs\CreateBackup;
use App\Jobs\PruneOldBackups;
use App\Settings\GeneralSettings;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

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

                // Create the replacement backup first, then prune to the configured
                // max/age limits. Pruning before the replacement exists (the previous
                // behavior) could leave zero backups available if the replacement
                // job was delayed or failed.
                Bus::chain([
                    new CreateBackup,
                    new PruneOldBackups($max, $deleteAfterDays, requireBackupSince: now()),
                ])->dispatch();
            }
        }
    }
}
