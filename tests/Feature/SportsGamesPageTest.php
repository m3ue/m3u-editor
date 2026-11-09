<?php

use App\Filament\Pages\CustomDashboard;
use App\Filament\Pages\SportsGames;
use App\Filament\Resources\Playlists\Pages\ListPlaylists;
use App\Filament\Tables\SportsLeaguesTable;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\Sports\SportsGameDisplay;
use App\Services\Sports\SportsService;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\TableSelect\Livewire\TableSelectLivewireComponent;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * @param  list<string>  $leagueIds
 */
function sportsPageEnable(bool $enabled = true, array $leagueIds = ['hockey/nhl']): void
{
    $settings = app(GeneralSettings::class);
    $settings->sports_espn_enabled = $enabled;
    $settings->sports_tsdb_enabled = false;
    $settings->sports_league_ids = $leagueIds;
    $settings->save();
}

function sportsPageMatchingChannel(User $user, Playlist $playlist): Channel
{
    return Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'group_id' => null,
        'title' => 'NHL: Utah Mammoth at Toronto Maple Leafs',
        'title_custom' => null,
    ]);
}

function sportsPageLeaguesTable(array $state = [])
{
    return Livewire::test(TableSelectLivewireComponent::class, [
        'tableConfiguration' => base64_encode(SportsLeaguesTable::class),
        'state' => $state,
    ]);
}

/**
 * @return array<string, mixed>
 */
function sportsPageTeam(string $id, string $name, string $color): array
{
    return ['id' => $id, 'displayName' => $name, 'name' => $name, 'abbreviation' => strtoupper($id), 'color' => $color, 'logo' => "https://a.espncdn.com/{$id}.png"];
}

/**
 * @return array<string, mixed>
 */
function sportsPageEvent(string $state = 'in', string $statusName = 'STATUS_IN_PROGRESS'): array
{
    return [
        'id' => '401',
        'date' => '2026-11-03T19:00Z',
        'name' => 'Utah Mammoth at Toronto Maple Leafs',
        'status' => ['type' => ['name' => $statusName, 'state' => $state, 'shortDetail' => '3rd - 10:00']],
        'competitions' => [[
            'competitors' => [
                ['homeAway' => 'home', 'score' => '2', 'team' => sportsPageTeam('tor', 'Toronto Maple Leafs', '003e7e')],
                ['homeAway' => 'away', 'score' => '3', 'team' => sportsPageTeam('uta', 'Utah Mammoth', '69b3e7')],
            ],
        ]],
    ];
}

function sportsPageLeagueSessionKey(): string
{
    return 'lw'.crc32((new SportsGames)->getName().'leagueIds');
}

function sportsPageFakeEspn(string $state = 'in', string $statusName = 'STATUS_IN_PROGRESS'): void
{
    Http::fake(function (HttpRequest $request) use ($state, $statusName) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if (str_ends_with($path, '/summary')) {
            $event = sportsPageEvent($state, $statusName);

            return Http::response([
                'header' => ['id' => '401', 'competitions' => [[
                    'date' => $event['date'],
                    'status' => $event['status'],
                    'competitors' => $event['competitions'][0]['competitors'],
                ]]],
                'boxscore' => ['teams' => [
                    ['homeAway' => 'home', 'statistics' => [['name' => 'shots', 'label' => 'Shots', 'displayValue' => '29']]],
                    ['homeAway' => 'away', 'statistics' => [['name' => 'shots', 'label' => 'Shots', 'displayValue' => '36']]],
                ]],
                'gameInfo' => ['venue' => ['fullName' => 'Scotiabank Arena']],
            ]);
        }
        if (str_ends_with($path, '/teams')) {
            return Http::response(['sports' => [['leagues' => [['teams' => []]]]]]);
        }

        return Http::response(['events' => (string) $request['dates'] === '20261103' ? [sportsPageEvent($state, $statusName)] : []]);
    });
}

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Queue::fake();
    $this->travelTo(now()->parse('2026-11-03 20:00:00', 'UTC'));
    config(['app.timezone' => 'UTC']);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create(['name' => 'Alpha']);
});

