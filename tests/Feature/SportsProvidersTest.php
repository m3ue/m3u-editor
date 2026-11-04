<?php

use App\Filament\Clusters\Settings\Pages\ManageIntegrationSettings;
use App\Jobs\RefreshSportsAedProfile;
use App\Models\AedProfile;
use App\Models\Playlist;
use App\Models\User;
use App\Services\AedExtractorService;
use App\Services\EspnSportsService;
use App\Services\Sports\EspnLeagueCatalog;
use App\Services\Sports\SportsAedRefresher;
use App\Services\Sports\SportsAedResolver;
use App\Services\Sports\SportsChannelMatcher;
use App\Services\Sports\SportsService;
use App\Services\Sports\SportsTeamMatcher;
use App\Services\Sports\TheSportsDbLeagueCatalog;
use App\Services\TheSportsDbService;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

const TSDB_TEST_KEY = 'test-paid-key';

/**
 * One TheSportsDB event: Vancouver Canucks vs Anaheim Ducks in the KHL's slot.
 *
 * @return array<string, mixed>
 */
function tsdbEvent(string $id, string $timestamp, string $leagueId = '4920', ?string $status = 'NS'): array
{
    return [
        'idEvent' => $id,
        'idLeague' => $leagueId,
        'strEvent' => 'Vancouver Canucks vs Anaheim Ducks',
        'strTimestamp' => $timestamp,
        'strStatus' => $status,
        'strPostponed' => 'no',
        'strVenue' => 'Rogers Arena',
        'strHomeTeam' => 'Vancouver Canucks',
        'strAwayTeam' => 'Anaheim Ducks',
        'idHomeTeam' => '10',
        'idAwayTeam' => '20',
        'intHomeScore' => null,
        'intAwayScore' => null,
        'strHomeTeamBadge' => 'https://r2.thesportsdb.com/van.png',
        'strAwayTeamBadge' => 'https://r2.thesportsdb.com/ana.png',
        'strLeagueBadge' => 'https://r2.thesportsdb.com/khl.png',
    ];
}

function enableProviders(bool $espn, bool $theSportsDb, ?string $key = TSDB_TEST_KEY): void
{
    $settings = app(GeneralSettings::class);
    $settings->sports_espn_enabled = $espn;
    $settings->sports_tsdb_enabled = $theSportsDb;
    $settings->sports_tsdb_api_key = $key;
    $settings->app_output_enabled = true;
    $settings->save();
}

/**
 * Answers schedule requests with [$events] for the day they fall on.
 *
 * @param  array<string, list<array<string, mixed>>>  $eventsByDay
 */
function fakeTheSportsDb(array $eventsByDay = [], array $live = [], array $teams = []): void
{
    Http::fake([
        'www.thesportsdb.com/api/v1/json/*/eventsday.php*' => fn (HttpRequest $request) => Http::response([
            'events' => $eventsByDay[$request->data()['d'] ?? ''] ?? null,
        ]),
        'www.thesportsdb.com/api/v1/json/*/search_all_teams.php*' => Http::response(['teams' => $teams]),
        'www.thesportsdb.com/api/v2/json/livescore/all' => Http::response(['livescore' => $live]),
    ]);
}

