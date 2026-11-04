<?php

namespace App\Services\Sports;

use App\Jobs\RefreshSportsAedProfile;
use App\Models\AedProfile;
use App\Services\AedEvent;
use App\Services\EspnSportsService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The "Sports" step of an AED profile: finds the game a channel
 * title names (both teams, whole words, any provider format) and turns it into
 * an AED event with the real kickoff, a clean title and the league crest.
 *
 * Fixtures are loaded once per league set and window, so one instance should
 * serve a whole EPG build: titles are then matched through a word index rather
 * than against every fixture.
 */
class SportsAedResolver
{
    /**
     * Typical running time per sport when the profile sets none.
     */
    private const array SPORT_DURATION_MINUTES = [
        'football' => 210,
        'basketball' => 150,
        'hockey' => 165,
        'baseball' => 180,
        'soccer' => 120,
        'rugby' => 120,
        'rugby-league' => 120,
        'australian-football' => 180,
        'lacrosse' => 150,
        'volleyball' => 120,
        'field-hockey' => 120,
        'water-polo' => 90,
        'cricket' => 240,
        'handball' => 90,
        'netball' => 90,
        'gaelic' => 90,
    ];

    /**
     * Words that mark a title as a women's game. Providers name women's sides
     * exactly as the men's ("Arsenal"), so without one a title prefers the
     * men's game, and with one it only matches women's leagues. A bare "w"
     * counts only when it tags both sides ("Arsenal W vs Chelsea W"), not in
     * a name like "W Michigan".
     */
    private const array WOMEN_MARKERS = ['women', 'womens', 'woman', 'ladies', 'girls', 'femenil', 'femenino', 'femenina', 'feminin', 'feminine', 'feminino', 'frauen', 'vrouwen', 'ncaaw', 'wsl', 'wbb', 'wcbb'];

    /** Words that mark a title as a youth game, beside "u17".."u23". */
    private const array YOUTH_MARKERS = ['youth', 'under', 'junior', 'juniors', 'academy'];

    /**
     * A time read from the title must be this close to the game's kickoff: it
     * keeps a rotating event channel's stale title (last week's game) from
     * matching, and tells a series' back-to-back games apart.
     */
    private const int TITLE_TIME_TOLERANCE_HOURS = 18;

    /**
     * Words that name a sport, and the sports they can mean. Schools field
     * teams in many sports, so "football: UAlbany vs Bryant" must not match
     * their volleyball game. "Football" is soccer to much of the world. An
     * empty list is a sport no provider's catalogue covers: a tennis channel
     * can't be any game here.
     *
     * @var array<string, list<string>>
     */
    private const array SPORT_WORDS = [
        'football' => ['football', 'soccer', 'australian-football'],
        'flofootball' => ['football'],
        'nfl' => ['football'],
        'ncaaf' => ['football'],
        'cfb' => ['football'],
        'basketball' => ['basketball'],
        'hoops' => ['basketball'],
        'flohoops' => ['basketball'],
        'nba' => ['basketball'],
        'wnba' => ['basketball'],
        'ncaab' => ['basketball'],
        'ncaam' => ['basketball'],
        'ncaaw' => ['basketball'],
        'hockey' => ['hockey', 'field-hockey'],
        'flohockey' => ['hockey', 'field-hockey'],
        'nhl' => ['hockey'],
        'baseball' => ['baseball'],
        'softball' => ['baseball'],
        'mlb' => ['baseball'],
        'soccer' => ['soccer'],
        'futbol' => ['soccer'],
        'volleyball' => ['volleyball'],
        'flovolleyball' => ['volleyball'],
        'lacrosse' => ['lacrosse'],
        'rugby' => ['rugby', 'rugby-league'],
        'nrl' => ['rugby', 'rugby-league'],
        'afl' => ['australian-football', 'football'],
        'cricket' => ['cricket'],
        'handball' => ['handball'],
        'netball' => ['netball'],
        'gaelic' => ['gaelic'],
        'gaa' => ['gaelic'],
        'tennis' => [],
        'golf' => [],
        'swimming' => [],
        'wrestling' => [],
        'wwe' => [],
        'gymnastics' => [],
        'boxing' => [],
        'ufc' => [],
        'mma' => [],
        'darts' => [],
        'snooker' => [],
        'cycling' => [],
        'nascar' => [],
        'motogp' => [],
    ];

    /** Names providers give a side that isn't decided yet. */
    private const array PLACEHOLDER_TEAMS = ['TBD', 'TBA'];

    /** How far back the window reaches, so games under way still match. */
    public const int WINDOW_PAST_HOURS = 12;

