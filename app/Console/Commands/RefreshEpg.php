<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
use Cron\CronExpression;
use Illuminate\Console\Command;

class RefreshEpg extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-epg {epg?} {force?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh EPGs in batch (or specific EPG when ID provided)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $epgId = $this->argument('epg');
        if ($epgId) {
            $force = $this->argument('force') ?? false;
            $this->info("Refreshing EPG with ID: {$epgId}");
            $epg = Epg::findOrFail($epgId);
            dispatch(new ProcessEpgImport($epg, (bool) $force));
            $this->info('Dispatched EPG for refresh');
        } else {
            $this->info('Refreshing all EPGs');
            // Auto-reset stuck EPGs (processing for too long)
            $stuckMinutes = (int) config('dev.stuck_processing_minutes', 120);

            Epg::query()
                ->where('status', Status::Processing)
                ->where('updated_at', '<', now()->subMinutes($stuckMinutes))
                ->each(function (Epg $epg) {
                    $epg->update([
                        'status' => Status::Pending,
                        'synced' => null,
                        'processing' => false,
                    ]);
                });

            // Next, let's get all EPGs that are not currently processing and check if they are due for a sync
            $epgs = Epg::query()->where([
                ['status', '!=', Status::Processing],
                ['auto_sync', '=', true],
            ]);

            $totalEpgs = $epgs->count();
            if ($totalEpgs === 0) {
                $this->info('No EPGs ready refresh');

                return;
            }

            $count = 0;
            $failedRetryCooldown = (int) config('dev.failed_retry_cooldown_minutes', 30);
            $epgs->get()->each(function (Epg $epg) use (&$count, $failedRetryCooldown) {
                $interval = $epg->sync_interval === '24hr' ? '0 0 * * *' : $epg->sync_interval;
                $cronExpression = new CronExpression($interval);

                // Gate failed retries behind a cooldown to prevent CPU runaway
                $isFailed = $epg->status === Status::Failed;
                $cooldownPassed = $epg->updated_at->diffInMinutes(now()) >= $failedRetryCooldown;

                if ($isFailed && ! $cooldownPassed) {
                    return;
                }

                $force = $isFailed;
                $lastRun = $force ? now()->subYears(1) : ($epg->synced ?? now()->subYears(1));
                $nextDue = $cronExpression->getNextRunDate($lastRun->toDateTimeImmutable());

                if (now() >= $nextDue) {
                    $count++;
                    dispatch(new ProcessEpgImport($epg, $force));
                }
            });
            $this->info('Dispatched '.$count.' epgs for refresh');
        }
    }
}