function theSportsDbDayRequests(): int
{
    return Http::recorded(fn (HttpRequest $request): bool => str_contains($request->url(), 'eventsday.php'))->count();
}

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Queue::fake();
    CarbonImmutable::setTestNow('2026-11-03 18:00:00');
    $this->travelTo(now()->parse('2026-11-03 18:00:00', 'UTC'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

// The first test of a process that saves settings can't run locally, so parsers lead
it('maps events to fixtures, dropping unknown leagues and events without teams', function () {
    $race = [...tsdbEvent('2', '2026-11-03T19:00:00'), 'strHomeTeam' => null, 'strAwayTeam' => null, 'idHomeTeam' => null, 'idAwayTeam' => null];

    $parsed = TheSportsDbService::parseEvents(['events' => [
        tsdbEvent('1', '2026-11-03T19:00:00'),
        $race,
        tsdbEvent('3', '2026-11-03T19:00:00', leagueId: '999999'),
        'junk',
    ]]);

    expect(array_keys($parsed))->toBe(['hockey/tsdb.4920'])
        ->and($parsed['hockey/tsdb.4920'])->toHaveCount(1);
    $fixture = $parsed['hockey/tsdb.4920'][0];
    expect($fixture['start'])->toBe('2026-11-03T19:00:00Z')
        ->and($fixture['state'])->toBe('scheduled')
        ->and($fixture['home']['team']['display_name'])->toBe('Vancouver Canucks')
        ->and($fixture['league_logo'])->toBe('https://r2.thesportsdb.com/khl.png')
        ->and(TheSportsDbService::parseEvents('nope'))->toBe([])
        ->and(TheSportsDbService::parseEvents(['events' => null]))->toBe([]);
});

it('reads an instant with no offset as UTC whatever the server time zone', function () {
    $previous = date_default_timezone_get();
    date_default_timezone_set('America/Denver');
    try {
        $parsed = TheSportsDbService::parseEvents(['events' => [tsdbEvent('1', '2026-11-03T19:00:00')]]);
    } finally {
        date_default_timezone_set($previous);
    }

    expect($parsed['hockey/tsdb.4920'][0]['start'])->toBe('2026-11-03T19:00:00Z');
});

it('reads game states from the status and the kickoff', function (string $timestamp, ?string $status, string $state, string $detail) {
    $fixture = TheSportsDbService::parseEvents(['events' => [tsdbEvent('1', $timestamp, status: $status)]])['hockey/tsdb.4920'][0];

    expect($fixture['state'])->toBe($state)->and($fixture['status_detail'])->toBe($detail);
})->with([
    'not started' => ['2026-11-03T20:00:00', 'NS', 'scheduled', ''],
    'no status yet' => ['2026-11-03T20:00:00', '', 'scheduled', ''],
    'under way' => ['2026-11-03T17:30:00', '1H', 'live', '1H'],
    'finished' => ['2026-11-03T12:00:00', 'FT', 'finished', 'Final'],
    'after overtime' => ['2026-11-03T12:00:00', 'AOT', 'finished', 'Final'],
    'postponed' => ['2026-11-03T20:00:00', 'PST', 'postponed', 'Postponed'],
    'status left behind' => ['2026-11-02T12:00:00', '2H', 'finished', 'Final'],
    'never played' => ['2026-11-02T12:00:00', 'NS', 'scheduled', ''],
]);

it('marks the winner of a finished game only', function () {
    $finished = [...tsdbEvent('1', '2026-11-03T12:00:00', status: 'FT'), 'intHomeScore' => '5', 'intAwayScore' => '6'];
    $live = [...tsdbEvent('2', '2026-11-03T17:30:00', status: '1H'), 'intHomeScore' => '1', 'intAwayScore' => '0'];

    [$first, $second] = TheSportsDbService::parseEvents(['events' => [$finished, $live]])['hockey/tsdb.4920'];

    expect($first['away']['winner'])->toBeTrue()
        ->and($first['home']['winner'])->toBeFalse()
        ->and($second['home']['winner'])->toBeFalse();
});

it('keeps a team\'s other names but not its initialisms', function () {
    [$team] = TheSportsDbService::parseTeams(['teams' => [[
        'idTeam' => '1',
        'strTeam' => 'Brentford',
        'strTeamAlternate' => 'BFC, Brentford Football Club, Brentford FC, The Bees',
        'strTeamShort' => 'BRE',
        'strBadge' => 'https://r2.thesportsdb.com/bre.png',
        'strColour1' => '#FF0000',
    ]]]);

    expect($team['abbreviation'])->toBe('BRE')
        ->and($team['nickname'])->toBe('Brentford Football Club')
        ->and($team['aliases'])->toBe(['Brentford FC', 'The Bees'])
        ->and($team['color'])->toBe('ff0000')
        ->and(SportsTeamMatcher::phrasesFor($team))->toContain('the bees', 'brentford')->not->toContain('bfc', 'bre');
});

it('keeps both league catalogues consistent', function () {
    $espnIds = array_column(EspnSportsService::leagues(), 'id');
    $tsdbIds = array_column(TheSportsDbService::leagues(), 'id');
    $repeated = array_column(TheSportsDbLeagueCatalog::LEAGUES, 'espn');

    expect($tsdbIds)->toEqual(array_values(array_unique($tsdbIds)))
        ->and(array_diff($repeated, $espnIds))->toBe([])
        ->and($repeated)->toEqual(array_values(array_unique($repeated)));

    // Follows and clients read a league id as "{sport}/{slug}"
    foreach ([...$espnIds, ...$tsdbIds] as $id) {
        expect($id)->toMatch('/^[a-z0-9-]+\/[a-z0-9._-]+$/');
    }

    // TheSportsDB alone must start with the same leagues ESPN would
    $defaults = array_filter(EspnLeagueCatalog::LEAGUES, fn (array $league): bool => $league['default'] ?? false);
    foreach ($defaults as $league) {
        expect(TheSportsDbService::leagueIdForEspn($league['sport'].'/'.$league['slug']))->not->toBeNull();
    }
});

it('offers ESPN cricket, reading home side first', function () {
    $league = collect(EspnSportsService::leagues())->firstWhere('id', 'cricket/8048');

    expect($league['name'])->toBe('Indian Premier League')
        ->and($league['sport_name'])->toBe('Cricket')
        ->and($league['soccer_style'])->toBeTrue();
});

it('treats the free key as no key', function () {
    expect(TheSportsDbService::isUsableKey('123'))->toBeFalse()
        ->and(TheSportsDbService::isUsableKey(' 123 '))->toBeFalse()
        ->and(TheSportsDbService::isUsableKey(''))->toBeFalse()
        ->and(TheSportsDbService::isUsableKey(null))->toBeFalse()
        ->and(TheSportsDbService::isUsableKey(TSDB_TEST_KEY))->toBeTrue();

    enableProviders(espn: false, theSportsDb: true, key: '123');

    expect(app(TheSportsDbService::class)->isEnabled())->toBeFalse()
        ->and(app(SportsService::class)->isEnabled())->toBeFalse();
});

it('serves a league from one provider at a time, ESPN first', function () {
    enableProviders(espn: true, theSportsDb: true);
    $both = array_column(app(SportsService::class)->leagues(), 'id');
    expect($both)->toContain('hockey/nhl', 'hockey/tsdb.4920')->not->toContain('hockey/tsdb.4380');

    enableProviders(espn: false, theSportsDb: true);
    $alone = array_column(app(SportsService::class)->leagues(), 'id');
    expect($alone)->toContain('hockey/tsdb.4380', 'hockey/tsdb.4920')->not->toContain('hockey/nhl');

    enableProviders(espn: true, theSportsDb: false);
    $espn = array_column(app(SportsService::class)->leagues(), 'id');
    expect($espn)->toContain('hockey/nhl')->not->toContain('hockey/tsdb.4920');

    enableProviders(espn: false, theSportsDb: false);
    expect(app(SportsService::class)->leagues())->toBe([])
        ->and(app(SportsService::class)->findLeague('hockey/nhl'))->toBeNull();
});

it('keeps a saved league working when its provider is turned off', function () {
    enableProviders(espn: false, theSportsDb: true);
    fakeTheSportsDb(['2026-11-03' => [tsdbEvent('1', '2026-11-03T20:00:00', leagueId: '4380')]]);

    $fixtures = app(SportsService::class)->fixtures(['hockey/nhl'], now(), now()->addDay());

    expect($fixtures)->toHaveCount(1)
        ->and($fixtures[0]['league_id'])->toBe('hockey/tsdb.4380');

    enableProviders(espn: true, theSportsDb: false);
    expect(app(SportsService::class)->findLeague('hockey/tsdb.4380')['slug'])->toBe('nhl');
});

it('merges fixtures from both providers, soonest first', function () {
    enableProviders(espn: true, theSportsDb: true);
    Http::fake([
        'site.api.espn.com/*' => Http::response(['events' => [[
            'id' => '401',
            'date' => '2026-11-03T22:00:00Z',
            'name' => 'Utah Mammoth at Toronto Maple Leafs',
            'status' => ['type' => ['state' => 'pre']],
            'competitions' => [['competitors' => []]],
        ]]]),
        'www.thesportsdb.com/api/v1/json/*/eventsday.php*' => fn (HttpRequest $request) => Http::response([
            'events' => ($request->data()['d'] ?? '') === '2026-11-03' ? [tsdbEvent('1', '2026-11-03T20:00:00')] : null,
        ]),
    ]);

    $fixtures = app(SportsService::class)->fixtures(['hockey/nhl', 'hockey/tsdb.4920'], now(), now()->addDay());

    expect(array_column($fixtures, 'league_id'))->toBe(['hockey/tsdb.4920', 'hockey/nhl']);
});

it('fetches one request per day however many leagues are asked for, then reads the cache', function () {
    enableProviders(espn: false, theSportsDb: true);
    fakeTheSportsDb(['2026-11-03' => [
        tsdbEvent('1', '2026-11-03T20:00:00'),
        tsdbEvent('2', '2026-11-03T20:00:00', leagueId: '4380'),
    ]]);
    $provider = app(TheSportsDbService::class);

    $fixtures = $provider->rawFixtures(['hockey/tsdb.4920', 'hockey/tsdb.4380', 'hockey/tsdb.4919'], now(), now()->addDay(), liveScores: false);
    $requests = theSportsDbDayRequests();

    expect($fixtures)->toHaveCount(2)
        // The day either side, and the day the span ends on
        ->and($requests)->toBe(4);

    expect($provider->rawFixtures(['hockey/tsdb.4920'], now(), now()->addDay(), liveScores: false))->toHaveCount(1)
        ->and(theSportsDbDayRequests())->toBe($requests);
});

it('asks by sport for a day cut short at the event limit', function () {
    enableProviders(espn: false, theSportsDb: true);
    $full = array_map(fn (int $id): array => tsdbEvent("full-{$id}", '2026-11-03T20:00:00'), range(1, 1500));
    Http::fake([
        'www.thesportsdb.com/api/v1/json/*/eventsday.php*' => function (HttpRequest $request) use ($full) {
            $query = $request->data();

            return Http::response(['events' => match (true) {
                ($query['d'] ?? '') !== '2026-11-03' => null,
                ($query['s'] ?? null) === 'Volleyball' => [tsdbEvent('cut-off', '2026-11-03T20:30:00', leagueId: '4545')],
                ($query['s'] ?? null) === 'Ice Hockey' => [$full[0], tsdbEvent('also-cut-off', '2026-11-03T21:00:00')],
                isset($query['s']) => null,
                default => $full,
            }]);
        },
    ]);
    $provider = app(TheSportsDbService::class);

    $fixtures = $provider->rawFixtures(['hockey/tsdb.4920', 'volleyball/tsdb.4545'], now(), now()->addDay(), liveScores: false);
    $ids = array_column($fixtures, 'id');

    expect($fixtures)->toHaveCount(1502)
        ->and($ids)->toContain('cut-off', 'also-cut-off', 'full-1')
        // Only the day that reached the limit asks again: three other days, and 14 sports
        ->and(theSportsDbDayRequests())->toBe(4 + 14);
});

it('serves EPG requests from the cache alone', function () {
    enableProviders(espn: false, theSportsDb: true);
    fakeTheSportsDb(['2026-11-03' => [tsdbEvent('1', '2026-11-03T20:00:00')]]);
    $provider = app(TheSportsDbService::class);

    $cold = $provider->cachedFixtures(['hockey/tsdb.4920'], now(), now()->addDay());
    expect($cold)->toBe(['fixtures' => [], 'complete' => false])
        ->and(theSportsDbDayRequests())->toBe(0);

    $provider->rawFixtures(['hockey/tsdb.4920'], now(), now()->addDay(), liveScores: false);
    $warm = $provider->cachedFixtures(['hockey/tsdb.4920'], now(), now()->addDay());

    expect($warm['complete'])->toBeTrue()->and($warm['fixtures'])->toHaveCount(1);
});

it('applies the livescore feed for clients, not for matching', function () {
    enableProviders(espn: false, theSportsDb: true);
    fakeTheSportsDb(
        ['2026-11-03' => [tsdbEvent('1', '2026-11-03T17:30:00')]],
        live: [['idEvent' => '1', 'strStatus' => '2H', 'strProgress' => '63', 'intHomeScore' => '2', 'intAwayScore' => '1']],
    );
    $provider = app(TheSportsDbService::class);

    $matching = $provider->rawFixtures(['hockey/tsdb.4920'], now()->subDay(), now()->addDay(), liveScores: false);
    expect($matching[0]['state'])->toBe('scheduled')
        ->and(Http::recorded(fn (HttpRequest $request): bool => str_contains($request->url(), 'livescore'))->count())->toBe(0);

    $fixture = $provider->fixtures(['hockey/tsdb.4920'], now()->subDay(), now()->addDay())[0];
    expect($fixture['state'])->toBe('live')
        ->and($fixture['status_detail'])->toBe("63'")
        ->and($fixture['home']['score'])->toBe('2')
        ->and($fixture['away']['score'])->toBe('1')
        ->and($fixture['home']['team']['logo'])->toContain('logo-proxy');

    $provider->fixtures(['hockey/tsdb.4920'], now()->subDay(), now()->addDay());
    expect(Http::recorded(fn (HttpRequest $request): bool => str_contains($request->url(), 'livescore'))->count())->toBe(1);
});

it('sends the key by header to the v2 API, off the log and out of client data', function () {
    enableProviders(espn: false, theSportsDb: true);
    fakeTheSportsDb(['2026-11-03' => [tsdbEvent('1', '2026-11-03T17:30:00')]]);

    $fixtures = app(TheSportsDbService::class)->fixtures(['hockey/tsdb.4920'], now()->subDay(), now()->addDay());

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'livescore/all')
        && $request->hasHeader('X-API-KEY', TSDB_TEST_KEY));
    expect(json_encode($fixtures))->not->toContain(TSDB_TEST_KEY);
});

