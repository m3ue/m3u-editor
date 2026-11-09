<?php

use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\EspnSportsService;
use App\Services\TheSportsDbService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * An ESPN `/summary` answer for a game in its third period.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function espnSummary(array $extra = [], string $state = 'in', string $statusName = 'STATUS_IN_PROGRESS'): array
{
    $competitor = fn (string $side, string $id, string $name, string $score, array $lines, string $record): array => [
        'homeAway' => $side,
        'score' => $score,
        'record' => [['type' => 'total', 'summary' => $record]],
        'linescores' => array_map(fn (string $line): array => ['displayValue' => $line], $lines),
        'team' => ['id' => $id, 'displayName' => $name, 'name' => $name, 'logos' => [['href' => "https://a.espncdn.com/{$id}.png"]]],
    ];

    return [
        'header' => [
            'id' => '401',
            'competitions' => [[
                'date' => '2026-11-03T17:00Z',
                'status' => ['type' => ['name' => $statusName, 'state' => $state, 'shortDetail' => '3rd - 10:00']],
                'competitors' => [
                    $competitor('home', 'tor', 'Toronto Maple Leafs', '2', ['0', '1', '1', '0'], '8-7-2'),
                    $competitor('away', 'uta', 'Utah Mammoth', '3', ['1', '1', '1'], '10-6-0'),
                ],
            ]],
        ],
        'format' => ['regulation' => ['periods' => 3, 'slug' => 'period']],
        'gameInfo' => [
            'attendance' => 18889,
            'venue' => ['fullName' => 'Scotiabank Arena', 'address' => ['city' => 'Toronto', 'country' => 'Canada']],
            'officials' => [['displayName' => 'Garrett Rank']],
        ],
        'seasonseries' => [['summary' => ''], ['summary' => 'UTA wins series 2-0']],
        'boxscore' => ['teams' => [
            ['homeAway' => 'away', 'statistics' => [['name' => 'shots', 'label' => 'Shots', 'displayValue' => '36'], ['name' => 'hits', 'label' => 'Hits', 'displayValue' => '22']]],
            ['homeAway' => 'home', 'statistics' => [['name' => 'shots', 'label' => 'Shots', 'displayValue' => '29']]],
        ]],
        'leaders' => [
            ['team' => ['id' => 'tor'], 'leaders' => [['name' => 'goals', 'displayName' => 'Goals', 'leaders' => [
                ['displayValue' => '2', 'athlete' => ['displayName' => 'Jack Roslovic', 'headshot' => ['href' => 'https://a.espncdn.com/jack.png']]],
            ]]]],
        ],
        ...$extra,
    ];
}

function enableDetailsProviders(bool $espn = true, bool $theSportsDb = false): void
{
    $settings = app(GeneralSettings::class);
    $settings->sports_espn_enabled = $espn;
    $settings->sports_tsdb_enabled = $theSportsDb;
    $settings->sports_tsdb_api_key = $theSportsDb ? 'test-paid-key' : null;
    $settings->sports_league_ids = [];
    $settings->app_output_enabled = true;
    $settings->save();
}

/**
 * @param  array<string, mixed>  $params
 */
function fixtureDetailsRequest($test, array $params = [])
{
    return $test->getJson(route('xtream.api.player', [
        'action' => 'get_sports_fixture_details',
        'username' => 'sports_details',
        'password' => 'secret',
        'league' => 'hockey/nhl',
        'fixture' => '401',
        ...$params,
    ]));
}

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Queue::fake();
    $this->travelTo(now()->parse('2026-11-03 18:00:00', 'UTC'));

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->playlist->playlistAuths()->attach(PlaylistAuth::create([
        'name' => 'Sports Details',
        'username' => 'sports_details',
        'password' => 'secret',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]));
});

it('maps every section of an ESPN summary', function () {
    $details = EspnSportsService::parseFixtureDetails(espnSummary([
        'scoringPlays' => [[
            'text' => 'Dylan Guenther Goal (6)',
            'homeScore' => 0,
            'awayScore' => 1,
            'period' => ['number' => 1, 'displayValue' => '1st'],
            'clock' => ['displayValue' => '19:47'],
            'team' => ['id' => 'uta'],
        ]],
    ]), 'hockey/nhl');

    expect($details['fixture'])->toMatchArray(['id' => '401', 'league_id' => 'hockey/nhl', 'state' => 'live', 'status_detail' => '3rd - 10:00'])
        ->and($details['fixture']['away']['score'])->toBe('3')
        ->and($details['line_score'])->toBe([
            'periods' => ['1', '2', '3', 'OT'],
            'home' => ['0', '1', '1', '0'],
            'away' => ['1', '1', '1', '-'],
        ])
        ->and($details['events'])->toBe([[
            'kind' => 'score',
            'time' => '1st 19:47',
            'side' => 'away',
            'text' => 'Dylan Guenther Goal (6)',
            'home_score' => '0',
            'away_score' => '1',
        ]])
        // Only what both teams report
        ->and($details['stats'])->toBe([['label' => 'Shots', 'home' => '29', 'away' => '36']])
        ->and($details['leaders'])->toBe([[
            'label' => 'Goals',
            'home' => ['name' => 'Jack Roslovic', 'value' => '2', 'photo' => 'https://a.espncdn.com/jack.png'],
            'away' => null,
        ]])
        ->and($details['info'])->toBe([
            'venue' => 'Scotiabank Arena',
            'location' => 'Toronto, Canada',
            'attendance' => 18889,
            'officials' => ['Garrett Rank'],
            'series' => 'UTA wins series 2-0',
            'home_record' => '8-7-2',
            'away_record' => '10-6-0',
        ]);
});

