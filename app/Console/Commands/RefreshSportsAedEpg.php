<?php

namespace App\Console\Commands;

use App\Models\AedProfile;
use App\Services\EpgCacheService;
use App\Services\EspnSportsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

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
     * Sports AED programmes are baked into the cached playlist EPG files, so a
     * game added, moved or rescheduled on ESPN would otherwise never reach them.
     * Each profile's games are fingerprinted (ids, kickoffs, teams; not live
     * state or scores, which the EPG doesn't show), and only a changed
     * fingerprint clears that profile's files. Fetching also keeps the ESPN
     * cache warm, so the next EPG build doesn't wait on ESPN.
     */
    public function handle(EspnSportsService $espn): int
    {
        if (! $espn->isEnabled()) {
            return self::SUCCESS;
        }

        $allLeagueIds = array_column(EspnSportsService::leagues(), 'id');
        $now = CarbonImmutable::now('UTC');
        $cleared = 0;

        AedProfile::query()->where('sports_matching', true)->each(function (AedProfile $profile) use ($espn, $allLeagueIds, $now, &$cleared): void {
            $days = max(1, min((int) ($profile->dummy_epg_days ?? 5), EspnSportsService::MAX_RANGE_DAYS - 1));
            $fixtures = $espn->rawFixtures($profile->sports_league_ids ?: $allLeagueIds, $now->subHours(12), $now->addDays($days), liveScores: false);

            $fingerprint = md5(json_encode(array_map(fn (array $fixture): array => [
                $fixture['league_id'],
                $fixture['id'],
                $fixture['start'],
                $fixture['home']['team']['id'] ?? null,
                $fixture['away']['team']['id'] ?? null,
            ], $fixtures)));

            $key = "sports:aed:fingerprint:{$profile->id}";
            if (Cache::get($key) !== $fingerprint) {
                EpgCacheService::clearForAedProfile($profile->id);
                Cache::put($key, $fingerprint, now()->addDay());
                $cleared++;
            }
        });

        $this->info("Cleared EPG cache for {$cleared} sports AED profile(s).");

        return self::SUCCESS;
    }
}