it('pauses after the key is refused and keeps it out of the log', function () {
    enableProviders(espn: false, theSportsDb: true);
    Http::fake(['www.thesportsdb.com/*' => Http::response(['Message' => 'Invalid Premium API key'], 400)]);
    Log::spy();
    $provider = app(TheSportsDbService::class);

    $provider->rawFixtures(['hockey/tsdb.4920'], now(), now()->addDay(), liveScores: false);
    $requests = Http::recorded()->count();
    Cache::forget('sports:tsdb:v1:day:20261103');

    $provider->rawFixtures(['hockey/tsdb.4920'], now(), now()->addDay(), liveScores: false);

    expect(Http::recorded()->count())->toBe($requests);
    Log::shouldHaveReceived('warning')->with('TheSportsDB requests paused', Mockery::any());
});

it('takes the key out of connection errors before logging them', function () {
    enableProviders(espn: false, theSportsDb: true);
    Http::fake(['www.thesportsdb.com/*' => fn () => throw new ConnectionException('cURL error 28 for https://www.thesportsdb.com/api/v1/json/'.TSDB_TEST_KEY.'/eventsday.php')]);
    $logged = [];
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) use (&$logged): void {
        $logged[] = $message.json_encode($context);
    });

    app(TheSportsDbService::class)->rawFixtures(['hockey/tsdb.4920'], now(), now()->addDay(), liveScores: false);

    expect($logged)->not->toBeEmpty()
        ->and(implode(' ', $logged))->not->toContain(TSDB_TEST_KEY)->toContain('***');
});

