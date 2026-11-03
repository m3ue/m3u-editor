<?php

namespace App\Console\Commands;

use App\Models\AedProfile;
use App\Services\EspnSportsService;
use App\Services\Sports\SportsAedRefresher;
use Illuminate\Console\Command;

class RefreshSportsAedEpg extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-sports-aed-epg';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear cached EPG files of sports-matching AED profiles whose games changed';

    /**
     * See {@see SportsAedRefresher}: only a profile whose games changed has
     * its cached EPG files cleared.
     */
    public function handle(EspnSportsService $espn, SportsAedRefresher $refresher): int
    {
        if (! $espn->isEnabled()) {
            return self::SUCCESS;
        }

        $profiles = 0;
        AedProfile::query()->where('sports_matching', true)->each(function (AedProfile $profile) use ($refresher, &$profiles): void {
            $refresher->refresh($profile);
            $profiles++;
        });

        $this->info("Refreshed games for {$profiles} sports AED profile(s).");

        return self::SUCCESS;
    }
}