it('reads teams, colours and shares for display', function () {
    $nhl = ['league_id' => 'hockey/nhl', 'home' => ['team' => ['color' => '003e7e']], 'away' => ['team' => ['color' => 'ffffff', 'alternate_color' => '69b3e7']]];
    $soccer = ['league_id' => 'soccer/eng.1', 'home' => ['team' => ['color' => '000000']], 'away' => ['team' => ['color' => '000000']]];

    expect(SportsGameDisplay::sideOrder($nhl))->toBe(['away', 'home'])
        ->and(SportsGameDisplay::sideOrder($soccer))->toBe(['home', 'away'])
        // White falls back to the alternate
        ->and(SportsGameDisplay::teamColor($nhl['away']['team']))->toBe([105, 179, 231])
        ->and(SportsGameDisplay::washStyle($nhl))->toBe('background-image: linear-gradient(90deg, rgb(105 179 231 / 0.32), rgb(0 62 126 / 0.32));')
        ->and(SportsGameDisplay::washStyle($soccer))->toBe('')
        ->and(SportsGameDisplay::barColors($nhl))->toBe(['rgb(105 179 231)', 'rgb(0 62 126)'])
        ->and(SportsGameDisplay::barColors($soccer))->toBe(['var(--primary-500)', 'var(--danger-500)'])
        ->and(SportsGameDisplay::shares('30', '70%'))->toBe([30.0, 70.0])
        ->and(SportsGameDisplay::shares('W1', 'L5'))->toBeNull()
        ->and(SportsGameDisplay::shares('7-15', '1-11'))->toBeNull()
        ->and(SportsGameDisplay::shares('0', '0'))->toBeNull();
});

it('hides the page while Sports is off', function () {
    sportsPageEnable(false);

    expect(SportsGames::canAccess())->toBeFalse();
    Livewire::test(CustomDashboard::class)->assertActionHidden('sports_games');
    Livewire::test(ListPlaylists::class)->assertActionHidden(TestAction::make('sports_games')->table($this->playlist));
});

it('links to the page from the dashboard and the playlist', function () {
    sportsPageEnable();

    Livewire::test(CustomDashboard::class)->assertActionVisible('sports_games');
    Livewire::test(ListPlaylists::class)
        ->assertActionHasUrl(TestAction::make('sports_games')->table($this->playlist), SportsGames::getUrl(['playlistId' => $this->playlist->id]));
});

it('opens with no request to the provider, then shows games and their channels in turn', function () {
    sportsPageEnable();
    sportsPageFakeEspn();
    sportsPageMatchingChannel($this->user, $this->playlist);

    $page = Livewire::test(SportsGames::class)
        ->assertSet('playlistId', $this->playlist->id)
        ->assertSet('day', '2026-11-03')
        ->assertSet('leagueIds', ['hockey/nhl'])
        ->assertDontSee('Utah Mammoth');
    Http::assertNothingSent();

    $page->call('loadGames')
        ->assertSee('Live Now')
        ->assertSee('Utah Mammoth')
        ->assertDontSee('1 channel');

    $page->call('loadChannels')->assertSee('1 channel');
});

it('marks a game no channel carries', function () {
    sportsPageEnable();
    sportsPageFakeEspn();

    Livewire::test(SportsGames::class)->call('loadGames')->call('loadChannels')->assertSee('No channel');
});

it('shows a finished game without asking about channels', function () {
    sportsPageEnable();
    sportsPageFakeEspn('post', 'STATUS_FINAL');

    Livewire::test(SportsGames::class)->call('loadGames')->call('loadChannels')->assertSee('Final')->assertDontSee('No channel');
});

