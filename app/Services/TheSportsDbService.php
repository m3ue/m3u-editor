<?php

namespace App\Services;

use App\Services\Sports\MapsSportsData;
use App\Services\Sports\SportsProvider;
use App\Services\Sports\TheSportsDbLeagueCatalog;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Game schedules and scores from TheSportsDB's documented API.
 *
 * A paid key is required: the free one (`123`) returns 3 events a day and 10
 * teams a league, too few to list a league's games or match channel titles.
 *
 * One request returns a whole day across every sport, so unlike ESPN's
 * scoreboards the cost doesn't grow with the league set (only a day past
 * [DAY_EVENT_LIMIT] takes more). Each day is split up
 * and cached by league, with a marker listing the leagues that played, so a
 * reader only loads the leagues it asked for. Scores between those fetches come
 * from the all-sports livescore feed, which TheSportsDB updates every 2 minutes.
 */
class TheSportsDbService implements SportsProvider
{
    use MapsSportsData;

    public const string API_V1_URL = 'https://www.thesportsdb.com/api/v1/json';

    public const string API_V2_URL = 'https://www.thesportsdb.com/api/v2/json';

    /** The free public key, which TheSportsDB limits too far to be used here. */
    public const string FREE_KEY = '123';

    /** Slug prefix marking a league as this provider's. */
    private const string SLUG_PREFIX = 'tsdb.';

    private const int CONCURRENCY = 4;

    private const int REQUEST_TIMEOUT_SECONDS = 15;

    /**
     * Requests per clock minute, across every process. Two clock minutes can
     * fall inside the 100 a rolling minute the cheapest paid plan allows, so
     * this is under half of it.
     */
    private const int REQUESTS_PER_MINUTE = 45;

    /**
     * What team lists may spend of that, leaving room for schedules and scores:
     * a first run has hundreds of lists to fetch, and clients shouldn't wait.
     */
    private const int TEAM_REQUESTS_PER_MINUTE = 35;

    /** How long a day's cache is kept past its freshness, for game matching. */
    private const int MATCHING_STALE_MINUTES = 30;

    /** How long one process may hold the fetch lock. */
    private const int FETCH_LOCK_SECONDS = 120;

    /** How long requests pause after TheSportsDB refuses or fails a batch. */
    private const int PAUSE_MINUTES = 5;

    /** Failed requests in one batch that, as half of it or more, pause requests. */
    private const int PAUSE_MIN_FAILURES = 3;

    /** How long the livescore feed is cached: it only updates this often. */
    private const int LIVE_SCORES_CACHE_MINUTES = 2;

    /** A game not marked finished this long after kickoff is treated as over. */
    private const int LIVE_WINDOW_HOURS = 8;

    /**
     * The most events `eventsday.php` returns. A busy Saturday reaches it, and
     * games past it are simply left out, so such a day is asked for by sport.
     */
    private const int DAY_EVENT_LIMIT = 1500;

    /** The team sports the catalogue holds, as TheSportsDB names them. */
    private const array TEAM_SPORTS = ['Soccer', 'Basketball', 'Ice Hockey', 'Rugby', 'Cricket', 'Volleyball', 'Baseball', 'American Football', 'Handball', 'Field Hockey', 'Netball', 'Lacrosse', 'Australian Football', 'Gaelic'];

    /** Statuses of a game that's over. */
    private const array FINISHED_STATUSES = ['FT', 'AET', 'AOT', 'AP', 'PEN', 'FT_PEN', 'AWD', 'WO', 'FINAL', 'MATCH FINISHED'];

    /** Statuses of a game that isn't being played as scheduled. */
    private const array STOPPED_STATUSES = ['PST', 'POST', 'POSTPONED', 'CANC', 'CANCELLED', 'CANCELED', 'ABD', 'ABANDONED', 'SUSP', 'SUSPENDED'];

    /** Statuses of a game that hasn't started. */
    private const array SCHEDULED_STATUSES = ['', 'NS', 'TBD', 'NOT STARTED', 'SCHEDULED'];

    /** @var array<string, array<string, mixed>>|null catalogue entries by league id */
    private static ?array $leaguesById = null;

    /** @var array<string, array<string, mixed>>|null catalogue entries by TheSportsDB's id */
    private static ?array $leaguesByTsdbId = null;

    /** @var array<string, string>|null league ids by the ESPN league they repeat */
    private static ?array $idsByEspnId = null;

    public function __construct(private readonly GeneralSettings $settings) {}

    public function isEnabled(): bool
    {
        return (bool) $this->settings->sports_tsdb_enabled && self::isUsableKey($this->apiKey());
    }

    public function apiKey(): string
    {
        return trim((string) $this->settings->sports_tsdb_api_key);
    }

