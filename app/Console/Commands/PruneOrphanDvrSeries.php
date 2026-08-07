<?php

namespace App\Console\Commands;

use App\Models\Series;
use Illuminate\Console\Command;

class PruneOrphanDvrSeries extends Command
{
    protected $signature = 'dvr:prune-orphan-series
                            {--dry-run : Report what would be deleted without making changes}';

    protected $description = 'Delete Series rows with no remaining episodes (orphans left behind before issue #1372 was fixed)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Scope to DVR-created series only — DvrVodIntegrationService tags every
        // Series/Season/Episode it creates with import_batch_no = 'dvr'. Without
        // this filter, a normal M3U/Xtream series can be mid-sync (its Series row
        // created by ProcessM3uImportSeries before ProcessM3uImportSeriesEpisodes
        // has populated episodes) and would be wrongly swept up as an "orphan".
        $query = Series::query()->where('import_batch_no', 'dvr')->whereDoesntHave('episodes');

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No orphan series found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[DRY RUN] Would delete {$count} orphan series:");
            foreach ($query->orderBy('id')->cursor() as $series) {
                $this->line("  - #{$series->id} \"{$series->name}\"");
            }

            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($query->cursor() as $series) {
            $series->delete();
            $deleted++;
        }

        $this->info("Deleted {$deleted} orphan series.");

        return self::SUCCESS;
    }
}