    /**
     * Prepared fixture sets, keyed by league ids and window length. `names`
     * holds every multi-word team name of those leagues (from their full team
     * lists, not just today's games), by first word, with its team's key.
     *
     * @var array<string, array{fixtures: list<array<string, mixed>>, index: array<string, list<int>>, names: array<string, list<array{0: list<string>, 1: string}>>}>
     */
    private array $prepared = [];

    public function __construct(private readonly SportsService $sports) {}

    /**
     * Whether [$profile] should match games at all right now.
     */
    public function appliesTo(AedProfile $profile): bool
    {
        return $profile->sports_matching && $this->sports->isEnabled();
    }

    /**
     * The event for the game [$channelTitle] names, or null when it names none
     * of the profile's leagues' games in the window. [$titleTime] is a time
     * parsed from the title by the profile's regex fields, when it has any.
     */
    public function match(AedProfile $profile, string $channelTitle, ?CarbonInterface $titleTime = null, int $days = 5): ?AedEvent
    {
        $set = $this->fixtureSet($profile, $days);
        if ($set['fixtures'] === []) {
            return null;
        }

        $text = SportsTeamMatcher::normalize($channelTitle);
        $words = explode(' ', trim($text));
        $titleSports = $this->sportsNamedBy($text, $words);
        if ($titleSports === []) {
            return null;
        }
        $nameSpans = $this->nameSpans($words, $set['names']);
        $candidates = [];
        foreach (array_unique($words) as $word) {
            foreach ($set['index'][$word] ?? [] as $position) {
                $candidates[$position] = true;
            }
        }

        $wordCounts = array_count_values($words);
        $titleIsWomen = array_intersect_key($wordCounts, array_flip(self::WOMEN_MARKERS)) !== [] || ($wordCounts['w'] ?? 0) >= 2;
        $titleIsYouth = array_intersect_key($wordCounts, array_flip(self::YOUTH_MARKERS)) !== []
            || preg_grep('/^u(1[5-9]|2[0-3])$/', $words) !== [];

        $reference = $titleTime ? CarbonImmutable::instance($titleTime) : CarbonImmutable::now();
        // Without the profile's own time regex, a date the title states still
        // rules out other days' games: stale rotating titles, and a title
        // listing two games whose teams meet on another day.
        $titleDates = $titleTime ? [] : SportsTitleDates::candidates($channelTitle, $reference);
        $best = null;
        $bestRank = [PHP_INT_MAX, PHP_INT_MAX];
        foreach (array_keys($candidates) as $position) {
            $fixture = $set['fixtures'][$position];
            if ($titleSports !== null && ! in_array($fixture['sport'], $titleSports, true)) {
                continue;
            }
            // A marked title only matches its kind of league; an unmarked one
            // only falls back to a women's or youth game when it has to.
            if (($titleIsWomen && ! $fixture['women']) || ($titleIsYouth && ! $fixture['youth'])) {
                continue;
            }
            if (! $this->namesBoth($words, $nameSpans, $fixture)) {
                continue;
            }
            $gap = abs($fixture['start_ts'] - $reference->getTimestamp());
            if ($titleTime && $gap > self::TITLE_TIME_TOLERANCE_HOURS * 3600) {
                continue;
            }
            if (! $titleTime && $titleDates !== [] && ! $this->onAnyDate($fixture['start_ts'], $titleDates)) {
                continue;
            }
            $rank = [(int) ($fixture['women'] && ! $titleIsWomen) + (int) ($fixture['youth'] && ! $titleIsYouth), $gap];
            if ($rank < $bestRank) {
                $best = $fixture;
                $bestRank = $rank;
            }
        }

        return $best ? $this->event($profile, $channelTitle, $best) : null;
    }

    /**
     * The sports a title's words allow, or null when it names none. A title
     * naming both a sport we cover and one we don't still matches the first.
     *
     * @param  list<string>  $words
     * @return list<string>|null
     */
    private function sportsNamedBy(string $text, array $words): ?array
    {
        if (str_contains($text, ' field hockey ')) {
            return ['field-hockey'];
        }
        if (str_contains($text, ' water polo ')) {
            return ['water-polo'];
        }
        $named = array_intersect_key(self::SPORT_WORDS, array_flip($words));

        return $named === [] ? null : array_merge(...array_values($named));
    }

    /**
     * Where each known multi-word team name occurs in a title.
     *
     * @param  list<string>  $words
     * @param  array<string, list<array{0: list<string>, 1: string}>>  $names
     * @return list<array{0: int, 1: int, 2: string}> [first word, length, team key]
     */
    private function nameSpans(array $words, array $names): array
    {
        $spans = [];
        foreach ($words as $start => $word) {
            foreach ($names[$word] ?? [] as [$nameWords, $teamKey]) {
                if (array_slice($words, $start, count($nameWords)) === $nameWords) {
                    $spans[] = [$start, count($nameWords), $teamKey];
                }
            }
        }

        return $spans;
    }

