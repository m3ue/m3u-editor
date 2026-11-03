<?php

namespace App\Services;

use App\Enums\ImageProfile;
use App\Http\Controllers\LogoProxyController;
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
     * Competitions offered to clients, in display order. Team sports only:
     * the TV finds a game's channel by both teams' names in the guide.
     *
     * @var list<array{sport: string, slug: string, name: string, abbreviation: string, soccer_style?: bool, default?: bool, query?: array<string, string>}>
     */
    private const array LEAGUES = [
        ['sport' => 'football', 'slug' => 'nfl', 'name' => 'NFL', 'abbreviation' => 'NFL', 'default' => true],
        ['sport' => 'basketball', 'slug' => 'nba', 'name' => 'NBA', 'abbreviation' => 'NBA', 'default' => true],
        ['sport' => 'baseball', 'slug' => 'mlb', 'name' => 'MLB', 'abbreviation' => 'MLB', 'default' => true],
        ['sport' => 'hockey', 'slug' => 'nhl', 'name' => 'NHL', 'abbreviation' => 'NHL', 'default' => true],
        ['sport' => 'basketball', 'slug' => 'wnba', 'name' => 'WNBA', 'abbreviation' => 'WNBA'],
        ['sport' => 'football', 'slug' => 'college-football', 'name' => 'College Football', 'abbreviation' => 'NCAAF', 'default' => true, 'query' => ['groups' => '80']],
        ['sport' => 'basketball', 'slug' => 'mens-college-basketball', 'name' => "Men's College Basketball", 'abbreviation' => 'NCAAM', 'query' => ['groups' => '50']],
        ['sport' => 'basketball', 'slug' => 'womens-college-basketball', 'name' => "Women's College Basketball", 'abbreviation' => 'NCAAW', 'query' => ['groups' => '50']],
        ['sport' => 'football', 'slug' => 'cfl', 'name' => 'CFL', 'abbreviation' => 'CFL'],
        ['sport' => 'soccer', 'slug' => 'usa.1', 'name' => 'MLS', 'abbreviation' => 'MLS', 'soccer_style' => true, 'default' => true],
        ['sport' => 'soccer', 'slug' => 'usa.nwsl', 'name' => 'NWSL', 'abbreviation' => 'NWSL', 'soccer_style' => true],
        ['sport' => 'soccer', 'slug' => 'mex.1', 'name' => 'Liga MX', 'abbreviation' => 'LMX', 'soccer_style' => true],
        ['sport' => 'soccer', 'slug' => 'eng.1', 'name' => 'Premier League', 'abbreviation' => 'EPL', 'soccer_style' => true, 'default' => true],
        ['sport' => 'soccer', 'slug' => 'eng.2', 'name' => 'EFL Championship', 'abbreviation' => 'EFL', 'soccer_style' => true],
        ['sport' => 'soccer', 'slug' => 'esp.1', 'name' => 'LaLiga', 'abbreviation' => 'LIGA', 'soccer_style' => true],
        ['sport' => 'soccer', 'slug' => 'ger.1', 'name' => 'Bundesliga', 'abbreviation' => 'BUN', 'soccer_style' => true],
        ['sport' => 'soccer', 'slug' => 'ita.1', 'name' => 'Serie A', 'abbreviation' => 'SA', 'soccer_style' => true],
        ['sport' => 'soccer', 'slug' => 'fra.1', 'name' => 'Ligue 1', 'abbreviation' => 'L1', 'soccer_style' => true],
        ['sport' => 'soccer', 'slug' => 'uefa.champions', 'name' => 'UEFA Champions League', 'abbreviation' => 'UCL', 'soccer_style' => true, 'default' => true],
        ['sport' => 'soccer', 'slug' => 'uefa.europa', 'name' => 'UEFA Europa League', 'abbreviation' => 'UEL', 'soccer_style' => true],
    ];

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
     * The competitions clients may list, as sent to them.
     *
     * @return list<array{id: string, name: string, abbreviation: string, soccer_style: bool, default: bool}>
     */
    public static function leagues(): array
    {
        return array_map(fn (array $league): array => [
            'id' => self::leagueId($league),
            'name' => $league['name'],
            'abbreviation' => $league['abbreviation'],
            'soccer_style' => $league['soccer_style'] ?? false,
            'default' => $league['default'] ?? false,
        ], self::LEAGUES);
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
        $from = CarbonImmutable::instance($from)->utc();
        $to = CarbonImmutable::instance($to)->utc();
        if ($to->greaterThan($from->addDays(self::MAX_RANGE_DAYS))) {
            $to = $from->addDays(self::MAX_RANGE_DAYS);
        }
        if (! $to->greaterThan($from)) {
            return [];
        }

        $leagues = array_values(array_filter(
            self::LEAGUES,
            fn (array $league): bool => in_array(self::leagueId($league), $leagueIds, true),
        ));

        $days = [];
        for ($day = $from->subDay()->startOfDay(); $day->lessThanOrEqualTo($to->addDay()); $day = $day->addDay()) {
            $days[] = $day;
        }

        $scoreboards = $this->scoreboards($leagues, $days);

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

        return array_map($this->present(...), $fixtures);
    }

    /**
     * Fetches today's NFL scoreboard from [$apiUrl] (else the configured
     * API), bypassing the cache, and returns how many events it listed.
     * Throws when the API can't be reached or answers with something that
     * isn't a scoreboard.
     */
    public function testConnection(?string $apiUrl = null): int
    {
        $league = self::LEAGUES[0];
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
     * Each league-day's mapped fixtures, from the cache where fresh and
     * fetched in parallel batches otherwise.
     *
     * @param  list<array<string, mixed>>  $leagues
     * @param  list<CarbonImmutable>  $days
     * @return list<list<array<string, mixed>>>
     */
    private function scoreboards(array $leagues, array $days): array
    {
        $results = [];
        $misses = [];
        foreach ($leagues as $league) {
            foreach ($days as $day) {
                $key = $this->cacheKey($league, $day);
                $cached = Cache::get($key);
                if (is_array($cached)) {
                    $results[] = $cached;
                } else {
                    $misses[$key] = [$league, $day];
                }
            }
        }

        foreach (array_chunk($misses, self::CONCURRENCY, preserve_keys: true) as $batch) {
            $responses = Http::pool(fn (Pool $pool): array => array_map(
                fn (string $key): mixed => $pool->as($key)
                    ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->get($this->scoreboardUrl($batch[$key][0]), $this->scoreboardQuery($batch[$key][0], $batch[$key][1])),
                array_keys($batch),
            ));

            foreach ($batch as $key => [$league, $day]) {
                $fixtures = $this->readScoreboard($responses[$key] ?? null, $league, $day);
                if ($fixtures === null) {
                    // A short negative cache, so one failing league-day isn't
                    // retried by every client refresh.
                    Cache::put($key, [], now()->addMinute());
                    $results[] = [];

                    continue;
                }
                Cache::put($key, $fixtures, $this->ttl($fixtures, $day));
                $results[] = $fixtures;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $league
     * @return list<array<string, mixed>>|null null when the request failed
     */
    private function readScoreboard(mixed $response, array $league, CarbonImmutable $day): ?array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            $reason = match (true) {
                $response instanceof Response => 'HTTP '.$response->status(),
                $response instanceof Throwable => $response->getMessage(),
                default => 'no response',
            };
            Log::warning('ESPN scoreboard fetch failed', [
                'league' => self::leagueId($league),
                'day' => $day->format('Ymd'),
                'reason' => $reason,
            ]);

            return null;
        }

        return self::parseScoreboard($response->json(), self::leagueId($league));
    }

    /**
     * A day with a game on refreshes every minute for its score; a day long
     * over rarely changes; a future one only gains times and broadcasters.
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

        return $day->addDays(2)->lessThan($now) ? $now->addHours(6) : $now->addMinutes(10);
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

        $fixtures = [];
        foreach ($events as $event) {
            if (is_array($event) && ($fixture = self::parseEvent($event, $leagueId)) !== null) {
                $fixtures[] = $fixture;
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
        $team = is_array($competitor['team'] ?? null) ? $competitor['team'] : null;
        $id = self::string($team['id'] ?? null);
        if ($team === null || $id === null) {
            return null;
        }

        $displayName = self::string($team['displayName'] ?? null) ?? self::string($team['name'] ?? null) ?? $id;
        $logo = self::string($team['logo'] ?? null) ?? self::string($team['logos'][0]['href'] ?? null);
        $score = $competitor['score'] ?? null;

        return [
            'score' => is_array($score) ? self::string($score['displayValue'] ?? null) : self::string($score),
            'winner' => ($competitor['winner'] ?? false) === true,
            'team' => [
                'id' => $id,
                'display_name' => $displayName,
                'short_name' => self::string($team['shortDisplayName'] ?? null) ?? $displayName,
                'nickname' => self::string($team['name'] ?? null) ?? $displayName,
                'abbreviation' => self::string($team['abbreviation'] ?? null) ?? '',
                'logo' => $logo,
                'color' => self::hex($team['color'] ?? null),
                'alternate_color' => self::hex($team['alternateColor'] ?? null),
            ],
        ];
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
                $fixture[$side]['team']['logo'] = LogoProxyController::generateProxyUrl($logo, profile: ImageProfile::Photo);
            }
        }

        return $fixture;
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
        return 'sports:espn:'.md5($this->apiUrl()).':'.self::leagueId($league).':'.$day->format('Ymd');
    }
}