it('stops at the minute\'s request budget and leaves room for schedules', function () {
    enableProviders(espn: false, theSportsDb: true);
    fakeTheSportsDb(['2026-11-03' => [tsdbEvent('1', '2026-11-03T20:00:00')]]);
    $leagueIds = array_slice(array_column(TheSportsDbService::leagues(), 'id'), 0, 120);
    $provider = app(TheSportsDbService::class);

    $first = $provider->teamsByLeague($leagueIds);
    expect(count($first))->toBeLessThanOrEqual(35)->toBeGreaterThan(0)
        ->and(Http::recorded()->count())->toBe(count($first));

    // Team lists have spent their share, but a client's schedule still loads
    expect($provider->rawFixtures(['hockey/tsdb.4920'], now(), now()->addDay(), liveScores: false))->toHaveCount(1);

    $this->travel(1)->minutes();
    expect(count($provider->teamsByLeague($leagueIds)))->toBeGreaterThan(count($first));
});

it('paces a first run\'s team lists in the background job until they stop coming', function () {
    Sleep::fake();
    enableProviders(espn: false, theSportsDb: true);
    $events = array_map(
        fn (array $league, int $index): array => tsdbEvent((string) $index, '2026-11-03T20:00:00', leagueId: $league['tsdb_id']),
        array_slice(TheSportsDbLeagueCatalog::LEAGUES, 0, 50),
        range(1, 50),
    );
    fakeTheSportsDb(['2026-11-03' => $events]);
    Sleep::whenFakingSleep(fn () => $this->travel(1)->minutes());
    $user = User::factory()->create();
    $profile = AedProfile::factory()->for($user)->sportsMatching(array_column(array_slice(TheSportsDbService::leagues(), 0, 50), 'id'))->create();

    (new RefreshSportsAedProfile($profile->id))->handle(app(SportsService::class), app(SportsAedRefresher::class));

    Sleep::assertSleptTimes(1);
    expect(app(TheSportsDbService::class)->teamsByLeague(array_column(array_slice(TheSportsDbService::leagues(), 0, 50), 'id'), fetchMissing: false))->toHaveCount(50);
});

