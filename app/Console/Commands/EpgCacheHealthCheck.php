<?php

namespace App\Console\Commands;

use App\Jobs\GenerateEpgCache;
use App\Models\Epg;
use App\Services\EpgCacheService;
use Illuminate\Console\Command;

class EpgCacheHealthCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:epg-cache-health-check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the health of EPG cache and, and if necessary, regenerate it';

    /**
     * Execute the console command.
     */
    public function handle(EpgCacheService $cacheService)
    {
        // Check if marked cached, but not actually cached
        $epgs = Epg::where('is_cached', true)->get();
        if ($epgs->isEmpty()) {
            $this->info('No cached EPGs found.');
        } else {
            $this->info('Checking EPG cache health...');
            foreach ($epgs as $epg) {
                if (!$cacheService->isCacheValid($epg)) {
                    $this->warn("Cache for EPG \"{$epg->name}\" is invalid. Regenerating...");
                    $epg->update(['is_cached' => false]);
                    dispatch(new GenerateEpgCache($epg->uuid, notify: false));
                } else {
                    $this->info("Cache for EPG \"{$epg->name}\" is valid.");
                }
            }
        }

        // Check if any EPGs marked as not cached, but actually cached
        $uncachedEpgs = Epg::where('is_cached', false)->get();
        if ($uncachedEpgs->isEmpty()) {
            $this->info('No uncached EPGs found.');
        } else {
            $this->info('Checking uncached EPGs...');
            foreach ($uncachedEpgs as $epg) {
                if ($cacheService->isCacheValid($epg)) {
                    $this->warn("EPG \"{$epg->name}\" is marked as not cached, but cache exists. Updating status...");
                    $epg->update(['is_cached' => true]);
                } else {
                    $this->info("EPG \"{$epg->name}\" is correctly marked as not cached.");
                }
            }
        }
    }
}
