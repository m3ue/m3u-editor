<?php

namespace App\Services;

use App\Enums\ImageProfile;
use App\Http\Controllers\LogoProxyController;
use App\Services\Sports\EspnLeagueCatalog;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Game schedules and scores for the M3U TV Sports screen, from ESPN's
 * public, keyless (and unofficial, undocumented) site API.
 *
 * Every client reads through this service, so ESPN sees one request per
 * league per day however many TVs are watching: each league-day scoreboard
 * is cached for a time that depends on what it holds (a minute while a game
 * is on, hours once the day is long over). Crests are served through the
 * logo proxy, so TVs never contact ESPN at all.
 *
 * Scoreboards are fetched one ESPN day (`?dates=YYYYMMDD`) at a time: ESPN
 * answers a date range with an error.
 */
class EspnSportsService
{
    public const string DEFAULT_API_URL = 'https://site.api.espn.com/apis/site/v2/sports';

    /** Longest span one fixtures request may cover. */
    public const int MAX_RANGE_DAYS = 14;

    /** Scoreboard requests sent to ESPN at once. */
    private const int CONCURRENCY = 6;

    private const int REQUEST_TIMEOUT_SECONDS = 15;

    /**
     * Above this many soccer leagues, a day's soccer comes from ESPN's
     * all-leagues scoreboard: one larger request instead of one per league.
     */
    private const int BULK_SOCCER_MIN_LEAGUES = 3;

    /**
     * How stale a scoreboard game matching (kickoffs and teams, no scores)
     * still accepts, so live games don't refetch it every minute.
     */
    private const int MATCHING_STALE_MINUTES = 30;

    /** How long one process may hold the ESPN fetch lock. */
    private const int FETCH_LOCK_SECONDS = 120;

    /** How long another process waits for that lock before serving the cache. */
    private const int FETCH_LOCK_WAIT_SECONDS = 60;

    /** How long ESPN requests pause after ESPN refuses or fails a batch. */
    private const int PAUSE_MINUTES = 10;

    /** Failed requests in one batch that, as half of it or more, pause ESPN requests. */
    private const int PAUSE_MIN_FAILURES = 3;

    /** How long a league's season window is trusted before it's re-read. */
    private const int SEASON_CACHE_HOURS = 24;

    /** Sports whose fixtures read home side first ("Arsenal v Chelsea"). */
    private const array HOME_FIRST_SPORTS = ['soccer', 'rugby', 'rugby-league', 'australian-football'];

    /** Display names of the sports in [EspnLeagueCatalog], in its order. */
    private const array SPORT_NAMES = [
        'football' => 'Football',
        'basketball' => 'Basketball',
        'baseball' => 'Baseball',
        'hockey' => 'Hockey',
        'soccer' => 'Soccer',
        'rugby' => 'Rugby',
        'rugby-league' => 'Rugby League',
        'australian-football' => 'Australian Football',
        'lacrosse' => 'Lacrosse',
        'volleyball' => 'Volleyball',
        'field-hockey' => 'Field Hockey',
        'water-polo' => 'Water Polo',
    ];

    /** @var array<string, array<string, mixed>>|null catalogue entries by league id */
    private static ?array $leaguesById = null;

    public function __construct(private readonly GeneralSettings $settings) {}

    public function isEnabled(): bool
    {
        return (bool) $this->settings->sports_espn_enabled;
    }

    /**
     * The scoreboard API base: the admin's override, else ESPN's own.
     */
    public function apiUrl(): string
    {
        $override = trim((string) $this->settings->sports_espn_api_url);

        return rtrim($override !== '' ? $override : self::DEFAULT_API_URL, '/');
    }

    /**
     * The competitions clients may list, as sent to them: every league in
     * [EspnLeagueCatalog], with its sport's display name for grouping.
     *
     * @return list<array{id: string, name: string, abbreviation: string, sport: string, sport_name: string, soccer_style: bool, default: bool}>
     */
    public static function leagues(): array
    {
        return array_map(fn (array $league): array => [
            'id' => self::leagueId($league),
            'name' => $league['name'],
            'abbreviation' => $league['abbreviation'],
            'sport' => $league['sport'],
            'sport_name' => self::sportName($league['sport']),
            'soccer_style' => self::isHomeFirst($league),
            'default' => $league['default'] ?? false,
        ], EspnLeagueCatalog::LEAGUES);
    }