it('matches a channel title by a team\'s nickname from the team list', function () {
    enableProviders(espn: false, theSportsDb: true);
    fakeTheSportsDb(
        ['2026-11-03' => [tsdbEvent('1', '2026-11-03T20:00:00')]],
        teams: [
            ['idTeam' => '10', 'strTeam' => 'Vancouver Canucks', 'strTeamAlternate' => 'Canucks', 'strBadge' => null],
            ['idTeam' => '20', 'strTeam' => 'Anaheim Ducks', 'strTeamAlternate' => 'Ducks', 'strBadge' => null],
        ],
    );
    $user = User::factory()->create();
    $this->actingAs($user);
    Playlist::factory()->for($user)->create();
    $profile = AedProfile::factory()->for($user)->sportsMatching(['hockey/tsdb.4920'])->create([
        'no_event_format' => null,
        'title_regex' => '^NO REGEX MATCH$',
    ]);

    expect(app(SportsAedRefresher::class)->refresh($profile))->toBe(1);

    $event = app(AedExtractorService::class)->resolve($profile, 'DAZN 25: Ducks @ Canucks');

    expect($event)->not->toBeNull()
        ->and($event->title)->toBe('Anaheim Ducks at Vancouver Canucks')
        ->and($event->description)->toContain('Russian KHL', 'Rogers Arena');
});

it('does not match a game whose sides are not decided yet', function () {
    enableProviders(espn: false, theSportsDb: true);
    fakeTheSportsDb(['2026-11-03' => [[...tsdbEvent('1', '2026-11-03T20:00:00'), 'strHomeTeam' => 'TBD', 'strAwayTeam' => 'TBD']]]);
    $user = User::factory()->create();
    $this->actingAs($user);
    $profile = AedProfile::factory()->for($user)->sportsMatching(['hockey/tsdb.4920'])->create([
        'no_event_format' => null,
        'title_regex' => '^NO REGEX MATCH$',
    ]);
    app(SportsAedRefresher::class)->refresh($profile);

    expect(app(SportsAedResolver::class)->match($profile, 'hoops: TBD vs TBD'))->toBeNull();
});

