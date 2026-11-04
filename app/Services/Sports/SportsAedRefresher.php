<?php

namespace App\Services\Sports;

use App\Models\AedProfile;
use App\Services\EpgCacheService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps a sports-matching AED profile's cached EPG files in step with its
 * sports providers.
 *
 * Its programmes are baked into the cached playlist EPG files, so a game
 * added, moved or rescheduled would otherwise never reach them. Each
 * profile's games are fingerprinted (ids, kickoffs, teams; not live state or
 * scores, which the EPG doesn't show), and only a changed fingerprint clears
 * that profile's files. Fetching also keeps the games cache warm, so the next
 * EPG build doesn't wait on a provider.
 */
class SportsAedRefresher
{
    public function __construct(private readonly SportsService $sports) {}

    /**
     * Refreshes [$profile]'s games and returns how many it has in its window.
     * [$force] (a manual refresh) refetches them instead of reading
     * the cache, and clears the profile's EPG files whatever changed.
     */
    public function refresh(AedProfile $profile, bool $force = false): int
    {
        [$leagueIds, $from, $to] = $this->window($profile);

        if ($force) {
            $this->sports->forgetFixtures($leagueIds, $from, $to);
        }
        $fixtures = $this->sports->rawFixtures($leagueIds, $from, $to, liveScores: false);
        $this->fetchTeams($fixtures);

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

    /**
     * Fetches the team lists a profile's games still lack, and returns how
     * many are still missing. Providers with a rate limit can't fetch them all
     * at once on a first run, so a caller with time to spare calls this again.
     */
    public function fetchMissingTeams(AedProfile $profile): int
    {
        [$leagueIds, $from, $to] = $this->window($profile);

        return $this->fetchTeams($this->sports->cachedFixtures($leagueIds, $from, $to)['fixtures']);
    }

    /**
     * Matching also reads the playing leagues' full team lists.
     *
     * @param  list<array<string, mixed>>  $fixtures
     * @return int how many of them are still missing
     */
    private function fetchTeams(array $fixtures): int
    {
        $playing = array_values(array_unique(array_column($fixtures, 'league_id')));

        return count($playing) - count($this->sports->teamsByLeague($playing));
    }

    /**
     * @return array{0: list<string>, 1: CarbonImmutable, 2: CarbonImmutable} league ids and the span games are read for
     */
    private function window(AedProfile $profile): array
    {
        $days = max(1, min((int) ($profile->dummy_epg_days ?? 5), SportsService::MAX_RANGE_DAYS - 1));
        $now = CarbonImmutable::now('UTC');

        return [
            $profile->sports_league_ids ?: array_column($this->sports->leagues(), 'id'),
            $now->subHours(SportsAedResolver::WINDOW_PAST_HOURS),
            $now->addDays($days),
        ];
    }
}