    /**
     * [leagues] as sent to clients on every login, kept small (the Xtream API
     * isn't compressed): flags only when set, an abbreviation only when it
     * differs from the name, and no sport, which starts the id.
     *
     * @return list<array{id: string, name: string, abbreviation?: string, soccer_style?: true, default?: true}>
     */
    public static function clientLeagues(): array
    {
        return array_map(fn (array $league): array => array_filter([
            'id' => $league['id'],
            'name' => $league['name'],
            'abbreviation' => $league['abbreviation'] !== $league['name'] ? $league['abbreviation'] : null,
            'soccer_style' => $league['soccer_style'] ?: null,
            'default' => $league['default'] ?: null,
        ], fn (mixed $value): bool => $value !== null), self::leagues());
    }

    /**
     * Each sport's display name, by the sport that starts a league id.
     *
     * @return array<string, string>
     */
    public static function sportNames(): array
    {
        return array_column(self::leagues(), 'sport_name', 'sport');
    }

    /**
     * A sport's display name ("Rugby League" for "rugby-league").
     */
    public static function sportName(string $sport): string
    {
        return __(self::SPORT_NAMES[$sport] ?? ucwords(str_replace('-', ' ', $sport)));
    }

    /**
     * Whether a league's fixtures read home side first, as soccer's do.
     *
     * @param  array<string, mixed>  $league
     */
    public static function isHomeFirst(array $league): bool
    {
        return in_array($league['sport'], self::HOME_FIRST_SPORTS, true);
    }