it('does not let a league without team lists pause ESPN', function () {
    enableProviders(espn: true, theSportsDb: false);
    Http::fake([
        'site.api.espn.com/*/cricket/*/teams*' => Http::response(['code' => 404], 404),
        'site.api.espn.com/*' => Http::response(['events' => []]),
    ]);
    $service = app(SportsService::class);
    $cricket = ['cricket/8048', 'cricket/8044', 'cricket/21284', 'cricket/8050'];

    expect($service->teamsByLeague($cricket))->toHaveCount(4);
    $requests = Http::recorded()->count();

    // Remembered, rather than asked again every few minutes
    $this->travel(1)->hours();
    $service->teamsByLeague($cricket);
    expect(Http::recorded()->count())->toBe($requests);

    // And ESPN still answers
    $service->fixtures(['hockey/nhl'], now(), now()->addDay());
    expect(Http::recorded()->count())->toBeGreaterThan($requests);
});

it('never lists a league from both providers under the same name', function () {
    enableProviders(espn: true, theSportsDb: true);
    // The same name can be two competitions: Scottish Premiership is soccer and rugby
    $normalize = fn (array $league): string => $league['sport'].':'.trim(SportsTeamMatcher::normalize($league['name']));

    $espn = array_map($normalize, EspnSportsService::leagues());
    $added = array_filter(app(SportsService::class)->leagues(), fn (array $league): bool => TheSportsDbService::owns($league['id']));
    $repeats = array_filter($added, fn (array $league): bool => in_array($normalize($league), $espn, true));

    expect(array_column($repeats, 'name'))->toBe([])
        ->and(array_column(app(SportsService::class)->leagues(), 'id'))
        ->toContain('cricket/8048', 'cricket/tsdb.5532')->not->toContain('cricket/tsdb.4460');
});

it('only matches a game in the sport a title names', function (string $title, ?string $league) {
    enableProviders(espn: false, theSportsDb: true);
    // The same two teams in four sports, as schools have
    fakeTheSportsDb(['2026-11-03' => [
        [...tsdbEvent('1', '2026-11-03T20:00:00', leagueId: '4920'), 'strEvent' => 'hockey'],
        [...tsdbEvent('2', '2026-11-03T20:00:00', leagueId: '4328'), 'strEvent' => 'soccer'],
        [...tsdbEvent('3', '2026-11-03T20:00:00', leagueId: '4387'), 'strEvent' => 'basketball'],
        [...tsdbEvent('4', '2026-11-03T20:00:00', leagueId: '4558'), 'strEvent' => 'field hockey'],
    ]]);
    $user = User::factory()->create();
    $this->actingAs($user);
    $profile = AedProfile::factory()->for($user)->sportsMatching(['hockey/tsdb.4920', 'soccer/tsdb.4328', 'basketball/tsdb.4387', 'field-hockey/tsdb.4558'])->create([
        'no_event_format' => null,
        'title_regex' => '^NO REGEX MATCH$',
        'sports_title_format' => '{event}',
    ]);
    app(SportsAedRefresher::class)->refresh($profile);

    $event = app(SportsAedResolver::class)->match($profile, $title);

    expect($event?->title)->toBe($league);
})->with([
    'hockey' => ['(FLSP 460) | hockey:  Vancouver Canucks vs Anaheim Ducks', 'hockey'],
    'hoops' => ['(FLSP 216) | hoops:  Vancouver Canucks vs Anaheim Ducks', 'basketball'],
    'a league name' => ['NBA 03: Vancouver Canucks vs Anaheim Ducks', 'basketball'],
    'football as soccer' => ['Sky Sports Football: Vancouver Canucks v Anaheim Ducks', 'soccer'],
    'field hockey' => ['Vancouver Canucks vs Anaheim Ducks _ Field Hockey', 'field hockey'],
    'a sport with no game here' => ['(FLSP 801) | volleyball:  Vancouver Canucks vs Anaheim Ducks', null],
    'a sport no catalogue covers' => ['tennis: Vancouver Canucks vs Anaheim Ducks', null],
]);

it('still matches a title that names no sport', function () {
    enableProviders(espn: false, theSportsDb: true);
    fakeTheSportsDb(['2026-11-03' => [tsdbEvent('1', '2026-11-03T20:00:00')]]);
    $user = User::factory()->create();
    $this->actingAs($user);
    $profile = AedProfile::factory()->for($user)->sportsMatching(['hockey/tsdb.4920'])->create(['no_event_format' => null, 'title_regex' => '^NO REGEX MATCH$']);
    app(SportsAedRefresher::class)->refresh($profile);

    expect(app(SportsAedResolver::class)->match($profile, 'DAZN 25: Vancouver Canucks vs Anaheim Ducks'))->not->toBeNull();
});

it('asks ESPN for all of Division I college football, not just FBS', function () {
    enableProviders(espn: true, theSportsDb: false);
    Http::fake(['site.api.espn.com/*' => Http::response(['events' => []])]);

    app(SportsService::class)->fixtures(['football/college-football'], now(), now()->addDay());

    Http::assertSent(fn (HttpRequest $request): bool => ($request->data()['groups'] ?? null) === '90');
    Http::assertNotSent(fn (HttpRequest $request): bool => ($request->data()['groups'] ?? null) === '80');
});