    /**
     * Whether [$apiKey] could be a paid key: set, and not the free one.
     */
    public static function isUsableKey(?string $apiKey): bool
    {
        $apiKey = trim((string) $apiKey);

        return $apiKey !== '' && $apiKey !== self::FREE_KEY;
    }

    /**
     * Whether [$leagueId] is one of this provider's.
     */
    public static function owns(string $leagueId): bool
    {
        return str_contains($leagueId, '/'.self::SLUG_PREFIX);
    }

    /**
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
        ], TheSportsDbLeagueCatalog::LEAGUES);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findLeague(string $leagueId): ?array
    {
        self::indexCatalogue();

        return self::$leaguesById[$leagueId] ?? null;
    }

    /**
     * This provider's id for the league ESPN calls [$espnLeagueId], if it has it.
     */
    public static function leagueIdForEspn(string $espnLeagueId): ?string
    {
        self::indexCatalogue();

        return self::$idsByEspnId[$espnLeagueId] ?? null;
    }

    /**
     * @param  list<string>  $leagueIds
     * @return list<array<string, mixed>>
     */
    public function fixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to): array
    {
        return array_map(function (array $fixture): array {
            unset($fixture['home']['team']['aliases'], $fixture['away']['team']['aliases']);

            return $this->present($fixture);
        }, $this->rawFixtures($leagueIds, $from, $to));
    }

    /**
     * @param  list<string>  $leagueIds
     * @return list<array<string, mixed>>
     */
    public function rawFixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to, bool $liveScores = true): array
    {
        [$fixtures] = $this->collectFixtures($leagueIds, $from, $to, $liveScores, fetchMissing: true);

        return $liveScores ? $this->withLiveScores($fixtures) : $fixtures;
    }

    /**
     * @param  list<string>  $leagueIds
     * @return array{fixtures: list<array<string, mixed>>, complete: bool}
     */
    public function cachedFixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to): array
    {
        [$fixtures, $missing] = $this->collectFixtures($leagueIds, $from, $to, liveScores: false, fetchMissing: false);

        return ['fixtures' => $fixtures, 'complete' => $missing === 0];
    }

    /**
     * Days are fetched whole, so this drops them for every league.
     *
     * @param  list<string>  $leagueIds
     */
    public function fixtureDetails(string $leagueId, string $fixtureId): ?array
    {
        $league = self::findLeague($leagueId);
        if ($league === null || ! $this->isEnabled()) {
            return null;
        }

        $key = $this->cacheKey('details:'.$leagueId.':'.$fixtureId);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === false ? null : $this->presentDetails($cached);
        }
        if ($this->isPaused() || ! $this->spendRequestBudget(3)) {
            return null;
        }

        $responses = Http::pool(fn (Pool $pool): array => array_map(
            fn (string $endpoint): mixed => $pool->as($endpoint)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get($this->v1Url($endpoint.'.php'), ['id' => $fixtureId]),
            ['lookupevent', 'lookupeventstats', 'lookuptimeline'],
        ));
        $this->pauseIfRefused($responses);
        $decoded = [];
        foreach ($responses as $endpoint => $response) {
            // A game without stats or a timeline yet still has details
            $decoded[$endpoint] = $response instanceof Response && $response->successful()
                ? json_decode($response->body(), true)
                : null;
        }

        $details = self::parseFixtureDetails($decoded['lookupevent'], $decoded['lookupeventstats'], $decoded['lookuptimeline'], $league);
        if ($details === null) {
            Log::warning('TheSportsDB game details fetch failed', [
                'league' => $leagueId,
                'fixture' => $fixtureId,
                'reason' => $this->failureReason($responses['lookupevent']),
            ]);
            Cache::put($key, false, now()->addMinute());

            return null;
        }
        $details['fixture'] = $this->withLiveScores([$details['fixture']])[0];
        Cache::put($key, $details, self::detailsExpiry($details['fixture'], self::LIVE_SCORES_CACHE_MINUTES * 60));

        return $this->presentDetails($details);
    }

    /**
     * Maps `lookupevent`, `lookupeventstats` and `lookuptimeline` responses,
     * or null without a usable game. Exposed for tests.
     *
     * @param  array<string, mixed>  $league
     * @return array<string, mixed>|null
     */
    public static function parseFixtureDetails(mixed $eventJson, mixed $statsJson, mixed $timelineJson, array $league): ?array
    {
        $event = is_array($eventJson['events'][0] ?? null) ? $eventJson['events'][0] : null;
        $fixture = $event === null ? null : self::parseEvent($event, $league);
        if ($fixture === null) {
            return null;
        }

        $stats = [];
        foreach ((array) ($statsJson['eventstats'] ?? []) as $stat) {
            $label = is_array($stat) ? self::string($stat['strStat'] ?? null) : null;
            $home = is_array($stat) ? self::string($stat['intHome'] ?? null) : null;
            $away = is_array($stat) ? self::string($stat['intAway'] ?? null) : null;
            if ($label !== null && $home !== null && $away !== null) {
                $stats[] = ['label' => $label, 'home' => $home, 'away' => $away];
            }
        }

        $events = [];
        foreach ((array) ($timelineJson['timeline'] ?? []) as $entry) {
            if (is_array($entry) && ($mapped = self::timelineEvent($entry)) !== null) {
                $events[] = $mapped;
            }
        }

        $location = array_filter([self::string($event['strCity'] ?? null), self::string($event['strCountry'] ?? null)]);
        $attendance = is_numeric($event['intSpectators'] ?? null) ? (int) $event['intSpectators'] : 0;

        return [
            'fixture' => $fixture,
            'line_score' => null,
            'events' => $events,
            'stats' => $stats,
            'leaders' => [],
            'info' => [
                'venue' => $fixture['venue'],
                'location' => $location === [] ? null : implode(', ', $location),
                'attendance' => $attendance > 0 ? $attendance : null,
                'officials' => [],
                'series' => null,
                'home_record' => null,
                'away_record' => null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private static function timelineEvent(array $entry): ?array
    {
        $type = strtolower((string) self::string($entry['strTimeline'] ?? null));
        $detail = self::string($entry['strTimelineDetail'] ?? null);
        $kind = match (true) {
            str_contains($type, 'goal') => 'score',
            str_contains($type, 'subst') => 'substitution',
            str_contains($type, 'card') => str_contains(strtolower((string) $detail), 'red') ? 'red_card' : 'yellow_card',
            default => null,
        };
        $player = self::string($entry['strPlayer'] ?? null);
        if ($kind === null || $player === null) {
            return null;
        }
        $assist = self::string($entry['strAssist'] ?? null);
        $text = match ($kind) {
            'substitution' => $assist === null ? $player : $player.' / '.$assist,
            default => implode(' - ', array_filter([$player, $detail, $kind === 'score' ? $assist : null])),
        };
        $minute = self::string($entry['intTime'] ?? null);
        $isHome = strtolower((string) self::string($entry['strHome'] ?? null));

        return [
            'kind' => $kind,
            'time' => $minute === null ? null : $minute."'",
            'side' => match ($isHome) {
                'yes' => 'home',
                'no' => 'away',
                default => null,
            },
            'text' => $text,
            'home_score' => null,
            'away_score' => null,
        ];
    }

    public function forgetFixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to): void
    {
        $days = $this->days(CarbonImmutable::instance($from)->utc(), CarbonImmutable::instance($to)->utc());
        Cache::deleteMultiple(array_map($this->dayCacheKey(...), $days));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function teams(string $leagueId): array
    {
        $teams = $this->teamsByLeague([$leagueId])[$leagueId] ?? [];

        return array_map(function (array $team): array {
            unset($team['aliases']);

            return [...$team, 'logo' => $team['logo'] !== null ? self::proxiedLogo($team['logo']) : null];
        }, $teams);
    }

    /**
     * Lists not cached yet are fetched as far as the minute's request budget
     * goes; the next refresh fetches the rest.
     *
     * @param  list<string>  $leagueIds
     * @return array<string, list<array<string, mixed>>>
     */
    public function teamsByLeague(array $leagueIds, bool $fetchMissing = true): array
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
                $misses[$key] = $leagueId;
            }
        }
        if (! $fetchMissing) {
            return $teams;
        }

        foreach (array_chunk($misses, self::CONCURRENCY, preserve_keys: true) as $batch) {
            if ($this->isPaused() || ! $this->spendRequestBudget(count($batch), self::TEAM_REQUESTS_PER_MINUTE)) {
                break;
            }
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (string $key): mixed => $pool->as($key)
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->get($this->v1Url('search_all_teams.php'), ['l' => self::findLeague($batch[$key])['name']]),
                array_keys($batch),
            ));
            $this->pauseIfRefused($responses);
            foreach ($batch as $key => $leagueId) {
                $teams[$leagueId] = $this->storeTeams($key, $leagueId, $responses[$key] ?? null);
                unset($responses[$key]);
            }
        }

        return $teams;
    }

    /**
     * Asks TheSportsDB for one league's teams with [$apiKey] (else the
     * configured key), bypassing the cache, and returns how many it listed.
     * Throws when the key is the free one, is refused, or the API can't be
     * reached.
     */
    public function testConnection(?string $apiKey = null): int
    {
        $apiKey = trim((string) $apiKey) !== '' ? trim((string) $apiKey) : $this->apiKey();
        if (! self::isUsableKey($apiKey)) {
            throw new RuntimeException(__('A paid TheSportsDB API key is required.'));
        }

        // The v2 API only answers paid keys, so this also tells a free one apart
        $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->acceptJson()
            ->withHeaders(['X-API-KEY' => $apiKey])
            ->get(self::API_V2_URL.'/list/teams/4380');

        if (in_array($response->status(), [400, 401, 403], true)) {
            throw new RuntimeException(__('TheSportsDB refused this API key. A paid key is required.'));
        }
        if (! $response->successful()) {
            throw new RuntimeException(__('The API answered with HTTP :status.', ['status' => $response->status()]));
        }
        $teams = $response->json('list');
        if (! is_array($teams)) {
            throw new RuntimeException(__('The API answered, but not with a list of teams.'));
        }

        return count($teams);
    }

    /**
     * @param  list<string>  $leagueIds
     * @return array{0: list<array<string, mixed>>, 1: int} fixtures, and how many days weren't cached and weren't fetched
     */
    private function collectFixtures(array $leagueIds, CarbonInterface $from, CarbonInterface $to, bool $liveScores, bool $fetchMissing): array
    {
        $from = CarbonImmutable::instance($from)->utc();
        $to = CarbonImmutable::instance($to)->utc();
        if ($to->greaterThan($from->addDays(self::MAX_RANGE_DAYS))) {
            $to = $from->addDays(self::MAX_RANGE_DAYS);
        }
        $wanted = array_filter(array_unique($leagueIds), fn (string $leagueId): bool => self::findLeague($leagueId) !== null);
        if (! $to->greaterThan($from) || $wanted === []) {
            return [[], 0];
        }

        $days = $this->days($from, $to);
        $markers = $this->dayMarkers($days);
        if ($fetchMissing) {
            $stale = array_values(array_filter($days, function (CarbonImmutable $day) use ($markers, $liveScores): bool {
                $marker = $markers[$this->dayCacheKey($day)] ?? null;

                return ! is_array($marker) || ($liveScores && $marker['fresh_until'] < now()->getTimestamp());
            }));
            if ($stale !== []) {
                // Another process fetching the same days will have cached them
                Cache::lock($this->cacheKey('fetching'), self::FETCH_LOCK_SECONDS)->get(fn () => $this->fetchDays($stale));
                $markers = $this->dayMarkers($days);
            }
        }

        $keys = [];
        $missing = 0;
        foreach ($days as $day) {
            $marker = $markers[$this->dayCacheKey($day)] ?? null;
            if (! is_array($marker)) {
                $missing++;

                continue;
            }
            foreach (array_intersect($marker['leagues'], $wanted) as $leagueId) {
                $keys[] = $this->leagueDayCacheKey($leagueId, $day);
            }
        }

        $fixtures = [];
        foreach ($keys === [] ? [] : Cache::many($keys) as $dayFixtures) {
            foreach ((array) $dayFixtures as $fixture) {
                $start = CarbonImmutable::parse($fixture['start']);
                if (! $start->lessThan($from) && $start->lessThan($to)) {
                    $fixtures[$fixture['id']] = $fixture;
                }
            }
        }
        $fixtures = array_values($fixtures);
        usort($fixtures, fn (array $a, array $b): int => strcmp($a['start'], $b['start']));

        return [$this->withTeamNames($fixtures), $missing];
    }

    /**
     * @param  list<CarbonImmutable>  $days
     * @return array<string, mixed>
     */
    private function dayMarkers(array $days): array
    {
        return Cache::many(array_map($this->dayCacheKey(...), $days));
    }

    /**
     * Fetches whole days in parallel batches and caches each by league.
     *
     * @param  list<CarbonImmutable>  $days
     */
    private function fetchDays(array $days): void
    {
        foreach (array_chunk($days, self::CONCURRENCY) as $batch) {
            if ($this->isPaused() || ! $this->spendRequestBudget(count($batch))) {
                return;
            }
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (CarbonImmutable $day): mixed => $pool->as($day->format('Ymd'))
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->get($this->v1Url('eventsday.php'), ['d' => $day->format('Y-m-d')]),
                $batch,
            ));
            $this->pauseIfRefused($responses);
            foreach ($batch as $day) {
                $this->storeDay($day, $responses[$day->format('Ymd')] ?? null);
                unset($responses[$day->format('Ymd')]);
            }
        }
    }

    /**
     * Caches a day's games by league. A failed day keeps what was cached and
     * is retried in a minute.
     */
    private function storeDay(CarbonImmutable $day, mixed $response): void
    {
        $key = $this->dayCacheKey($day);
        if (! $response instanceof Response || ! $response->successful()) {
            Log::warning('TheSportsDB schedule fetch failed', ['day' => $day->format('Ymd'), 'reason' => $this->failureReason($response)]);
            $previous = Cache::get($key);
            Cache::put($key, [
                'fresh_until' => now()->addMinute()->getTimestamp(),
                'leagues' => is_array($previous) ? $previous['leagues'] : [],
            ], now()->addMinute());

            return;
        }

        // Not $response->json(), which keeps the decoded copy on the response
        $json = json_decode($response->body(), true);
        unset($response);
        if (is_array($json['events'] ?? null) && count($json['events']) >= self::DAY_EVENT_LIMIT) {
            $json['events'] = $this->withEventsBySport($json['events'], $day);
        }
        $byLeague = self::parseEvents($json);
        $freshUntil = $this->ttl(array_merge([], ...array_values($byLeague)), $day);
        $expiresAt = max($freshUntil, now()->addMinutes(self::MATCHING_STALE_MINUTES));

        $entries = [];
        foreach ($byLeague as $leagueId => $fixtures) {
            $entries[$this->leagueDayCacheKey($leagueId, $day)] = $fixtures;
        }
        if ($entries !== []) {
            // Outlives the marker, which is what says to read them
            Cache::putMany($entries, $expiresAt->copy()->addMinutes(5));
        }
        Cache::put($key, ['fresh_until' => $freshUntil->getTimestamp(), 'leagues' => array_keys($byLeague)], $expiresAt);
    }

    /**
     * [$events], a day cut short at [DAY_EVENT_LIMIT], plus what each team
     * sport's own request adds. No sport has been seen to reach the limit
     * alone. Past the minute's budget the day stays as it is until its next
     * refresh.
     *
     * @param  list<mixed>  $events
     * @return list<mixed>
     */
    private function withEventsBySport(array $events, CarbonImmutable $day): array
    {
        $byId = array_column($events, null, 'idEvent');
        foreach (array_chunk(self::TEAM_SPORTS, self::CONCURRENCY) as $batch) {
            if ($this->isPaused() || ! $this->spendRequestBudget(count($batch))) {
                break;
            }
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (string $sport): mixed => $pool->as($sport)
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->get($this->v1Url('eventsday.php'), ['d' => $day->format('Y-m-d'), 's' => $sport]),
                $batch,
            ));
            $this->pauseIfRefused($responses);
            foreach ($batch as $sport) {
                $response = $responses[$sport] ?? null;
                unset($responses[$sport]);
                if (! $response instanceof Response || ! $response->successful()) {
                    Log::warning('TheSportsDB schedule fetch failed', ['day' => $day->format('Ymd'), 'sport' => $sport, 'reason' => $this->failureReason($response)]);

                    continue;
                }
                $sportEvents = json_decode($response->body(), true)['events'] ?? null;
                $byId += array_column(is_array($sportEvents) ? $sportEvents : [], null, 'idEvent');
            }
        }

        return array_values($byId);
    }

    /**
     * Maps an `eventsday.php` response to fixtures by league id. Leagues
     * outside the catalogue and events without two teams (a race, a fight
     * card) are dropped. Exposed for tests.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public static function parseEvents(mixed $json): array
    {
        $events = is_array($json) ? ($json['events'] ?? null) : null;
        if (! is_array($events)) {
            return [];
        }
        self::indexCatalogue();

        $byLeague = [];
        foreach ($events as $event) {
            $league = is_array($event) ? (self::$leaguesByTsdbId[(string) ($event['idLeague'] ?? '')] ?? null) : null;
            if ($league !== null && ($fixture = self::parseEvent($event, $league)) !== null) {
                $byLeague[self::leagueId($league)][] = $fixture;
            }
        }

        return $byLeague;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $league
     * @return array<string, mixed>|null
     */
    private static function parseEvent(array $event, array $league): ?array
    {
        $id = self::string($event['idEvent'] ?? null);
        $start = self::parseDate(self::string($event['strTimestamp'] ?? null));
        $homeName = self::string($event['strHomeTeam'] ?? null);
        $awayName = self::string($event['strAwayTeam'] ?? null);
        $homeId = self::string($event['idHomeTeam'] ?? null);
        $awayId = self::string($event['idAwayTeam'] ?? null);
        if ($id === null || $start === null || $homeName === null || $awayName === null || $homeId === null || $awayId === null) {
            return null;
        }

        $status = self::string($event['strStatus'] ?? null);
        $state = self::state($status, $start, self::string($event['strPostponed'] ?? null) === 'yes');
        $homeScore = self::string($event['intHomeScore'] ?? null);
        $awayScore = self::string($event['intAwayScore'] ?? null);

        return [
            'id' => $id,
            'league_id' => self::leagueId($league),
            'start' => $start->toIso8601ZuluString(),
            'state' => $state,
            'status_detail' => self::statusDetail($state, $status, null),
            'name' => self::string($event['strEvent'] ?? null),
            'broadcasts' => [],
            'venue' => self::string($event['strVenue'] ?? null),
            'home' => [
                'score' => $homeScore,
                'winner' => self::isWinner($state, $homeScore, $awayScore),
                'team' => self::eventTeam($homeId, $homeName, self::string($event['strHomeTeamBadge'] ?? null)),
            ],
            'away' => [
                'score' => $awayScore,
                'winner' => self::isWinner($state, $awayScore, $homeScore),
                'team' => self::eventTeam($awayId, $awayName, self::string($event['strAwayTeamBadge'] ?? null)),
            ],
            'league_logo' => self::string($event['strLeagueBadge'] ?? null) ?? ($league['logo'] ?? null),
        ];
    }

    /**
     * A team as an event names it: the full team list adds its other names
     * through [withTeamNames].
     *
     * @return array<string, mixed>
     */
    private static function eventTeam(string $id, string $name, ?string $logo): array
    {
        return [
            'id' => $id,
            'display_name' => $name,
            'short_name' => $name,
            'nickname' => $name,
            'aliases' => [],
            'abbreviation' => '',
            'location' => null,
            'logo' => $logo,
            'color' => null,
            'alternate_color' => null,
        ];
    }

    private static function state(?string $status, CarbonImmutable $start, bool $postponed): string
    {
        $status = strtoupper((string) $status);
        if ($postponed || in_array($status, self::STOPPED_STATUSES, true)) {
            return 'postponed';
        }
        if (in_array($status, self::FINISHED_STATUSES, true)) {
            return 'finished';
        }
        $now = CarbonImmutable::now('UTC');
        // Long past kickoff with no result recorded: it's over, or never played
        if ($start->addHours(self::LIVE_WINDOW_HOURS)->lessThan($now)) {
            return $status === '' || $status === 'NS' ? 'scheduled' : 'finished';
        }
        if (in_array($status, self::SCHEDULED_STATUSES, true) || $start->greaterThan($now)) {
            return 'scheduled';
        }

        return 'live';
    }

    private static function statusDetail(string $state, ?string $status, ?string $progress): string
    {
        return match ($state) {
            'finished' => 'Final',
            'postponed' => 'Postponed',
            'live' => $progress !== null && preg_match('/^\d+(\+\d+)?$/', $progress) === 1 ? $progress."'" : ($progress ?? $status ?? ''),
            default => '',
        };
    }

    private static function isWinner(string $state, ?string $score, ?string $otherScore): bool
    {
        return $state === 'finished' && is_numeric($score) && is_numeric($otherScore) && (float) $score > (float) $otherScore;
    }

    /**
     * Adds the names only a league's team list has (a nickname, other forms of
     * the name) from what's cached, for matching channel titles.
     *
     * @param  list<array<string, mixed>>  $fixtures
     * @return list<array<string, mixed>>
     */
    private function withTeamNames(array $fixtures): array
    {
        $teamsByLeague = [];
        foreach ($this->teamsByLeague(array_values(array_unique(array_column($fixtures, 'league_id'))), fetchMissing: false) as $leagueId => $teams) {
            $teamsByLeague[$leagueId] = array_column($teams, null, 'id');
        }

        foreach ($fixtures as &$fixture) {
            foreach (['home', 'away'] as $side) {
                $known = $teamsByLeague[$fixture['league_id']][$fixture[$side]['team']['id']] ?? null;
                if ($known !== null) {
                    // The event's crest stays: it's what the provider shows for this game
                    $fixture[$side]['team'] = [...$known, 'logo' => $fixture[$side]['team']['logo'] ?? $known['logo']];
                }
            }
        }

        return $fixtures;
    }

    /**
     * Applies the livescore feed to the games it could be reporting.
     *
     * @param  list<array<string, mixed>>  $fixtures
     * @return list<array<string, mixed>>
     */
    private function withLiveScores(array $fixtures): array
    {
        $now = CarbonImmutable::now('UTC');
        $mayBeLive = fn (array $fixture): bool => in_array($fixture['state'], ['scheduled', 'live'], true)
            && CarbonImmutable::parse($fixture['start'])->between($now->subHours(self::LIVE_WINDOW_HOURS), $now->addMinutes(5));
        if (array_filter($fixtures, $mayBeLive) === []) {
            return $fixtures;
        }

        $scores = $this->liveScores();
        foreach ($fixtures as &$fixture) {
            $live = $scores[$fixture['id']] ?? null;
            if ($live === null || ! $mayBeLive($fixture)) {
                continue;
            }
            $fixture['state'] = self::state($live['status'], CarbonImmutable::parse($fixture['start']), false);
            $fixture['status_detail'] = self::statusDetail($fixture['state'], $live['status'], $live['progress']);
            $fixture['home']['score'] = $live['home'] ?? $fixture['home']['score'];
            $fixture['away']['score'] = $live['away'] ?? $fixture['away']['score'];
            $fixture['home']['winner'] = self::isWinner($fixture['state'], $fixture['home']['score'], $fixture['away']['score']);
            $fixture['away']['winner'] = self::isWinner($fixture['state'], $fixture['away']['score'], $fixture['home']['score']);
        }

        return $fixtures;
    }

    /**
     * The livescore feed by event id, cached as long as it takes to change. A
     * failure leaves scores as the day's schedule has them.
     *
     * @return array<string, array{status: ?string, progress: ?string, home: ?string, away: ?string}>
     */
    private function liveScores(): array
    {
        $key = $this->cacheKey('live');
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if ($this->isPaused() || ! $this->spendRequestBudget(1)) {
            return [];
        }

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->acceptJson()
                ->withHeaders(['X-API-KEY' => $this->apiKey()])
                ->get(self::API_V2_URL.'/livescore/all');
        } catch (Throwable $e) {
            $response = $e;
        }
        $this->pauseIfRefused(['live' => $response]);
        if (! $response instanceof Response || ! $response->successful()) {
            Log::warning('TheSportsDB livescore fetch failed', ['reason' => $this->failureReason($response)]);
            Cache::put($key, [], now()->addMinute());

            return [];
        }

        $scores = self::parseLiveScores(json_decode($response->body(), true));
        Cache::put($key, $scores, now()->addMinutes(self::LIVE_SCORES_CACHE_MINUTES));

        return $scores;
    }

    /**
     * Maps a `livescore/all` response by event id. Exposed for tests.
     *
     * @return array<string, array{status: ?string, progress: ?string, home: ?string, away: ?string}>
     */
    public static function parseLiveScores(mixed $json): array
    {
        $scores = [];
        foreach (is_array($json['livescore'] ?? null) ? $json['livescore'] : [] as $score) {
            $id = is_array($score) ? self::string($score['idEvent'] ?? null) : null;
            if ($id !== null) {
                $scores[$id] = [
                    'status' => self::string($score['strStatus'] ?? null),
                    'progress' => self::string($score['strProgress'] ?? null),
                    'home' => self::string($score['intHomeScore'] ?? null),
                    'away' => self::string($score['intAwayScore'] ?? null),
                ];
            }
        }

        return $scores;
    }

    /**
     * Caches a team list response for a week, or nothing for a few minutes
     * when the request failed.
     *
     * @return list<array<string, mixed>>
     */
    private function storeTeams(string $key, string $leagueId, mixed $response): array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            Log::warning('TheSportsDB teams fetch failed', ['league' => $leagueId, 'reason' => $this->failureReason($response)]);
            Cache::put($key, [], now()->addMinutes(5));

            return [];
        }
        $teams = self::parseTeams(json_decode($response->body(), true));
        Cache::put($key, $teams, now()->addDays(7));

        return $teams;
    }

    /**
     * Maps a `search_all_teams.php` response, alphabetically. Exposed for tests.
     *
     * @return list<array<string, mixed>>
     */
    public static function parseTeams(mixed $json): array
    {
        $teams = [];
        foreach (is_array($json['teams'] ?? null) ? $json['teams'] : [] as $team) {
            if (! is_array($team)) {
                continue;
            }
            $id = self::string($team['idTeam'] ?? null);
            $name = self::string($team['strTeam'] ?? null);
            if ($id === null || $name === null) {
                continue;
            }
            $aliases = self::teamAliases(self::string($team['strTeamAlternate'] ?? null));
            $teams[] = [
                'id' => $id,
                'display_name' => $name,
                'short_name' => $name,
                'nickname' => $aliases[0] ?? $name,
                'aliases' => array_slice($aliases, 1),
                'abbreviation' => self::string($team['strTeamShort'] ?? null) ?? '',
                'location' => null,
                'logo' => self::string($team['strBadge'] ?? null),
                'color' => self::hex($team['strColour1'] ?? null),
                'alternate_color' => self::hex($team['strColour2'] ?? null),
            ];
        }
        usort($teams, fn (array $a, array $b): int => strcasecmp($a['display_name'], $b['display_name']));

        return $teams;
    }

    /**
     * A team's other names, from a comma-separated list. Initialisms ("AFC",
     * "BFC") are left out: a channel title would name them by accident.
     *
     * @return list<string>
     */
    private static function teamAliases(?string $alternates): array
    {
        $aliases = [];
        foreach (explode(',', (string) $alternates) as $alias) {
            $alias = trim($alias);
            if (mb_strlen($alias) >= 4 && ! preg_match('/^[\p{Lu}\d.]+$/u', $alias)) {
                $aliases[] = $alias;
            }
        }

        return $aliases;
    }

    /**
     * The days whose schedules cover [$from, $to): a day either side, as a
     * league's night games can land on the next UTC day.
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
     * A day with a game on refreshes every few minutes (the livescore feed
     * covers scores between), today and tomorrow's busy days every ten, days
     * ahead or without games hourly, and a day long over rarely.
     *
     * @param  list<array<string, mixed>>  $fixtures
     */
    private function ttl(array $fixtures, CarbonImmutable $day): CarbonInterface
    {
        $now = CarbonImmutable::now('UTC');
        foreach ($fixtures as $fixture) {
            if ($fixture['state'] === 'live') {
                return $now->addMinutes(5);
            }
        }
        if ($day->addDays(2)->lessThan($now)) {
            return $now->addHours(6);
        }

        return $fixtures === [] || $day->subDay()->greaterThan($now) ? $now->addHour() : $now->addMinutes(10);
    }

    /**
     * Spends [$requests] of this minute's budget, whether or not they fit, and
     * returns whether all of them stay within [$limit].
     */
    private function spendRequestBudget(int $requests, int $limit = self::REQUESTS_PER_MINUTE): bool
    {
        $key = $this->cacheKey('budget:'.now()->format('YmdHi'));
        Cache::add($key, 0, now()->addMinutes(2));

        return (int) Cache::increment($key, $requests) <= $limit;
    }

    private function isPaused(): bool
    {
        return Cache::has($this->cacheKey('paused'));
    }

    /**
     * Pauses requests for [PAUSE_MINUTES] when the key is refused (TheSportsDB
     * answers a bad key with a 400) or rate limited, or a batch mostly fails.
     *
     * @param  array<string, mixed>  $responses
     */
    private function pauseIfRefused(array $responses): void
    {
        $failed = 0;
        $refused = false;
        foreach ($responses as $response) {
            if ($response instanceof Response && $response->status() === 404) {
                continue;
            }
            if (! $response instanceof Response || ! $response->successful()) {
                $failed++;
                $refused = $refused || ($response instanceof Response && in_array($response->status(), [400, 401, 403, 429], true));
            }
        }
        if ($refused || ($failed >= self::PAUSE_MIN_FAILURES && $failed * 2 >= count($responses))) {
            Cache::put($this->cacheKey('paused'), true, now()->addMinutes(self::PAUSE_MINUTES));
            Log::warning('TheSportsDB requests paused', ['minutes' => self::PAUSE_MINUTES, 'failed' => $failed, 'of' => count($responses)]);
        }
    }

    /**
     * Why a request failed, for the log. The v1 API takes its key in the URL,
     * which connection errors quote, so it's taken out here.
     */
    private function failureReason(mixed $response): string
    {
        $reason = match (true) {
            $response instanceof Response => 'HTTP '.$response->status(),
            $response instanceof Throwable => $response->getMessage(),
            default => 'no response',
        };

        return str_replace($this->apiKey(), '***', $reason);
    }

    private function v1Url(string $endpoint): string
    {
        return self::API_V1_URL.'/'.rawurlencode($this->apiKey()).'/'.$endpoint;
    }

    private function cacheKey(string $suffix): string
    {
        return 'sports:tsdb:v1:'.$suffix;
    }

    private function dayCacheKey(CarbonImmutable $day): string
    {
        return $this->cacheKey('day:'.$day->format('Ymd'));
    }

    private function leagueDayCacheKey(string $leagueId, CarbonImmutable $day): string
    {
        return $this->cacheKey('league:'.$leagueId.':'.$day->format('Ymd'));
    }

    private function teamsCacheKey(string $leagueId): string
    {
        return $this->cacheKey('teams:'.$leagueId);
    }

    /**
     * @param  array<string, mixed>  $league
     */
    private static function leagueId(array $league): string
    {
        return $league['sport'].'/'.$league['slug'];
    }

    private static function indexCatalogue(): void
    {
        if (self::$leaguesById !== null) {
            return;
        }
        self::$leaguesById = [];
        self::$leaguesByTsdbId = [];
        self::$idsByEspnId = [];
        foreach (TheSportsDbLeagueCatalog::LEAGUES as $league) {
            $id = self::leagueId($league);
            self::$leaguesById[$id] = $league;
            self::$leaguesByTsdbId[$league['tsdb_id']] = $league;
            if (isset($league['espn'])) {
                self::$idsByEspnId[$league['espn']] = $id;
            }
        }
    }
}