it('keeps goals, cards and substitutions of soccer key events, not match markers', function () {
    $details = EspnSportsService::parseFixtureDetails(espnSummary([
        'keyEvents' => [
            ['type' => ['type' => 'kickoff'], 'scoringPlay' => false, 'clock' => ['displayValue' => '']],
            ['type' => ['type' => 'goal'], 'scoringPlay' => true, 'text' => 'Goal!', 'clock' => ['displayValue' => "12'"], 'team' => ['id' => 'tor']],
            ['type' => ['type' => 'yellow-card'], 'scoringPlay' => false, 'text' => 'Booked', 'clock' => ['displayValue' => "34'"], 'team' => ['id' => 'uta']],
            ['type' => ['type' => 'red-card'], 'scoringPlay' => false, 'text' => 'Sent off', 'clock' => ['displayValue' => "90'+5'"]],
            ['type' => ['type' => 'substitution'], 'scoringPlay' => false, 'text' => 'On for off', 'clock' => ['displayValue' => "53'"]],
            ['type' => ['type' => 'start-delay'], 'scoringPlay' => false, 'clock' => ['displayValue' => "28'"]],
        ],
    ]), 'soccer/eng.1');

    expect(array_column($details['events'], 'kind'))->toBe(['score', 'yellow_card', 'red_card', 'substitution'])
        ->and($details['events'][0])->toMatchArray(['time' => "12'", 'side' => 'home']);
});

it('takes scoring plays from a play feed, unless the sport scores too often', function () {
    $scoring = ['scoringPlay' => true, 'text' => 'Scored', 'period' => ['number' => 2]];
    $hockey = EspnSportsService::parseFixtureDetails(espnSummary(['plays' => [
        ['scoringPlay' => false, 'text' => 'Faceoff'],
        $scoring,
    ]]), 'hockey/nhl');
    $basketball = EspnSportsService::parseFixtureDetails(espnSummary([
        'plays' => array_fill(0, 61, $scoring),
    ]), 'basketball/nba');

    expect($hockey['events'])->toHaveCount(1)
        ->and($hockey['events'][0]['time'])->toBe('Q2')
        ->and($basketball['events'])->toBe([]);
});

it('reads only the headline numbers of a grouped box score', function () {
    $team = fn (string $side, string $hits): array => ['homeAway' => $side, 'statistics' => [
        ['name' => 'batting', 'stats' => [
            ['name' => 'hits', 'displayName' => 'Hits', 'displayValue' => $hits],
            ['name' => 'pinchAvg', 'displayName' => 'Pinch Average', 'displayValue' => '.000'],
        ]],
        ['name' => 'records', 'stats' => []],
    ]];
    $details = EspnSportsService::parseFixtureDetails(espnSummary([
        'boxscore' => ['teams' => [$team('home', '4'), $team('away', '8')]],
    ]), 'baseball/mlb');

    expect($details['stats'])->toBe([['label' => 'Hits', 'home' => '4', 'away' => '8']]);
});

it('ignores a summary with no game in it', function () {
    expect(EspnSportsService::parseFixtureDetails(['code' => 404, 'message' => 'Not found'], 'hockey/nhl'))->toBeNull()
        ->and(EspnSportsService::parseFixtureDetails('nope', 'hockey/nhl'))->toBeNull();
});

it('serves a game\'s details with images through the logo proxy, and reads them again once stale', function () {
    enableDetailsProviders();
    Http::fake(['*/hockey/nhl/summary*' => Http::response(espnSummary())]);

    $response = fixtureDetailsRequest($this)->assertOk();

    expect($response->json('details.fixture.state'))->toBe('live')
        ->and($response->json('details.fixture.home.team.logo'))->toContain('/logo-proxy/')
        ->and($response->json('details.leaders.0.home.photo'))->toContain('/logo-proxy/');
    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/hockey/nhl/summary') && $request['event'] === '401');

    fixtureDetailsRequest($this)->assertOk();
    Http::assertSentCount(1);

    $this->travel(31)->seconds();
    fixtureDetailsRequest($this)->assertOk();
    Http::assertSentCount(2);
});

it('keeps a finished game\'s details far longer than a live one\'s', function () {
    enableDetailsProviders();
    Http::fake(['*' => Http::response(espnSummary(state: 'post', statusName: 'STATUS_FINAL'))]);

    fixtureDetailsRequest($this)->assertOk();
    $this->travel(9)->minutes();
    fixtureDetailsRequest($this)->assertOk();

    Http::assertSentCount(1);
});