it('advertises sports and every league on login with only TheSportsDB on', function () {
    enableProviders(espn: false, theSportsDb: true);
    $user = User::factory()->create(['name' => 'sports-owner']);
    $playlist = Playlist::factory()->for($user)->create();

    $sports = $this->postJson(route('xtream.api.player'), [
        'username' => $user->name,
        'password' => $playlist->uuid,
    ])->assertOk()->json('m3u_editor.sports');

    $ids = array_column($sports['leagues'], 'id');
    expect($ids)->toContain('hockey/tsdb.4380', 'cricket/tsdb.4460')
        ->and($sports['sport_names'])->toHaveKey('cricket');
});

it('saves the TheSportsDB settings and tests the typed key', function () {
    $this->actingAs(User::factory()->admin()->create());
    Http::fake(['www.thesportsdb.com/api/v2/json/list/teams/*' => Http::response(['list' => [['idTeam' => '1'], ['idTeam' => '2']]])]);

    Livewire::test(ManageIntegrationSettings::class)
        ->fillForm(['sports_tsdb_enabled' => true, 'sports_tsdb_api_key' => TSDB_TEST_KEY])
        ->callAction(TestAction::make('test_tsdb_connection')->schemaComponent('sports_tsdb'))
        ->assertNotified(__('Connection Successful'))
        ->call('save')
        ->assertHasNoFormErrors();

    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('X-API-KEY', TSDB_TEST_KEY));
    $settings = app(GeneralSettings::class);
    $settings->refresh();
    expect($settings->sports_tsdb_enabled)->toBeTrue()
        ->and($settings->sports_tsdb_api_key)->toBe(TSDB_TEST_KEY);
});

it('rejects the free key and a missing key in settings', function (?string $key) {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageIntegrationSettings::class)
        ->fillForm(['sports_tsdb_enabled' => true, 'sports_tsdb_api_key' => $key])
        ->call('save')
        ->assertHasFormErrors(['sports_tsdb_api_key']);
})->with(['free key' => '123', 'no key' => null]);

it('points to AED profiles only while a sports provider is on', function () {
    $this->actingAs(User::factory()->admin()->create());
    $pointer = __('Generate EPG data for your channels');

    enableProviders(espn: false, theSportsDb: false);
    $page = Livewire::test(ManageIntegrationSettings::class)->assertDontSee($pointer);

    $page->fillForm(['sports_espn_enabled' => true])->assertSee($pointer);
    $page->fillForm(['sports_espn_enabled' => false])->assertDontSee($pointer);
    $page->fillForm(['sports_tsdb_enabled' => true])->assertSee($pointer)->assertSee(__('Open AED Profiles'));
});

it('tells the free key apart when testing a connection', function () {
    Http::fake();

    expect(fn () => app(TheSportsDbService::class)->testConnection('123'))
        ->toThrow(RuntimeException::class, 'paid');
    Http::assertNothingSent();
});

it('reports a refused key when testing a connection', function () {
    Http::fake(['www.thesportsdb.com/*' => Http::response(['Message' => 'Invalid Premium API key'], 400)]);

    expect(fn () => app(TheSportsDbService::class)->testConnection('not-a-key'))
        ->toThrow(RuntimeException::class, 'refused');
});

it('accepts follows in a league whose slug has an underscore', function () {
    $key = 'soccer/eng.league_cup:359';

    enableProviders(espn: true, theSportsDb: false);
    $user = User::factory()->create(['name' => 'follows-owner']);
    $playlist = Playlist::factory()->for($user)->create();
    Http::fake(['site.api.espn.com/*' => Http::response(['sports' => [['leagues' => [['teams' => []]]]]])]);

    $this->postJson(route('xtream.api.player'), [
        'username' => $user->name,
        'password' => $playlist->uuid,
        'action' => 'toggle_sports_follow',
        'team_key' => $key,
        'name' => 'Arsenal',
        'followed' => true,
    ])->assertOk()->assertJsonPath('follows.0.team_key', $key);
});

it('keeps a saved league selectable after its provider is turned off', function () {
    enableProviders(espn: false, theSportsDb: true);

    $options = app(SportsService::class)->leagueOptions(['hockey/nhl']);

    expect($options['Hockey'])->toHaveKey('hockey/nhl')->toHaveKey('hockey/tsdb.4380');

    enableProviders(espn: false, theSportsDb: false);
    expect(app(SportsService::class)->leagueOptions()['Hockey'])->toHaveKey('hockey/nhl');
});

function limitSportsLeagues(array $leagueIds): void
{
    $settings = app(GeneralSettings::class);
    $settings->sports_league_ids = $leagueIds;
    $settings->save();
}

