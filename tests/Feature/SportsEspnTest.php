<?php

use App\Filament\Clusters\Settings\Pages\ManageIntegrationSettings;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\EspnSportsService;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * One ESPN scoreboard event: Utah Mammoth at Toronto Maple Leafs.
 *
 * @return array<string, mixed>
 */
function espnEvent(string $id, string $date, string $state = 'pre', string $statusName = 'STATUS_SCHEDULED'): array
{
    $team = fn (string $teamId, string $name, string $short): array => [
        'id' => $teamId,
        'displayName' => $name,
        'shortDisplayName' => $short,
        'name' => $short,
        'abbreviation' => strtoupper($teamId),
        'color' => '003E7E',
        'logo' => "https://a.espncdn.com/i/teamlogos/nhl/500/{$teamId}.png",
    ];

    return [
        'id' => $id,
        'date' => $date,
        'name' => 'Utah Mammoth at Toronto Maple Leafs',
        'status' => ['type' => ['name' => $statusName, 'state' => $state, 'shortDetail' => '1st - 15:00']],
        'competitions' => [[
            'competitors' => [
                ['homeAway' => 'home', 'score' => '2', 'winner' => false, 'team' => $team('tor', 'Toronto Maple Leafs', 'Maple Leafs')],
                ['homeAway' => 'away', 'score' => '1', 'team' => $team('uta', 'Utah Mammoth', 'Mammoth')],
            ],
            'broadcasts' => [['names' => ['ESPN+']], ['names' => ['Utah 16', 'ESPN+']]],
        ]],
    ];
}

