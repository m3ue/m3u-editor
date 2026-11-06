<?php

namespace App\Console\Commands;

use App\Jobs\RefreshSportsAedProfile;
use App\Models\AedProfile;
use App\Services\Sports\SportsAedRefresher;
use App\Services\Sports\SportsService;
use Illuminate\Console\Command;

class RefreshSportsAedEpg extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:refresh-sports-aed-epg {--now : Fetch here and wait, instead of queueing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh the games of sports-matching AED profiles, clearing cached EPG files where they changed';

    /**
     * The schedule runs this inside schedule:run, where anything slow would hold
     * up every task after it, so the fetching itself is queued. See
     * {@see SportsAedRefresher}: only a profile whose games changed has its
     * cached EPG files cleared.
     */
    public function handle(SportsService $sports, SportsAedRefresher $refresher): int
    {
        if (! $sports->isEnabled()) {
            return self::SUCCESS;
        }

        $profiles = 0;
        AedProfile::query()->where('sports_matching', true)->each(function (AedProfile $profile) use ($refresher, &$profiles): void {
            if ($this->option('now')) {
                $refresher->refresh($profile);
            } else {
                RefreshSportsAedProfile::dispatch($profile->id);
            }
            $profiles++;
        });

        $this->info("Refreshed games for {$profiles} sports AED profile(s).");

        return self::SUCCESS;
    }
}