it('offers every league until Sports is limited to some', function () {
    enableProviders(espn: true, theSportsDb: false);
    $all = app(SportsService::class)->leagues();
    expect(count($all))->toBeGreaterThan(100);

    limitSportsLeagues([]);
    expect(app(SportsService::class)->leagues())->toBe($all);
});

it('offers only the limited leagues, and clients default to all of them', function () {
    enableProviders(espn: true, theSportsDb: false);
    limitSportsLeagues(['football/nfl', 'soccer/ger.1', 'not/a-league']);

    $sports = app(SportsService::class);
    $leagues = collect($sports->clientLeagues());

    expect($leagues->pluck('id')->all())->toBe(['football/nfl', 'soccer/ger.1'])
        // The Bundesliga isn't a catalogue default
        ->and($leagues->pluck('default')->all())->toBe([true, true])
        ->and(array_keys($sports->sportNames()))->toBe(['football', 'soccer']);
});

it('fetches nothing outside the limit, whoever asks', function () {
    enableProviders(espn: true, theSportsDb: false);
    limitSportsLeagues(['football/nfl']);
    Http::fake(['site.api.espn.com/*' => Http::response(['events' => []])]);

    app(SportsService::class)->rawFixtures(['football/nfl', 'hockey/nhl'], now(), now()->addDay());

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/football/nfl/'));
    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/hockey/nhl/'));

    expect(app(SportsService::class)->teamsByLeague(['hockey/nhl']))->toBe([]);
});

it('keeps a limited league when the other provider takes it over', function () {
    enableProviders(espn: false, theSportsDb: true);
    limitSportsLeagues(['hockey/nhl']);

    expect(array_column(app(SportsService::class)->leagues(), 'id'))->toBe(['hockey/tsdb.4380']);

    enableProviders(espn: true, theSportsDb: true);

    expect(array_column(app(SportsService::class)->leagues(), 'id'))->toBe(['hockey/nhl']);
});

it('still knows a league outside the limit, so its follows can be removed', function () {
    enableProviders(espn: true, theSportsDb: false);
    limitSportsLeagues(['football/nfl']);

    expect(app(SportsService::class)->findLeague('hockey/nhl'))->not->toBeNull();
});

it('limits the AED league picker and an AED profile with no leagues picked', function () {
    enableProviders(espn: true, theSportsDb: false);
    limitSportsLeagues(['football/nfl']);
    $sports = app(SportsService::class);

    expect($sports->leagueOptions())->toBe(['Football' => ['football/nfl' => 'NFL']]);

    // A saved pick stays listed, or saving the form would fail validation
    expect($sports->leagueOptions(['hockey/nhl']))->toBe([
        'Football' => ['football/nfl' => 'NFL'],
        'Hockey' => ['hockey/nhl' => 'NHL'],
    ]);

    $profile = new AedProfile(['sports_matching' => true, 'sports_league_ids' => []]);
    $profile->id = 1;
    Http::fake(['site.api.espn.com/*' => Http::response(['events' => []])]);

    app(SportsAedRefresher::class)->refresh($profile);

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/football/nfl/'));
    Http::assertNotSent(fn (HttpRequest $request): bool => ! str_contains($request->url(), '/football/nfl/'));
});

it('lists every league to limit to, following the providers in the form', function () {
    enableProviders(espn: true, theSportsDb: false);
    limitSportsLeagues(['football/nfl']);
    $sports = app(SportsService::class);

    $espnOnly = $sports->leagueScopeOptions(true, false);
    expect($espnOnly['Hockey'])->toHaveKey('hockey/nhl')
        ->and($espnOnly['Hockey'])->not->toHaveKey('hockey/tsdb.4920');

    $both = $sports->leagueScopeOptions(true, true);
    expect($both['Hockey'])->toHaveKey('hockey/nhl')
        ->and($both['Hockey'])->toHaveKey('hockey/tsdb.4920')
        // One entry for a league both carry
        ->and($both['Hockey'])->not->toHaveKey('hockey/tsdb.4380');

    // A pick from a provider now off stays listed
    expect($sports->leagueScopeOptions(true, false, ['hockey/tsdb.4920'])['Hockey'])->toHaveKey('hockey/tsdb.4920');
});

it('saves the league limit from the settings page', function () {
    enableProviders(espn: true, theSportsDb: false);
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageIntegrationSettings::class)
        ->fillForm(['sports_league_ids' => ['football/nfl', 'hockey/nhl']])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(GeneralSettings::class);
    $settings->refresh();
    expect($settings->sports_league_ids)->toBe(['football/nfl', 'hockey/nhl']);
});

it('uses the limit when matching channels', function () {
    enableProviders(espn: true, theSportsDb: false);
    limitSportsLeagues(['football/nfl']);
    Http::fake(['site.api.espn.com/*' => Http::response(['events' => []])]);
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    expect(app(SportsChannelMatcher::class)->channelsFor($playlist, ['hockey/nhl'], now(), now()->addDay()))->toBe([]);

    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/hockey/nhl/'));
});
