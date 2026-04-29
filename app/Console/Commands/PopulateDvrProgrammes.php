<?php

namespace App\Console\Commands;

use App\Models\Epg;
use App\Services\EpgCacheService;
use Illuminate\Console\Command;

class PopulateDvrProgrammes extends Command
{
    protected $signature = 'epg:populate-dvr {epg? : EPG ID to populate (omit for all cached EPGs)}';

    protected $description = 'Populate epg_programmes from the JSONL cache for DVR-enabled playlists';

    public function handle(EpgCacheService $cacheService): int
    {
        $epgId = $this->argument('epg');

        $query = Epg::query()->where('is_cached', true);
        if ($epgId) {
            $query->where('id', $epgId);
        }

        $epgs = $query->get();

        if ($epgs->isEmpty()) {
            $this->warn('No cached EPGs found'.($epgId ? " with ID {$epgId}" : '').'.');

            return self::FAILURE;
        }

        foreach ($epgs as $epg) {
            $this->info("Populating DVR programmes for EPG #{$epg->id} \"{$epg->name}\"...");
            $cacheService->populateDvrProgrammes($epg);
            $this->info('  Done.');
        }

        return self::SUCCESS;
    }
}