it('asks for channels again when the filters change', function () {
    sportsPageEnable();
    sportsPageFakeEspn();

    Livewire::test(SportsGames::class)
        ->call('loadGames')
        ->call('loadChannels')
        ->assertSet('channelsLoaded', true)
        ->call('shiftDay', 1)
        ->assertSet('day', '2026-11-04')
        ->assertSet('channelsLoaded', false)
        ->call('goToToday')
        ->assertSet('day', '2026-11-03')
        ->call('loadChannels')
        ->set('leagueIds', [])
        ->assertSet('channelsLoaded', false)
        ->assertSee('Choose some leagues');
});

it('offers only the leagues Settings limits Sports to', function () {
    sportsPageEnable();

    sportsPageLeaguesTable()
        ->assertSee('NHL')
        ->assertDontSee('NBA')
        ->assertDontSee('Premier League');
});

it('offers every league while Settings sets no limit, a page at a time', function () {
    sportsPageEnable(leagueIds: []);

    $table = sportsPageLeaguesTable()->searchTable('premier league');

    expect($table->instance()->getTableRecords()->keys()->all())->toContain('soccer/eng.1')
        ->and($table->instance()->getTableRecords()->total())->toBeLessThan(25);
    expect(sportsPageLeaguesTable()->instance()->getTableRecords())->toHaveCount(25);
});

it('searches, sorts and filters the leagues', function () {
    sportsPageEnable(leagueIds: ['hockey/nhl', 'basketball/nba', 'soccer/eng.1']);
    $keys = fn ($table): array => $table->instance()->getTableRecords()->keys()->all();

    expect($keys(sportsPageLeaguesTable()))->toBe(['basketball/nba', 'hockey/nhl', 'soccer/eng.1'])
        ->and($keys(sportsPageLeaguesTable()->sortTable('name', 'desc')))->toBe(['soccer/eng.1', 'hockey/nhl', 'basketball/nba'])
        ->and($keys(sportsPageLeaguesTable()->searchTable('hockey')))->toBe(['hockey/nhl'])
        ->and($keys(sportsPageLeaguesTable()->filterTable('sport', 'soccer')))->toBe(['soccer/eng.1'])
        ->and($keys(sportsPageLeaguesTable(['hockey/nhl'])->filterTable('selection', true)))->toBe(['hockey/nhl'])
        ->and($keys(sportsPageLeaguesTable(['hockey/nhl'])->filterTable('selection', false)))->toBe(['basketball/nba', 'soccer/eng.1']);
});

it('picks leagues from the table and looks for their channels again', function () {
    sportsPageEnable(leagueIds: ['hockey/nhl', 'basketball/nba']);
    sportsPageFakeEspn();

    Livewire::test(SportsGames::class)
        ->assertSee('2 leagues selected')
        ->call('loadGames')
        ->call('loadChannels')
        ->callAction(TestAction::make('select')->schemaComponent('leagueIds', schema: 'filtersForm'), data: ['selection' => ['hockey/nhl']])
        ->assertHasNoActionErrors()
        ->assertSet('leagueIds', ['hockey/nhl'])
        ->assertSet('channelsLoaded', false)
        ->assertSee('1 league selected');
});

it('drops remembered leagues Settings has since left out', function () {
    sportsPageEnable(leagueIds: ['hockey/nhl', 'basketball/nba']);

    Livewire::test(SportsGames::class)->assertSet('leagueIds', ['basketball/nba', 'hockey/nhl']);

    sportsPageEnable(leagueIds: ['hockey/nhl']);

    Livewire::test(SportsGames::class)->assertSet('leagueIds', ['hockey/nhl']);
});

it('falls back to the defaults when Settings left out every remembered league', function () {
    sportsPageEnable();
    session()->put(sportsPageLeagueSessionKey(), ['basketball/nba']);

    Livewire::test(SportsGames::class)->assertSet('leagueIds', ['hockey/nhl']);
});