    /**
     * Every fixture of [$leagueIds] starting in [$from, $to), soonest first,
     * ready to send to a client. Unknown league ids are ignored; the span is
     * capped at [MAX_RANGE_DAYS].
     *
     * ESPN groups a scoreboard by its own (US Eastern) day, so the range is
     * widened by a day each side and filtered back down.
     *
     * @param  list<string>  $leagueIds
     * @return list<array<string, mixed>>
     */
    public function fixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to): array
    {
        return array_map($this->present(...), $this->rawFixtures($leagueIds, $from, $to));
    }

    /**
     * [fixtures], with crests as ESPN serves them: for server-side use (the
     * sports AED profiles), which proxies only what it emits.
     *
     * Without [$liveScores] (game matching only reads kickoffs and teams), a
     * wide soccer league set comes from ESPN's all-leagues scoreboard, which
     * isn't refreshed for scores.
     *
     * @param  list<string>  $leagueIds
     * @return list<array<string, mixed>>
     */
    public function rawFixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to, bool $liveScores = true): array
    {
        $from = CarbonImmutable::instance($from)->utc();
        $to = CarbonImmutable::instance($to)->utc();
        if ($to->greaterThan($from->addDays(self::MAX_RANGE_DAYS))) {
            $to = $from->addDays(self::MAX_RANGE_DAYS);
        }
        if (! $to->greaterThan($from)) {
            return [];
        }

        $leagues = array_values(array_filter(array_map(self::findLeague(...), array_unique($leagueIds))));

        $scoreboards = $this->scoreboards($leagues, $this->days($from, $to), $liveScores);

        $fixtures = [];
        foreach ($scoreboards as $dayFixtures) {
            foreach ($dayFixtures as $fixture) {
                $start = CarbonImmutable::parse($fixture['start']);
                if ($start->lessThan($from) || ! $start->lessThan($to)) {
                    continue;
                }
                $fixtures[$fixture['league_id'].':'.$fixture['id']] = $fixture;
            }
        }

        $fixtures = array_values($fixtures);
        usort($fixtures, fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        return $fixtures;
    }

    /**
     * Drops the cached scoreboards [rawFixtures] would read for [$leagueIds]
     * over [$from, $to), so the next read fetches them from ESPN. Team lists
     * and season windows are kept.
     *
     * @param  list<string>  $leagueIds
     */
    public function forgetFixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to): void
    {
        $leagues = array_filter(array_map(self::findLeague(...), array_unique($leagueIds)));
        $keys = [];
        foreach ($this->days(CarbonImmutable::instance($from)->utc(), CarbonImmutable::instance($to)->utc()) as $day) {
            $keys[] = $this->allSoccerCacheKey($day);
            foreach ($leagues as $league) {
                $keys[] = $this->cacheKey($league, $day);
            }
        }
        Cache::deleteMultiple($keys);
    }

    /**
     * Every team of [$leagueId] (empty for an unknown league or a failed
     * fetch), with proxied crests. Rosters rarely change, so the list is
     * cached for a week; a failure is retried after a few minutes.
     *
     * @return list<array<string, mixed>>
     */
    public function teams(string $leagueId): array
    {
        $league = self::findLeague($leagueId);
        if ($league === null) {
            return [];
        }

        $key = $this->teamsCacheKey($leagueId);
        $teams = Cache::get($key);
        if (! is_array($teams) && $this->isPaused()) {
            return [];
        }
        if (! is_array($teams)) {
            try {
                $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->get($this->teamsUrl($league), ['limit' => '1000']);
            } catch (Throwable $e) {
                $response = $e;
            }
            $teams = $this->storeTeams($key, $leagueId, $response);
        }

        return array_map(fn (array $team): array => [
            ...$team,
            'logo' => $team['logo'] !== null ? self::proxiedLogo($team['logo']) : null,
        ], $teams);
    }

    /**
     * The teams of each of [$leagueIds], by league, with crests as ESPN
     * serves them: for server-side matching, which never shows them. Lists
     * not cached yet are fetched in parallel batches.
     *
     * @param  list<string>  $leagueIds
     * @return array<string, list<array<string, mixed>>>
     */
    public function teamsByLeague(array $leagueIds, bool $holdsLock = false): array
    {
        $keys = [];
        foreach (array_unique($leagueIds) as $leagueId) {
            if (self::findLeague($leagueId) !== null) {
                $keys[$this->teamsCacheKey($leagueId)] = $leagueId;
            }
        }
        $cached = $keys === [] ? [] : Cache::many(array_keys($keys));

        $teams = [];
        $misses = [];
        foreach ($keys as $key => $leagueId) {
            if (is_array($cached[$key] ?? null)) {
                $teams[$leagueId] = $cached[$key];
            } else {
                $misses[$key] = self::findLeague($leagueId);
            }
        }

        if ($misses !== [] && ! $holdsLock) {
            try {
                return Cache::lock($this->fetchLockKey(), self::FETCH_LOCK_SECONDS)
                    ->block(self::FETCH_LOCK_WAIT_SECONDS, fn (): array => $this->teamsByLeague($leagueIds, holdsLock: true));
            } catch (LockTimeoutException) {
                // Still fetching elsewhere: serve what's cached
                return $teams;
            }
        }

        foreach (array_chunk($misses, self::CONCURRENCY, preserve_keys: true) as $batch) {
            if ($this->isPaused()) {
                break;
            }
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (string $key): mixed => $pool->as($key)
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->get($this->teamsUrl($batch[$key]), ['limit' => '1000']),
                array_keys($batch),
            ));
            $this->pauseIfRefused($responses);
            foreach ($batch as $key => $league) {
                $teams[self::leagueId($league)] = $this->storeTeams($key, self::leagueId($league), $responses[$key] ?? null);
                unset($responses[$key]);
            }
        }

        return $teams;
    }

    /**
     * Caches a `/teams` response's teams for a week, or nothing for a few
     * minutes when the request failed.
     *
     * @return list<array<string, mixed>>
     */
    private function storeTeams(string $key, string $leagueId, mixed $response): array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            Log::warning('ESPN teams fetch failed', [
                'league' => $leagueId,
                'reason' => match (true) {
                    $response instanceof Response => 'HTTP '.$response->status(),
                    $response instanceof Throwable => $response->getMessage(),
                    default => 'no response',
                },
            ]);
            Cache::put($key, [], now()->addMinutes(5));

            return [];
        }
        $teams = self::parseTeams(json_decode($response->body(), true));
        Cache::put($key, $teams, now()->addDays(7));

        return $teams;
    }

    /**
     * The catalogue entry for [$leagueId], or null when it isn't offered.
     *
     * @return array<string, mixed>|null
     */
    public static function findLeague(string $leagueId): ?array
    {
        if (self::$leaguesById === null) {
            self::$leaguesById = [];
            foreach (EspnLeagueCatalog::LEAGUES as $league) {
                self::$leaguesById[self::leagueId($league)] = $league;
            }
        }

        return self::$leaguesById[$leagueId] ?? null;
    }

    /**
     * Whether a league is a college one ("basketball/mens-college-basketball",
     * "soccer/usa.ncaa.m.1"), whose teams are named by school.
     */
    public static function isCollege(string $leagueId): bool
    {
        return str_contains($leagueId, 'college') || str_contains($leagueId, '.ncaa.');
    }

    /**
     * The sport a league id belongs to ("hockey" for "hockey/nhl").
     */
    public static function sportOf(string $leagueId): string
    {
        return explode('/', $leagueId, 2)[0];
    }

    /**
     * Fetches today's NFL scoreboard from [$apiUrl] (else the configured
     * API), bypassing the cache, and returns how many events it listed.
     * Throws when the API can't be reached or answers with something that
     * isn't a scoreboard.
     */
    public function testConnection(?string $apiUrl = null): int
    {
        $league = EspnLeagueCatalog::LEAGUES[0];
        $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->acceptJson()
            ->get(
                $this->scoreboardUrl($league, $apiUrl),
                $this->scoreboardQuery($league, CarbonImmutable::now('UTC')),
            );

        if (! $response->successful()) {
            throw new RuntimeException(__('The API answered with HTTP :status.', ['status' => $response->status()]));
        }
        $events = $response->json('events');
        if (! is_array($events)) {
            throw new RuntimeException(__('The API answered, but not with a scoreboard.'));
        }

        return count($events);
    }

    /**
     * The ESPN days whose scoreboards cover [$from, $to): ESPN groups games by
     * its own (US Eastern) day, so a day either side is included.
     *
     * @return list<CarbonImmutable>
     */
    private function days(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        for ($day = $from->subDay()->startOfDay(); $day->lessThanOrEqualTo($to->addDay()); $day = $day->addDay()) {
            $days[] = $day;
        }

        return $days;
    }

    /**
     * Each league-day's mapped fixtures, from the cache where fresh and
     * fetched in parallel batches otherwise.
     *
     * Two things keep a wide league set (an AED profile matching every
     * league) cheap. A league's days outside its season window aren't
     * requested at all. And without [$liveScores], past
     * [BULK_SOCCER_MIN_LEAGUES] soccer leagues each day's soccer comes from
     * ESPN's all-leagues scoreboard, split by league, instead of one request
     * per league.
     *
     * @param  list<array<string, mixed>>  $leagues
     * @param  list<CarbonImmutable>  $days
     * @return list<list<array<string, mixed>>>
     */
    private function scoreboards(array $leagues, array $days, bool $liveScores, bool $holdsLock = false): array
    {
        $soccerIds = [];
        foreach ($leagues as $league) {
            if ($league['sport'] === 'soccer') {
                $soccerIds[] = self::leagueId($league);
            }
        }
        $bulkSoccer = ! $liveScores && count($soccerIds) > self::BULK_SOCCER_MIN_LEAGUES;

        $perLeague = array_values(array_filter($leagues, fn (array $league): bool => ! $bulkSoccer || $league['sport'] !== 'soccer'));
        // Read in one round trip: a wide league set has a thousand keys
        $keys = [];
        foreach ($perLeague as $league) {
            $keys[] = $this->seasonCacheKey($league);
            foreach ($days as $day) {
                $keys[] = $this->cacheKey($league, $day);
            }
        }
        if ($bulkSoccer) {
            foreach ($days as $day) {
                $keys[] = $this->allSoccerCacheKey($day);
            }
        }
        $cache = $keys === [] ? [] : Cache::many($keys);

        $results = [];
        $soccerDays = [];
        /** @var array<string, array{0: array<string, mixed>|null, 1: CarbonImmutable}> $misses */
        $misses = [];
        foreach ($perLeague as $league) {
            $season = $cache[$this->seasonCacheKey($league)] ?? null;
            foreach ($days as $day) {
                if ($this->isOutOfSeason($season, $day)) {
                    continue;
                }
                $key = $this->cacheKey($league, $day);
                $cached = $cache[$key] ?? null;
                if (is_array($cached['fixtures'] ?? null) && (! $liveScores || $cached['fresh_until'] >= now()->getTimestamp())) {
                    $results[] = $cached['fixtures'];
                } else {
                    $misses[$key] = [$league, $day];
                }
            }
        }
        if ($bulkSoccer) {
            foreach ($days as $day) {
                $key = $this->allSoccerCacheKey($day);
                $cached = $cache[$key] ?? null;
                if (is_array($cached)) {
                    $soccerDays[] = $cached;
                } else {
                    $misses[$key] = [null, $day];
                }
            }
        }

        // One process fetches at a time; the others wait, then read what it
        // cached (a TV opening its guide sends several EPG requests at once).
        if ($misses !== [] && ! $holdsLock) {
            try {
                return Cache::lock($this->fetchLockKey(), self::FETCH_LOCK_SECONDS)
                    ->block(self::FETCH_LOCK_WAIT_SECONDS, fn (): array => $this->scoreboards($leagues, $days, $liveScores, holdsLock: true));
            } catch (LockTimeoutException) {
                // Still fetching elsewhere: serve what's cached
                $misses = [];
            }
        }

        // A league whose season isn't known yet is asked for one day first:
        // the window it reports then rules out its other days (most leagues
        // are out of season on any given day).
        $first = [];
        $later = [];
        foreach ($misses as $key => $miss) {
            $league = $miss[0];
            $group = $league === null ? $key : self::leagueId($league);
            if ($league === null || (! isset($first[$group]) && ! is_array($cache[$this->seasonCacheKey($league)] ?? null))) {
                $first[$group] = [$key, $miss];
            } else {
                $later[$key] = $miss;
            }
        }
        foreach ([array_column($first, 1, 0), $later] as $phase => $phaseMisses) {
            if ($phase === 1 && $phaseMisses !== []) {
                // The windows the first phase just learned
                $seasons = Cache::many(array_values(array_unique(array_map(fn (array $miss): string => $this->seasonCacheKey($miss[0]), $phaseMisses))));
                $phaseMisses = array_filter($phaseMisses, fn (array $miss): bool => ! $this->isOutOfSeason($seasons[$this->seasonCacheKey($miss[0])] ?? null, $miss[1]));
            }
            [$fetched, $fetchedSoccerDays] = $this->fetchScoreboards($phaseMisses);
            array_push($results, ...$fetched);
            array_push($soccerDays, ...$fetchedSoccerDays);
        }

        foreach ($soccerDays as $byLeague) {
            foreach ($soccerIds as $leagueId) {
                $results[] = $byLeague[$leagueId] ?? [];
            }
        }

        return $results;
    }

    /**
     * Fetches [$misses] (cache key => [league, or null for the all-leagues
     * soccer scoreboard; day]) in parallel batches and caches each.
     *
     * @param  array<string, array{0: array<string, mixed>|null, 1: CarbonImmutable}>  $misses
     * @return array{0: list<list<array<string, mixed>>>, 1: list<array<string, list<array<string, mixed>>>>} league-day fixtures, and all-leagues soccer days
     */
    private function fetchScoreboards(array $misses): array
    {
        $results = [];
        $soccerDays = [];
        foreach (array_chunk($misses, self::CONCURRENCY, preserve_keys: true) as $batch) {
            if ($this->isPaused()) {
                // Not cached, so the next refresh after the pause asks again
                array_push($results, ...array_fill(0, count($batch), []));

                continue;
            }
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                function (string $key) use ($pool, $batch): mixed {
                    [$league, $day] = $batch[$key];
                    $request = $pool->as($key)->timeout(self::REQUEST_TIMEOUT_SECONDS)->acceptJson();

                    return $league === null
                        ? $request->get($this->apiUrl().'/soccer/all/scoreboard', ['dates' => $day->format('Ymd'), 'limit' => '1000'])
                        : $request->get($this->scoreboardUrl($league), $this->scoreboardQuery($league, $day));
                },
                array_keys($batch),
            ));

            $this->pauseIfRefused($responses);
            foreach ($batch as $key => [$league, $day]) {
                $json = $this->readScoreboard($responses[$key] ?? null, $league === null ? 'soccer/all' : self::leagueId($league), $day);
                // Decoded bodies can be megabytes (the all-leagues soccer
                // scoreboard); keep only what's parsed from them.
                unset($responses[$key]);
                if ($json === null) {
                    // A short negative cache, so one failing league-day isn't
                    // retried by every client refresh.
                    Cache::put($key, $league === null ? [] : ['fixtures' => [], 'fresh_until' => now()->addMinute()->getTimestamp()], now()->addMinute());
                    $results[] = [];

                    continue;
                }
                if ($league === null) {
                    $byLeague = self::parseAllSoccerScoreboard($json);
                    // Big, and only read for kickoffs and teams (game
                    // matching), so it isn't refetched for live scores.
                    $ttl = max($this->ttl(array_merge([], ...array_values($byLeague)), $day), now()->addMinutes(self::MATCHING_STALE_MINUTES));
                    Cache::put($key, $byLeague, $ttl);
                    $soccerDays[] = $byLeague;

                    continue;
                }
                $this->rememberSeason($league, $json);
                $fixtures = self::parseScoreboard($json, self::leagueId($league));
                // Kept past its freshness for game matching, which only reads
                // kickoffs and teams; live readers refetch once it's stale.
                $freshUntil = $this->ttl($fixtures, $day);
                Cache::put($key, ['fixtures' => $fixtures, 'fresh_until' => $freshUntil->getTimestamp()], max($freshUntil, now()->addMinutes(self::MATCHING_STALE_MINUTES)));
                $results[] = $fixtures;
            }
        }

        return [$results, $soccerDays];
    }

    /**
     * The decoded body of a scoreboard response, or null (logged) when the
     * request failed.
     *
     * @return array<string, mixed>|null
     */
    private function readScoreboard(mixed $response, string $leagueId, CarbonImmutable $day): ?array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            $reason = match (true) {
                $response instanceof Response => 'HTTP '.$response->status(),
                $response instanceof Throwable => $response->getMessage(),
                default => 'no response',
            };
            Log::warning('ESPN scoreboard fetch failed', [
                'league' => $leagueId,
                'day' => $day->format('Ymd'),
                'reason' => $reason,
            ]);

            return null;
        }
        // Not $response->json(), which keeps the decoded copy on the response
        $json = json_decode($response->body(), true);

        return is_array($json) ? $json : [];
    }

    /**
     * Whether ESPN requests are paused after it refused or failed a batch.
     */
    private function isPaused(): bool
    {
        return Cache::has($this->pauseCacheKey());
    }

    /**
     * Pauses ESPN requests for [PAUSE_MINUTES] when a batch is rate limited
     * or forbidden, or mostly fails (at least [PAUSE_MIN_FAILURES] requests):
     * without it, a wide league set would
     * retry hundreds of requests every minute while ESPN is down.
     *
     * @param  array<string, mixed>  $responses
     */
    private function pauseIfRefused(array $responses): void
    {
        $failed = 0;
        $refused = false;
        foreach ($responses as $response) {
            if (! $response instanceof Response || ! $response->successful()) {
                $failed++;
                $refused = $refused || ($response instanceof Response && in_array($response->status(), [403, 429], true));
            }
        }
        // One failing league-day isn't an outage; most of a batch failing is
        if ($refused || ($failed >= self::PAUSE_MIN_FAILURES && $failed * 2 >= count($responses))) {
            Cache::put($this->pauseCacheKey(), true, now()->addMinutes(self::PAUSE_MINUTES));
            Log::warning('ESPN requests paused', ['minutes' => self::PAUSE_MINUTES, 'failed' => $failed, 'of' => count($responses)]);
        }
    }

    /**
     * Keeps the season window a scoreboard reports (ESPN's calendar for the
     * league), so days outside it aren't requested.
     *
     * @param  array<string, mixed>  $league
     * @param  array<string, mixed>  $json
     */
    private function rememberSeason(array $league, array $json): void
    {
        $start = self::parseDate(self::string($json['leagues'][0]['calendarStartDate'] ?? null));
        $end = self::parseDate(self::string($json['leagues'][0]['calendarEndDate'] ?? null));
        if ($start !== null && $end !== null && $end->greaterThan($start)) {
            Cache::put($this->seasonCacheKey($league), [$start->toIso8601ZuluString(), $end->toIso8601ZuluString()], now()->addHours(self::SEASON_CACHE_HOURS));
        }
    }

    /**
     * Whether [$day] is more than a day outside a league's last known
     * [$season] window (as cached by [rememberSeason]). Unknown windows never
     * rule a day out.
     */
    private function isOutOfSeason(mixed $season, CarbonImmutable $day): bool
    {
        if (! is_array($season) || count($season) !== 2) {
            return false;
        }

        return $day->addDay()->lessThan(CarbonImmutable::parse($season[0]))
            || $day->subDay()->greaterThan(CarbonImmutable::parse($season[1]));
    }

    /**
     * A day with a game on refreshes every minute for its score; a day long
     * over rarely changes. A future day only gains times and broadcasters,
     * and so does a day without games: those refresh hourly, today and
     * tomorrow's busy days every ten minutes.
     *
     * @param  list<array<string, mixed>>  $fixtures
     */
    private function ttl(array $fixtures, CarbonImmutable $day): CarbonInterface
    {
        $now = CarbonImmutable::now('UTC');
        $soon = $now->addMinutes(30);
        foreach ($fixtures as $fixture) {
            $isLive = $fixture['state'] === 'live';
            $startsSoon = $fixture['state'] === 'scheduled' && CarbonImmutable::parse($fixture['start'])->lessThan($soon);
            if ($isLive || $startsSoon) {
                return $now->addMinute();
            }
        }

        if ($day->addDays(2)->lessThan($now)) {
            return $now->addHours(6);
        }

        return $fixtures === [] || $day->subDay()->greaterThan($now) ? $now->addHour() : $now->addMinutes(10);
    }

    /**
     * Maps ESPN's all-leagues soccer scoreboard to fixtures by league id.
     * An event names its league only by ESPN's numeric id, inside its `uid`
     * (`s:600~l:700~e:...`); leagues outside the catalogue are dropped.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function parseAllSoccerScoreboard(mixed $json): array
    {
        $events = is_array($json) ? ($json['events'] ?? null) : null;
        if (! is_array($events)) {
            return [];
        }
        $byEspnId = [];
        foreach (EspnLeagueCatalog::LEAGUES as $league) {
            if ($league['sport'] === 'soccer') {
                $byEspnId[$league['espn_id']] = $league;
            }
        }

        $byLeague = [];
        foreach ($events as $event) {
            if (! is_array($event) || preg_match('/~l:(\d+)~/', (string) ($event['uid'] ?? ''), $match) !== 1) {
                continue;
            }
            $league = $byEspnId[$match[1]] ?? null;
            if ($league === null) {
                continue;
            }
            $leagueId = self::leagueId($league);
            if (($fixture = self::parseEvent($event, $leagueId)) !== null) {
                $byLeague[$leagueId][] = [...$fixture, 'league_logo' => $league['logo'] ?? null];
            }
        }

        return $byLeague;
    }

    /**
     * Maps an ESPN scoreboard response to fixtures, skipping events it can't
     * date. Crest URLs are kept as ESPN serves them; [present] proxies them.
     *
     * @return list<array<string, mixed>>
     */
    public static function parseScoreboard(mixed $json, string $leagueId): array
    {
        $events = is_array($json) ? ($json['events'] ?? null) : null;
        if (! is_array($events)) {
            return [];
        }
        $leagueLogo = self::string($json['leagues'][0]['logos'][0]['href'] ?? null)
            ?? (self::findLeague($leagueId)['logo'] ?? null);

        $fixtures = [];
        foreach ($events as $event) {
            if (is_array($event) && ($fixture = self::parseEvent($event, $leagueId)) !== null) {
                $fixtures[] = [...$fixture, 'league_logo' => $leagueLogo];
            }
        }

        return $fixtures;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private static function parseEvent(array $event, string $leagueId): ?array
    {
        $id = self::string($event['id'] ?? null);
        $competition = is_array($event['competitions'][0] ?? null) ? $event['competitions'][0] : [];
        $start = self::parseDate(self::string($event['date'] ?? null) ?? self::string($competition['date'] ?? null));
        if ($id === null || $start === null) {
            return null;
        }

        $home = null;
        $away = null;
        foreach ((array) ($competition['competitors'] ?? []) as $competitor) {
            if (! is_array($competitor) || ($side = self::parseCompetitor($competitor)) === null) {
                continue;
            }
            if (($competitor['homeAway'] ?? null) === 'away') {
                $away = $side;
            } else {
                $home = $side;
            }
        }

        $broadcasts = [];
        foreach ((array) ($competition['broadcasts'] ?? []) as $broadcast) {
            foreach ((array) (is_array($broadcast) ? ($broadcast['names'] ?? []) : []) as $name) {
                $name = self::string($name);
                if ($name !== null && ! in_array($name, $broadcasts, true)) {
                    $broadcasts[] = $name;
                }
            }
        }

        $status = $event['status'] ?? $competition['status'] ?? [];
        $type = is_array($status['type'] ?? null) ? $status['type'] : [];

        return [
            'id' => $id,
            'league_id' => $leagueId,
            'start' => $start->toIso8601ZuluString(),
            'state' => self::state($type),
            'status_detail' => self::string($type['shortDetail'] ?? null) ?? '',
            'name' => self::string($event['name'] ?? null),
            'broadcasts' => $broadcasts,
            'venue' => self::string($competition['venue']['fullName'] ?? null),
            'home' => $home,
            'away' => $away,
        ];
    }

    /**
     * Stoppages are "postponed" whatever ESPN's coarse state says.
     *
     * @param  array<string, mixed>  $type
     */
    private static function state(array $type): string
    {
        $name = strtoupper((string) ($type['name'] ?? ''));
        foreach (['POSTPONED', 'CANCELED', 'CANCELLED', 'SUSPENDED', 'ABANDONED'] as $stoppage) {
            if (str_contains($name, $stoppage)) {
                return 'postponed';
            }
        }

        return match ($type['state'] ?? null) {
            'in' => 'live',
            'post' => 'finished',
            default => 'scheduled',
        };
    }

    /**
     * @param  array<string, mixed>  $competitor
     * @return array<string, mixed>|null
     */
    private static function parseCompetitor(array $competitor): ?array
    {
        $team = is_array($competitor['team'] ?? null) ? self::parseTeam($competitor['team']) : null;
        if ($team === null) {
            return null;
        }
        $score = $competitor['score'] ?? null;

        return [
            'score' => is_array($score) ? self::string($score['displayValue'] ?? null) : self::string($score),
            'winner' => ($competitor['winner'] ?? false) === true,
            'team' => $team,
        ];
    }

    /**
     * @param  array<string, mixed>  $team
     * @return array<string, mixed>|null
     */
    private static function parseTeam(array $team): ?array
    {
        $id = self::string($team['id'] ?? null);
        if ($id === null) {
            return null;
        }
        $displayName = self::string($team['displayName'] ?? null) ?? self::string($team['name'] ?? null) ?? $id;

        return [
            'id' => $id,
            'display_name' => $displayName,
            'short_name' => self::string($team['shortDisplayName'] ?? null) ?? $displayName,
            'nickname' => self::string($team['name'] ?? null) ?? $displayName,
            'abbreviation' => self::string($team['abbreviation'] ?? null) ?? '',
            'location' => self::string($team['location'] ?? null),
            'logo' => self::string($team['logo'] ?? null) ?? self::string($team['logos'][0]['href'] ?? null),
            'color' => self::hex($team['color'] ?? null),
            'alternate_color' => self::hex($team['alternateColor'] ?? null),
        ];
    }

    /**
     * Maps an ESPN `/teams` response, alphabetically. Exposed for tests.
     *
     * @return list<array<string, mixed>>
     */
    public static function parseTeams(mixed $json): array
    {
        $wrappers = is_array($json) ? ($json['sports'][0]['leagues'][0]['teams'] ?? null) : null;
        if (! is_array($wrappers)) {
            return [];
        }
        $teams = [];
        foreach ($wrappers as $wrapper) {
            $team = is_array($wrapper['team'] ?? null) ? self::parseTeam($wrapper['team']) : null;
            if ($team !== null) {
                $teams[] = $team;
            }
        }
        usort($teams, fn (array $a, array $b): int => strcasecmp($a['display_name'], $b['display_name']));

        return $teams;
    }

    /**
     * Swaps each crest for its logo proxy URL, sized as a small image.
     *
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function present(array $fixture): array
    {
        foreach (['home', 'away'] as $side) {
            $logo = $fixture[$side]['team']['logo'] ?? null;
            if ($logo !== null) {
                $fixture[$side]['team']['logo'] = self::proxiedLogo($logo);
            }
        }
        if (($fixture['league_logo'] ?? null) !== null) {
            $fixture['league_logo'] = self::proxiedLogo($fixture['league_logo']);
        }

        return $fixture;
    }

    /**
     * A crest as clients load it: through the logo proxy, sized as a small
     * image, so nothing outside this server ever reaches ESPN.
     */
    public static function proxiedLogo(string $url): string
    {
        return LogoProxyController::generateProxyUrl($url, profile: ImageProfile::Photo);
    }

    /**
     * ESPN instants are UTC and sometimes drop the seconds (`2026-11-04T00:00Z`).
     */
    private static function parseDate(?string $raw): ?CarbonImmutable
    {
        if ($raw === null) {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private static function hex(mixed $raw): ?string
    {
        $hex = ltrim((string) self::string($raw), '#');

        return preg_match('/^[0-9a-fA-F]{6}$/', $hex) === 1 ? strtolower($hex) : null;
    }

    private static function string(mixed $raw): ?string
    {
        if (! is_scalar($raw)) {
            return null;
        }
        $text = trim((string) $raw);

        return $text === '' ? null : $text;
    }

    /**
     * @param  array<string, mixed>  $league
     */
    private static function leagueId(array $league): string
    {
        return $league['sport'].'/'.$league['slug'];
    }

    /**
     * @param  array<string, mixed>  $league
     */
    private function scoreboardUrl(array $league, ?string $apiUrl = null): string
    {
        $base = trim((string) $apiUrl) !== '' ? rtrim(trim((string) $apiUrl), '/') : $this->apiUrl();

        return $base.'/'.$league['sport'].'/'.$league['slug'].'/scoreboard';
    }

    /**
     * @param  array<string, mixed>  $league
     * @return array<string, string>
     */
    private function scoreboardQuery(array $league, CarbonImmutable $day): array
    {
        return ['dates' => $day->format('Ymd'), 'limit' => '500', ...($league['query'] ?? [])];
    }

    /**
     * @param  array<string, mixed>  $league
     */
    private function cacheKey(array $league, CarbonImmutable $day): string
    {
        return 'sports:espn:v4:'.md5($this->apiUrl()).':'.self::leagueId($league).':'.$day->format('Ymd');
    }

    private function fetchLockKey(): string
    {
        return 'sports:espn:v4:'.md5($this->apiUrl()).':fetching';
    }

    private function pauseCacheKey(): string
    {
        return 'sports:espn:v4:'.md5($this->apiUrl()).':paused';
    }

    private function teamsCacheKey(string $leagueId): string
    {
        return 'sports:espn:v4:'.md5($this->apiUrl()).':teams:'.$leagueId;
    }

    /**
     * @param  array<string, mixed>  $league
     */
    private function teamsUrl(array $league): string
    {
        return $this->apiUrl().'/'.$league['sport'].'/'.$league['slug'].'/teams';
    }

    private function allSoccerCacheKey(CarbonImmutable $day): string
    {
        return 'sports:espn:v4:'.md5($this->apiUrl()).':soccer-all:'.$day->format('Ymd');
    }

    /**
     * @param  array<string, mixed>  $league
     */
    private function seasonCacheKey(array $league): string
    {
        return 'sports:espn:v4:'.md5($this->apiUrl()).':season:'.self::leagueId($league);
    }
}