    /**
     * Whether the title names both of a fixture's teams, at separate places
     * (one "Cougars" can't stand for two Cougars schools).
     *
     * @param  list<string>  $words
     * @param  list<array{0: int, 1: int, 2: string}>  $nameSpans
     * @param  array<string, mixed>  $fixture
     */
    private function namesBoth(array $words, array $nameSpans, array $fixture): bool
    {
        $home = $this->teamSpans($words, $nameSpans, $fixture['home_phrases'], $fixture['home_key']);
        if ($home === []) {
            return false;
        }
        foreach ($this->teamSpans($words, $nameSpans, $fixture['away_phrases'], $fixture['away_key']) as [$awayStart, $awayLength]) {
            foreach ($home as [$homeStart, $homeLength]) {
                if ($awayStart + $awayLength <= $homeStart || $homeStart + $homeLength <= $awayStart) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether [$start] falls within a day and a half of noon on any of
     * [$dates]: wide enough for a title dated in another time zone.
     *
     * @param  list<CarbonImmutable>  $dates
     */
    private function onAnyDate(int $start, array $dates): bool
    {
        foreach ($dates as $date) {
            if (abs($start - $date->setTime(12, 0)->getTimestamp()) <= 36 * 3600) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the title names a team: each occurrence of one of its phrases as
     * whole words that isn't merely inside a longer name of a different team.
     * "Southern" (Southern Jaguars) inside "Southern Illinois" (the Salukis)
     * doesn't name the Jaguars.
     *
     * @param  list<string>  $words
     * @param  list<array{0: int, 1: int, 2: string}>  $nameSpans
     * @param  list<string>  $phrases
     * @return list<array{0: int, 1: int}> [first word, length]
     */
    private function teamSpans(array $words, array $nameSpans, array $phrases, string $teamKey): array
    {
        $spans = [];
        foreach ($phrases as $phrase) {
            $phraseWords = explode(' ', $phrase);
            $length = count($phraseWords);
            foreach ($words as $start => $word) {
                if ($word !== $phraseWords[0] || array_slice($words, $start, $length) !== $phraseWords) {
                    continue;
                }
                $inOtherName = false;
                foreach ($nameSpans as [$spanStart, $spanLength, $spanTeam]) {
                    if ($spanTeam !== $teamKey && $spanLength > $length
                        && $spanStart <= $start && $start + $length <= $spanStart + $spanLength) {
                        $inOtherName = true;
                        break;
                    }
                }
                if (! $inOtherName) {
                    $spans[] = [$start, $length];
                }
            }
        }

        return $spans;
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    private function event(AedProfile $profile, string $channelTitle, array $fixture): AedEvent
    {
        $league = $this->sports->findLeague($fixture['league_id']);
        $leagueName = $league['name'] ?? $fixture['league_id'];
        $start = Carbon::createFromTimestamp($fixture['start_ts'], $profile->output_timezone ?: 'UTC');
        $duration = $profile->sports_event_duration_minutes
            ?? self::SPORT_DURATION_MINUTES[$fixture['sport']]
            ?? $profile->event_duration_minutes;

        $format = $profile->sports_title_format
            ?: ($league !== null && SportsService::isHomeFirst($league) ? '{home} vs {away}' : '{away} at {home}');
        $title = trim(str_replace(
            ['{home}', '{away}', '{league}', '{venue}', '{event}', '{channel}', '{date}', '{time}'],
            [
                $fixture['home_name'],
                $fixture['away_name'],
                $leagueName,
                $fixture['venue'] ?? '',
                $fixture['name'] ?? '',
                $channelTitle,
                $start->format('M j, Y'),
                $start->format('g:i A'),
            ],
            $format,
        ));

        $description = implode(' | ', array_filter([
            $leagueName,
            $fixture['venue'] ?? null,
            implode(', ', $fixture['broadcasts'] ?? []),
        ]));

        return new AedEvent(
            title: $title,
            description: $description !== '' ? $description : null,
            start: $start,
            end: $start->copy()->addMinutes($duration),
            durationMinutes: $duration,
            icon: ($fixture['league_logo'] ?? null) ? SportsService::proxiedLogo($fixture['league_logo']) : null,
            category: $profile->category ?: 'Sports',
        );
    }

    /**
     * The profile's leagues' fixtures in the window, each with its teams'
     * phrases, and an index from the first word of each home-team phrase to
     * the fixtures it can start a match for (both teams must be named, so the
     * home side alone is a sound filter).
     *
     * @return array{fixtures: list<array<string, mixed>>, index: array<string, list<int>>, names: array<string, list<array{0: list<string>, 1: string}>>}
     */
    private function fixtureSet(AedProfile $profile, int $days): array
    {
        $leagueIds = $profile->sports_league_ids ?: array_column($this->sports->leagues(), 'id');
        sort($leagueIds);
        $days = max(1, min($days, SportsService::MAX_RANGE_DAYS - 1));
        $key = implode(',', $leagueIds).'|'.$days;

        if (isset($this->prepared[$key])) {
            return $this->prepared[$key];
        }

        $now = CarbonImmutable::now('UTC');
        $fixtures = [];
        $index = [];
        $names = [];
        // Known names cover every way a team is named, its place included, so
        // "Southern" (the Jaguars) inside "Southern Illinois" (the Salukis) is
        // read as the Salukis.
        $addNames = function (array $team, string $teamKey) use (&$names): void {
            $phrases = array_unique([
                ...SportsTeamMatcher::phrasesFor($team),
                ...SportsTeamMatcher::phrasesFor($team, college: true),
            ]);
            foreach ($phrases as $phrase) {
                $phraseWords = explode(' ', $phrase);
                if (count($phraseWords) > 1) {
                    $names[$phraseWords[0]][] = [$phraseWords, $teamKey];
                }
            }
        };
        // EPG requests never wait on a provider: match what's cached, and have a
        // background refresh fetch whatever isn't yet
        $cached = $this->sports->cachedFixtures($leagueIds, $now->subHours(self::WINDOW_PAST_HOURS), $now->addDays($days));
        $rawFixtures = $cached['fixtures'];
        // Only leagues playing in the window need their full team lists
        $playingLeagueIds = array_values(array_unique(array_column($rawFixtures, 'league_id')));
        $teamsByLeague = $this->sports->teamsByLeague($playingLeagueIds, fetchMissing: false);
        if (! $cached['complete'] || count($teamsByLeague) < count($playingLeagueIds)) {
            RefreshSportsAedProfile::dispatch($profile->id);
        }
        foreach ($teamsByLeague as $leagueId => $teams) {
            foreach ($teams as $team) {
                $addNames($team, $leagueId.':'.$team['id']);
            }
        }
        foreach ($rawFixtures as $fixture) {
            if (! isset($fixture['home']['team'], $fixture['away']['team'])) {
                continue;
            }
            // Sides not decided yet: every such game would match a "TBD vs TBD" title
            $sides = [strtoupper($fixture['home']['team']['display_name']), strtoupper($fixture['away']['team']['display_name'])];
            if (array_intersect($sides, self::PLACEHOLDER_TEAMS) !== []) {
                continue;
            }
            $isCollege = EspnSportsService::isCollege($fixture['league_id']);
            $home = SportsTeamMatcher::phrasesFor($fixture['home']['team'], $isCollege);
            $away = SportsTeamMatcher::phrasesFor($fixture['away']['team'], $isCollege);
            if ($home === [] || $away === []) {
                continue;
            }
            $position = count($fixtures);
            $homeKey = $fixture['league_id'].':'.$fixture['home']['team']['id'];
            $awayKey = $fixture['league_id'].':'.$fixture['away']['team']['id'];
            $addNames($fixture['home']['team'], $homeKey);
            $addNames($fixture['away']['team'], $awayKey);
            $league = $this->sports->findLeague($fixture['league_id']);
            // Only what matching and [event] read: thousands of games are
            // held per EPG request, so crests, colours and scores are left out.
            $fixtures[] = [
                'league_id' => $fixture['league_id'],
                'sport' => SportsService::sportOf($fixture['league_id']),
                'name' => $fixture['name'],
                'venue' => $fixture['venue'],
                'broadcasts' => $fixture['broadcasts'],
                'league_logo' => $fixture['league_logo'] ?? null,
                'home_name' => $fixture['home']['team']['display_name'],
                'away_name' => $fixture['away']['team']['display_name'],
                'women' => (bool) ($league['women'] ?? false),
                'youth' => (bool) ($league['youth'] ?? false),
                'home_phrases' => $home,
                'away_phrases' => $away,
                'home_key' => $homeKey,
                'away_key' => $awayKey,
                'start_ts' => CarbonImmutable::parse($fixture['start'])->getTimestamp(),
            ];
            foreach ($home as $phrase) {
                $index[explode(' ', $phrase)[0]][] = $position;
            }
        }

        return $this->prepared[$key] = ['fixtures' => $fixtures, 'index' => $index, 'names' => $names];
    }
}