it('clears all leagues in one go, and remembers that', function () {
    sportsPageEnable();
    sportsPageFakeEspn();

    Livewire::test(SportsGames::class)
        ->call('loadGames')
        ->call('loadChannels')
        ->assertSee('1 league selected')
        ->assertActionVisible(TestAction::make('clearLeagues')->schemaComponent('leagueIds', schema: 'filtersForm'))
        ->callAction(TestAction::make('clearLeagues')->schemaComponent('leagueIds', schema: 'filtersForm'))
        ->assertSet('leagueIds', [])
        ->assertSet('channelsLoaded', false)
        ->assertDontSee('1 league selected')
        ->assertSee('Choose some leagues');

    Livewire::test(SportsGames::class)->assertSet('leagueIds', []);
});

it('moves between days from the date field', function () {
    sportsPageEnable();
    sportsPageFakeEspn();
    $dayAction = fn (string $name): TestAction => TestAction::make($name)->schemaComponent('day', schema: 'filtersForm');

    Livewire::test(SportsGames::class)
        ->callAction($dayAction('nextDay'))
        ->assertSet('day', '2026-11-04')
        ->assertSet('channelsLoaded', false)
        ->callAction($dayAction('today'))
        ->assertSet('day', '2026-11-03')
        ->callAction($dayAction('previousDay'))
        ->assertSet('day', '2026-11-02');
});

it('splits the day by league, each under its logo', function () {
    sportsPageEnable();
    sportsPageFakeEspn('pre', 'STATUS_SCHEDULED');

    Livewire::test(SportsGames::class)
        ->call('loadGames')
        ->assertDontSee('Live Now')
        ->assertSeeInOrder(['NHL', '1 game', 'Utah Mammoth'])
        ->assertSee(SportsService::proxiedLogo('https://a.espncdn.com/i/teamlogos/leagues/500-dark/nhl.png'));
});

it('names each live game\'s league with its logo', function () {
    sportsPageEnable();
    sportsPageFakeEspn();

    Livewire::test(SportsGames::class)
        ->call('loadGames')
        ->assertSeeInOrder(['Live Now', '1 game', 'NHL', 'Utah Mammoth'])
        ->assertSee(SportsService::proxiedLogo('https://a.espncdn.com/i/teamlogos/leagues/500-dark/nhl.png'));
});

it('offers Today only away from today', function () {
    sportsPageEnable();
    sportsPageFakeEspn();
    $today = TestAction::make('today')->schemaComponent('day', schema: 'filtersForm');

    Livewire::test(SportsGames::class)
        ->assertDontSeeHtml('>Today<')
        ->call('shiftDay', 1)
        ->assertActionVisible($today)
        ->assertSeeHtml('>Today<')
        ->callAction($today)
        ->assertSet('day', '2026-11-03')
        ->assertDontSeeHtml('>Today<');
});

it('ties the rematch action to channel matching', function () {
    sportsPageEnable();
    sportsPageFakeEspn();
    $second = Playlist::factory()->for($this->user)->create(['name' => 'Beta']);
    $rematch = TestAction::make('rematchChannels')->schemaComponent('playlistId', schema: 'filtersForm');

    $page = Livewire::test(SportsGames::class)->assertDontSee('Finding channels')->assertDontSee('Rematch channels');

    $page->call('loadGames')
        ->assertSee('Finding channels')
        ->assertActionDisabled($rematch)
        ->call('loadChannels')
        ->assertDontSee('Finding channels')
        ->assertSee('Rematch channels')
        ->assertActionEnabled($rematch)
        ->assertSeeHtml('wire:target="loadChannels,playlistId,day,leagueIds"')
        ->set('playlistId', $second->id)
        ->assertSet('channelsLoaded', false)
        ->assertSee('Finding channels')
        ->assertActionDisabled($rematch);
});

it('rematches on demand instead of reading the last run', function () {
    sportsPageEnable();
    sportsPageFakeEspn();
    $rematch = TestAction::make('rematchChannels')->schemaComponent('playlistId', schema: 'filtersForm');

    $page = Livewire::test(SportsGames::class)
        ->call('loadGames')
        ->call('loadChannels')
        ->assertSee('No channel');

    // Added since that run, so only a fresh match can find it
    sportsPageMatchingChannel($this->user, $this->playlist);
    $page->call('$refresh')->assertSee('No channel');

    $page->callAction($rematch)
        ->assertSet('channelsLoaded', false)
        ->call('loadChannels')
        ->assertSee('1 channel');
});