it('answers null for a game ESPN has nothing on, without pausing ESPN', function () {
    enableDetailsProviders();
    Http::fake(['*' => Http::response(['code' => 404], 404)]);

    fixtureDetailsRequest($this)->assertOk()->assertJsonPath('details', null);
    fixtureDetailsRequest($this)->assertOk()->assertJsonPath('details', null);

    // The failure is remembered for a minute, not refetched per request
    Http::assertSentCount(1);

    fixtureDetailsRequest($this, ['fixture' => '402'])->assertOk();
    Http::assertSentCount(2);
});

it('refuses bad parameters and unknown leagues', function () {
    enableDetailsProviders();
    Http::fake();

    fixtureDetailsRequest($this, ['fixture' => '../../secrets'])->assertStatus(422);
    fixtureDetailsRequest($this, ['league' => ''])->assertStatus(422);
    fixtureDetailsRequest($this, ['league' => 'hockey/nope'])->assertOk()->assertJsonPath('details', null);

    Http::assertNothingSent();
});

it('stays forbidden while Sports is off', function () {
    enableDetailsProviders(espn: false);

    fixtureDetailsRequest($this)->assertForbidden();
});

it('advertises the action in the auth payload', function () {
    enableDetailsProviders();

    $response = $this->getJson(route('xtream.api.player', ['username' => 'sports_details', 'password' => 'secret']));

    expect($response->json('m3u_editor.sports.actions.details'))->toBe('get_sports_fixture_details');
});

it('maps TheSportsDB event, stats and timeline answers', function () {
    $event = [
        'idEvent' => '77',
        'strEvent' => 'Vancouver Canucks vs Anaheim Ducks',
        'strTimestamp' => '2026-11-03 17:00:00',
        'strStatus' => 'FT',
        'strPostponed' => 'no',
        'strVenue' => 'Rogers Arena',
        'strCity' => 'Vancouver',
        'strCountry' => 'Canada',
        'intSpectators' => '18000',
        'strHomeTeam' => 'Vancouver Canucks',
        'strAwayTeam' => 'Anaheim Ducks',
        'idHomeTeam' => '10',
        'idAwayTeam' => '20',
        'intHomeScore' => '2',
        'intAwayScore' => '1',
    ];
    $league = TheSportsDbService::findLeague(TheSportsDbService::leagues()[0]['id']);

    $details = TheSportsDbService::parseFixtureDetails(
        ['events' => [$event]],
        ['eventstats' => [['strStat' => 'Shots', 'intHome' => '30', 'intAway' => '25'], ['strStat' => 'Empty', 'intHome' => null, 'intAway' => '1']]],
        ['timeline' => [
            ['strTimeline' => 'Goal', 'strTimelineDetail' => 'Normal Goal', 'strHome' => 'No', 'strPlayer' => 'Scorer', 'strAssist' => 'Helper', 'intTime' => '30'],
            ['strTimeline' => 'Card', 'strTimelineDetail' => 'Red Card', 'strHome' => 'Yes', 'strPlayer' => 'Offender', 'strAssist' => '', 'intTime' => '36'],
            ['strTimeline' => 'subst', 'strTimelineDetail' => 'Substitution 1', 'strHome' => 'Yes', 'strPlayer' => 'On', 'strAssist' => 'Off', 'intTime' => '60'],
            ['strTimeline' => 'Var', 'strPlayer' => 'Nobody', 'intTime' => '70'],
        ]],
        $league,
    );

    expect($details['fixture'])->toMatchArray(['id' => '77', 'state' => 'finished'])
        ->and($details['stats'])->toBe([['label' => 'Shots', 'home' => '30', 'away' => '25']])
        ->and(array_column($details['events'], 'kind'))->toBe(['score', 'red_card', 'substitution'])
        ->and($details['events'][0])->toMatchArray(['time' => "30'", 'side' => 'away', 'text' => 'Scorer - Normal Goal - Helper'])
        ->and($details['events'][2]['text'])->toBe('On / Off')
        ->and($details['info'])->toMatchArray(['venue' => 'Rogers Arena', 'location' => 'Vancouver, Canada', 'attendance' => 18000])
        ->and(TheSportsDbService::parseFixtureDetails(['events' => null], null, null, $league))->toBeNull();
});

it('still shows a TheSportsDB game when its stats and timeline fail', function () {
    enableDetailsProviders(espn: false, theSportsDb: true);
    $league = TheSportsDbService::leagues()[0];
    Http::fake([
        '*/lookupevent.php*' => Http::response(['events' => [[
            'idEvent' => '77',
            'strEvent' => 'A vs B',
            'strTimestamp' => '2026-11-01 17:00:00',
            'strStatus' => 'FT',
            'strHomeTeam' => 'A',
            'strAwayTeam' => 'B',
            'idHomeTeam' => '1',
            'idAwayTeam' => '2',
        ]]]),
        '*' => Http::response('', 500),
    ]);

    fixtureDetailsRequest($this, ['league' => $league['id'], 'fixture' => '77'])
        ->assertOk()
        ->assertJsonPath('details.fixture.id', '77')
        ->assertJsonPath('details.stats', [])
        ->assertJsonPath('details.events', []);
});