function enableSports(bool $enabled = true, ?string $apiUrl = null): void
{
    $settings = app(GeneralSettings::class);
    $settings->sports_espn_enabled = $enabled;
    $settings->sports_espn_api_url = $apiUrl;
    $settings->app_output_enabled = true;
    $settings->save();
}

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    CarbonImmutable::setTestNow('2026-11-03 18:00:00');

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $auth = PlaylistAuth::create([
        'name' => 'Sports Auth',
        'username' => 'sports_user',
        'password' => 'sports_pass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($auth);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function sportsApi($test, string $action, array $params = [])
{
    return $test->getJson(route('xtream.api.player', [
        'action' => $action,
        'username' => 'sports_user',
        'password' => 'sports_pass',
        ...$params,
    ]));
}

it('skips undated events and tolerates junk scoreboards', function () {
    expect(EspnSportsService::parseScoreboard('nope', 'hockey/nhl'))->toBe([])
        ->and(EspnSportsService::parseScoreboard(['events' => [['id' => '1'], 'junk']], 'hockey/nhl'))->toBe([])
        ->and(EspnSportsService::parseScoreboard(['events' => [espnEvent('9', '2026-11-04T00:00Z')]], 'hockey/nhl'))
        ->toHaveCount(1);
});

it('does not advertise sports while it is disabled', function () {
    enableSports(false);

    $response = sportsApi($this, 'panel')->assertOk();
    expect($response->json('m3u_editor.features'))->not->toContain('sports')
        ->and($response->json('m3u_editor.sports'))->toBeNull();
});

it('advertises the sports feature and its leagues when enabled', function () {
    enableSports();

    $response = sportsApi($this, 'panel')->assertOk();
    expect($response->json('m3u_editor.features'))->toContain('sports')
        ->and($response->json('m3u_editor.sports.actions.fixtures'))->toBe('get_sports_fixtures')
        ->and(collect($response->json('m3u_editor.sports.leagues'))->firstWhere('id', 'hockey/nhl'))
        ->toMatchArray(['name' => 'NHL', 'soccer_style' => false, 'default' => true]);
});

it('refuses fixtures while sports is disabled', function () {
    enableSports(false);

    sportsApi($this, 'get_sports_fixtures', ['leagues' => 'hockey/nhl', 'from' => 0, 'to' => 1])
        ->assertForbidden();
    Http::assertNothingSent();
});

it('validates the fixtures parameters', function () {
    enableSports();

    sportsApi($this, 'get_sports_fixtures', ['leagues' => 'hockey/nhl', 'from' => 10, 'to' => 5])
        ->assertStatus(422)
        ->assertJsonPath('fields', ['to']);
});

it('serves mapped fixtures with proxied crests, fetched once per league day', function () {
    enableSports();
    Http::fake(fn (HttpRequest $request) => Http::response([
        'events' => $request['dates'] === '20261104'
            ? [espnEvent('401', '2026-11-04T00:00Z', 'in', 'STATUS_IN_PROGRESS')]
            : [],
    ]));

    $params = [
        'leagues' => 'hockey/nhl,not/a-league',
        'from' => CarbonImmutable::parse('2026-11-03 12:00:00')->timestamp,
        'to' => CarbonImmutable::parse('2026-11-04 12:00:00')->timestamp,
    ];
    $response = sportsApi($this, 'get_sports_fixtures', $params)->assertOk();

    $fixture = $response->json('fixtures.0');
    expect($response->json('fixtures'))->toHaveCount(1)
        ->and($fixture)->toMatchArray([
            'id' => '401',
            'league_id' => 'hockey/nhl',
            'start' => '2026-11-04T00:00:00Z',
            'state' => 'live',
            'status_detail' => '1st - 15:00',
            'broadcasts' => ['ESPN+', 'Utah 16'],
        ])
        ->and($fixture['home']['team'])->toMatchArray([
            'display_name' => 'Toronto Maple Leafs',
            'nickname' => 'Maple Leafs',
            'color' => '003e7e',
        ])
        ->and($fixture['home']['team']['logo'])->toContain('/logo-proxy/')
        ->and($fixture['home']['team']['logo'])->not->toContain('espncdn')
        ->and($fixture['away']['score'])->toBe('1');

    // The range widened a day each side: Nov 2 to Nov 5, NHL only.
    Http::assertSentCount(4);
    Http::assertSent(fn (HttpRequest $request): bool => str_starts_with(
        $request->url(),
        EspnSportsService::DEFAULT_API_URL.'/hockey/nhl/scoreboard'
    ));

    sportsApi($this, 'get_sports_fixtures', $params)->assertOk();
    Http::assertSentCount(4);
});

it('reads stoppages as postponed and survives a failing league day', function () {
    enableSports(apiUrl: 'https://mirror.example.com/sports/');
    Http::fake([
        'mirror.example.com/sports/hockey/nhl/scoreboard?dates=20261103*' => Http::response([
            'events' => [espnEvent('402', '2026-11-03T23:00:00Z', 'post', 'STATUS_POSTPONED')],
        ]),
        'mirror.example.com/*' => Http::response('down', 503),
    ]);

    $fixtures = app(EspnSportsService::class)->fixtures(
        ['hockey/nhl'],
        CarbonImmutable::parse('2026-11-03 00:00:00'),
        CarbonImmutable::parse('2026-11-04 00:00:00'),
    );

    expect($fixtures)->toHaveCount(1)
        ->and($fixtures[0]['state'])->toBe('postponed');
});

it('saves the sports settings and tests the typed API URL', function () {
    $this->actingAs(User::factory()->admin()->create());
    Http::fake([
        'mirror.example.com/*' => Http::response(['events' => [espnEvent('1', '2026-11-03T23:00:00Z')]]),
    ]);

    Livewire::test(ManageIntegrationSettings::class)
        ->fillForm([
            'sports_espn_enabled' => true,
            'sports_espn_api_url' => 'https://mirror.example.com/sports',
        ])
        ->callAction(TestAction::make('test_sports_connection')->schemaComponent('sports_espn'))
        ->assertNotified(__('Connection Successful'))
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(GeneralSettings::class);
    $settings->refresh();
    expect($settings->sports_espn_enabled)->toBeTrue()
        ->and($settings->sports_espn_api_url)->toBe('https://mirror.example.com/sports');
});