it('has nothing to rematch on a day of finished games', function () {
    sportsPageEnable();
    sportsPageFakeEspn('post', 'STATUS_FINAL');

    Livewire::test(SportsGames::class)
        ->call('loadGames')
        ->call('loadChannels')
        ->assertDontSee('Rematch channels');
});

it('stays within the date range', function () {
    sportsPageEnable();
    sportsPageFakeEspn();

    Livewire::withQueryParams(['day' => '2030-01-01'])
        ->test(SportsGames::class)
        ->assertSet('day', '2026-11-03');
    Livewire::withQueryParams(['day' => '2026-11-17'])
        ->test(SportsGames::class)
        ->assertSet('day', '2026-11-17')
        ->call('shiftDay', 1)
        ->assertSet('day', '2026-11-17');
});

it('falls back to today for a date that does not exist', function (string $day) {
    sportsPageEnable();
    sportsPageFakeEspn();

    Livewire::withQueryParams(['day' => $day])
        ->test(SportsGames::class)
        ->assertSuccessful()
        ->assertSet('day', '2026-11-03');
})->with(['2026-13-45', '2026-11-00']);

it('never shows another user\'s playlist', function () {
    sportsPageEnable();
    sportsPageFakeEspn();
    $other = Playlist::factory()->for(User::factory())->create();
    sportsPageMatchingChannel($other->user, $other);

    Livewire::withQueryParams(['playlistId' => $other->id])
        ->test(SportsGames::class)
        ->assertSet('playlistId', $this->playlist->id);

    Livewire::test(SportsGames::class)
        ->set('playlistId', $other->id)
        ->call('loadGames')
        ->call('loadChannels')
        ->assertDontSee('1 channel');
});

it('shows a game\'s details in a slide-over and plays its channel', function () {
    sportsPageEnable();
    sportsPageFakeEspn();
    $channel = sportsPageMatchingChannel($this->user, $this->playlist);

    Livewire::test(SportsGames::class)
        ->call('loadGames')
        ->call('loadChannels')
        ->mountAction('gameDetails', ['league' => 'hockey/nhl', 'fixture' => '401'])
        ->assertActionMounted('gameDetails')
        ->assertMountedActionModalSee(['Team stats', 'Shots', 'Scotiabank Arena', 'On Your Channels', $channel->title])
        ->assertMountedActionModalSeeHtml('wire:click="playChannel('.$channel->id.')"')
        ->call('playChannel', $channel->id)
        ->assertDispatched('openFloatingStream');
});

it('refuses to play a channel not found for the day\'s games', function () {
    sportsPageEnable();
    sportsPageFakeEspn();
    $unrelated = Channel::factory()->for($this->user)->for($this->playlist)->create(['enabled' => true, 'is_vod' => false, 'group_id' => null]);

    Livewire::test(SportsGames::class)
        ->call('loadGames')
        ->call('loadChannels')
        ->call('playChannel', $unrelated->id)
        ->assertNotDispatched('openFloatingStream');
});

it('still shows a game whose details can\'t be read', function () {
    sportsPageEnable();
    Http::fake(function (HttpRequest $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if (str_ends_with($path, '/summary')) {
            return Http::response(['code' => 404], 404);
        }

        return Http::response(['events' => (string) $request['dates'] === '20261103' ? [sportsPageEvent('post', 'STATUS_FINAL')] : []]);
    });

    Livewire::test(SportsGames::class)
        ->call('loadGames')
        ->mountAction('gameDetails', ['league' => 'hockey/nhl', 'fixture' => '401'])
        ->assertMountedActionModalSee(['No more details are available for this game yet.', 'Toronto Maple Leafs']);
});
