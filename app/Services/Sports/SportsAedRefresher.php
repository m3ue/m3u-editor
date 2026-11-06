<?php

namespace App\Services\Sports;

use App\Jobs\RefreshSportsAedProfile;
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
    /** About the most a worker can fetch before other queued work should get a turn. */
    private const int GAME_BATCH_LEAGUES = 30;

    public function __construct(private readonly SportsService $sports) {}

    /**
     * Refreshes [$profile]'s games in one go and returns how many it has in its
     * window. This holds the caller for as long as the provider takes, so
     * background work uses {@see RefreshSportsAedProfile}, which runs these
     * steps one at a time. [$force] (a manual refresh) refetches the games
     * instead of reading the cache, and clears the profile's EPG files whatever
     * changed.
     */
    public function refresh(AedProfile $profile, bool $force = false): int
    {
        if ($force) {
            $this->forgetGames($profile);
        }
        foreach ($this->gameBatches($profile) as $leagueIds) {
            $this->fetchGames($profile, $leagueIds);
        }
        $this->fetchMissingTeams($profile);

        return $this->finishRefresh($profile, $force);
    }

    /**
     * The profile's leagues as separately fetchable batches, each short enough
     * not to hold a worker for long. Soccer stays together: a wide set of it is
     * read from one all-leagues scoreboard, which is only used, and only found
     * again by game matching, when they're asked for at once.
     *
     * @return list<list<string>>
     */
    public function gameBatches(AedProfile $profile): array
    {
        [$leagueIds] = $this->window($profile);
        $soccer = array_values(array_filter($leagueIds, fn (string $leagueId): bool => str_starts_with($leagueId, 'soccer/')));
        $others = array_values(array_diff($leagueIds, $soccer));

        return [...($soccer === [] ? [] : [$soccer]), ...array_chunk($others, self::GAME_BATCH_LEAGUES)];
    }

    /**
     * Drops the cached games of the profile's window, so the next fetch reads
     * them from the providers. Done once before the batches rather than per
     * batch, because a provider can cache a whole day across leagues.
     */
    public function forgetGames(AedProfile $profile): void
    {
        [$leagueIds, $from, $to] = $this->window($profile);
        $this->sports->forgetFixtures($leagueIds, $from, $to);
    }

    /**
     * Fetches one batch of {@see gameBatches} into the cache.
     *
     * @param  list<string>  $leagueIds
     */
    public function fetchGames(AedProfile $profile, array $leagueIds): void
    {
        [, $from, $to] = $this->window($profile);
        $this->sports->rawFixtures($leagueIds, $from, $to, liveScores: false);
    }

    /**
     * Compares the cached games with the last run, clears the profile's EPG
     * files if they changed (or when [$force]), and returns how many it has.
     */
    public function finishRefresh(AedProfile $profile, bool $force = false): int
    {
        [$leagueIds, $from, $to] = $this->window($profile);
        $fixtures = $this->sports->cachedFixtures($leagueIds, $from, $to)['fixtures'];

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
     * at once on a first run, so this can be called again later.
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
