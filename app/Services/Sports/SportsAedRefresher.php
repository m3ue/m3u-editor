<?php

namespace App\Services\Sports;

use App\Models\AedProfile;
use App\Services\EpgCacheService;
use App\Services\EspnSportsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps a sports-matching AED profile's cached EPG files in step with ESPN.
 *
 * Its programmes are baked into the cached playlist EPG files, so a game
 * added, moved or rescheduled on ESPN would otherwise never reach them. Each
 * profile's games are fingerprinted (ids, kickoffs, teams; not live state or
 * scores, which the EPG doesn't show), and only a changed fingerprint clears
 * that profile's files. Fetching also keeps the ESPN cache warm, so the next
 * EPG build doesn't wait on ESPN.
 */
class SportsAedRefresher
{
    public function __construct(private readonly EspnSportsService $espn) {}

    /**
     * Refreshes [$profile]'s games and returns how many it has in its window.
     * [$force] (a manual refresh) refetches them from ESPN instead of reading
     * the cache, and clears the profile's EPG files whatever changed.
     */
    public function refresh(AedProfile $profile, bool $force = false): int
    {
        $leagueIds = $profile->sports_league_ids ?: array_column(EspnSportsService::leagues(), 'id');
        $days = max(1, min((int) ($profile->dummy_epg_days ?? 5), EspnSportsService::MAX_RANGE_DAYS - 1));
        $now = CarbonImmutable::now('UTC');
        $from = $now->subHours(SportsAedResolver::WINDOW_PAST_HOURS);
        $to = $now->addDays($days);

        if ($force) {
            $this->espn->forgetFixtures($leagueIds, $from, $to);
        }
        $fixtures = $this->espn->rawFixtures($leagueIds, $from, $to, liveScores: false);
        // Matching also reads the playing leagues' full team lists
        $this->espn->teamsByLeague(array_values(array_unique(array_column($fixtures, 'league_id'))));

        $fingerprint = md5(json_encode(array_map(fn (array $fixture): array => [
            $fixture['league_id'],
            $fixture['id'],
            $fixture['start'],
            $fixture['home']['team']['id'] ?? null,
            $fixture['away']['team']['id'] ?? null,
        ], $fixtures)));

        $key = "sports:aed:fingerprint:{$profile->id}";
        if ($force || Cache::get($key) !== $fingerprint) {
            EpgCacheService::clearForAedProfile($profile->id);
            Cache::put($key, $fingerprint, now()->addDay());
        }

        return count($fixtures);
    }
}
