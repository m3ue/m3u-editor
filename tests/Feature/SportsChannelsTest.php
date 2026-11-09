<?php

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\EpgProgrammeStore;
use App\Services\EspnSportsService;
use App\Services\Sports\SportsChannelMatcher;
use App\Settings\GeneralSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Utah Mammoth at Toronto Maple Leafs, kicking off at [$start].
 *
 * @return array<string, mixed>
 */
function sportsChannelsGame(string $id, string $start): array
{
    $team = fn (string $teamId, string $name, string $nickname): array => [
        'id' => $teamId,
        'displayName' => $name,
        'shortDisplayName' => $nickname,
        'name' => $nickname,
        'abbreviation' => strtoupper($teamId),
    ];

    return [
        'id' => $id,
        'date' => $start,
        'name' => 'Utah Mammoth at Toronto Maple Leafs',
        'status' => ['type' => ['name' => 'STATUS_SCHEDULED', 'state' => 'pre', 'shortDetail' => '7:00 PM']],
        'competitions' => [[
            'competitors' => [
                ['homeAway' => 'home', 'team' => $team('tor', 'Toronto Maple Leafs', 'Maple Leafs')],
                ['homeAway' => 'away', 'team' => $team('uta', 'Utah Mammoth', 'Mammoth')],
            ],
        ]],
    ];
}

function sportsChannelsFakeProvider(): void
{
    Http::fake(fn (HttpRequest $request) => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/teams')
        ? Http::response(['sports' => [['leagues' => [['teams' => []]]]]])
        : Http::response(['events' => (string) $request['dates'] === '20261103'
            ? [sportsChannelsGame('401', '2026-11-04T00:00Z')]
            : []]));
}

/**
 * @param  array<string, mixed>  $params
 */
function sportsChannelsRequest($test, array $params = [])
{
    return $test->getJson(route('xtream.api.player', [
        'action' => 'get_sports_channels',
        'username' => 'sports_channels',
        'password' => 'secret',
        'leagues' => 'hockey/nhl',
        'from' => CarbonImmutable::parse('2026-11-03 07:00:00')->timestamp,
        'to' => CarbonImmutable::parse('2026-11-04 07:00:00')->timestamp,
        ...$params,
    ]));
}

function sportsChannelsEnable(): void
{
    $settings = app(GeneralSettings::class);
    $settings->sports_espn_enabled = true;
    $settings->app_output_enabled = true;
    $settings->save();
}

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Queue::fake();
    Storage::fake('local');
    $this->travelTo(now()->parse('2026-11-03 18:00:00', 'UTC'));

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->playlist->playlistAuths()->attach(PlaylistAuth::create([
        'name' => 'Sports Channels',
        'username' => 'sports_channels',
        'password' => 'secret',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]));
    $this->epg = Epg::factory()->for($this->user)->create(['is_cached' => true]);

    $this->channel = function (string $title, ?string $guideId = null, array $attributes = []): Channel {
        return Channel::factory()->for($this->user)->for($this->playlist)->create([
            'enabled' => true,
            'is_vod' => false,
            'group_id' => null,
            'title' => $title,
            'title_custom' => null,
            'epg_channel_id' => $guideId === null ? null : EpgChannel::factory()->for($this->user)->for($this->epg)->create(['channel_id' => $guideId])->id,
            ...$attributes,
        ]);
    };
    sportsChannelsFakeProvider();
});

it('reads only titles and subtitles from the guide store', function () {
    $path = sys_get_temp_dir().'/sports-channels-'.uniqid().'.sqlite';
    $store = new EpgProgrammeStore;
    $store->beginWrite($path);
    $store->insert('a', '2026-11-03', 1000, 2000, ['title' => 'Game', 'subtitle' => 'Teams', 'desc' => 'Long text']);
    $store->insert('b', '2026-11-03', 3000, 4000, ['title' => 'Too late']);
    $store->finish();

    $rows = iterator_to_array(EpgProgrammeStore::openRead($path)->readAiring(['2026-11-03'], 1500, 2500));
    unlink($path);

    expect($rows)->toBe([['channel_id' => 'a', 'start_ts' => 1000, 'stop_ts' => 2000, 'title' => 'Game', 'subtitle' => 'Teams']]);
});

it('stays behind the sports setting and validates its range', function () {
    sportsChannelsEnable();
    $settings = app(GeneralSettings::class);
    $settings->sports_espn_enabled = false;
    $settings->save();

    sportsChannelsRequest($this)->assertForbidden();
    Http::assertNothingSent();

    $settings->sports_espn_enabled = true;
    $settings->save();

    sportsChannelsRequest($this, ['to' => CarbonImmutable::parse('2026-11-06 07:00:00')->timestamp])
        ->assertStatus(422)
        ->assertJsonPath('fields', ['to']);
});

it('takes every league at once in a POST body', function () {
    sportsChannelsEnable();
    sportsChannelsFakeProvider();
    $leagues = implode(',', array_column(EspnSportsService::leagues(), 'id'));
    expect(strlen($leagues))->toBeGreaterThan(2000);

    $this->postJson(route('xtream.api.player', ['action' => 'get_sports_channels', 'username' => 'sports_channels', 'password' => 'secret']), [
        'leagues' => $leagues,
        'from' => CarbonImmutable::parse('2026-11-03 07:00:00')->timestamp,
        'to' => CarbonImmutable::parse('2026-11-04 07:00:00')->timestamp,
    ])->assertOk();
});

it('advertises the action with the sports feature', function () {
    sportsChannelsEnable();
    $response = $this->getJson(route('xtream.api.player', ['action' => 'panel', 'username' => 'sports_channels', 'password' => 'secret']));

    expect($response->json('m3u_editor.sports.actions.channels'))->toBe('get_sports_channels');
});

it('answers with an empty object when nothing matches', function () {
    sportsChannelsEnable();
    ($this->channel)('Cooking Channel');

    expect(sportsChannelsRequest($this)->assertOk()->getContent())->toBe('{"channels":{}}');
});

it('links games by programme title, channel title and subtitle, strongest first', function () {
    sportsChannelsEnable();
    $bySubtitle = ($this->channel)('Sports 1', 'sub');
    $byName = ($this->channel)('NHL 03: Utah Mammoth at Toronto Maple Leafs');
    $byHeadline = ($this->channel)('Sports 2', 'headline');
    seedEpgProgrammeCache($this->epg, [
        ['sub', ['title' => 'NHL Hockey', 'subtitle' => 'Mammoth at Maple Leafs', 'start' => '2026-11-04T00:00:00Z', 'stop' => '2026-11-04T03:00:00Z']],
        ['headline', ['title' => 'NHL Hockey: Utah Mammoth at Toronto Maple Leafs', 'start' => '2026-11-04T00:00:00Z', 'stop' => '2026-11-04T03:00:00Z']],
    ]);

    sportsChannelsRequest($this)->assertOk()->assertExactJson(['channels' => ['hockey/nhl:401' => [
        ['stream_id' => $byHeadline->id, 'program_title' => 'NHL Hockey: Utah Mammoth at Toronto Maple Leafs'],
        ['stream_id' => $byName->id, 'program_title' => null],
        ['stream_id' => $bySubtitle->id, 'program_title' => 'NHL Hockey'],
    ]]]);
});

it('only uses enabled live channels', function () {
    sportsChannelsEnable();
    ($this->channel)('NHL 01: Utah Mammoth at Toronto Maple Leafs', null, ['enabled' => false]);
    ($this->channel)('NHL 02: Utah Mammoth at Toronto Maple Leafs', null, ['is_vod' => true]);
    ($this->channel)('Sports 1', 'disabled', ['enabled' => false]);
    seedEpgProgrammeCache($this->epg, [
        ['disabled', ['title' => 'NHL Hockey: Utah Mammoth at Toronto Maple Leafs', 'start' => '2026-11-04T00:00:00Z', 'stop' => '2026-11-04T03:00:00Z']],
    ]);

    sportsChannelsRequest($this)->assertOk()->assertExactJson(['channels' => []]);
});

it('ignores programmes off air in the minutes after kickoff', function () {
    sportsChannelsEnable();
    $live = ($this->channel)('Sports 1', 'live');
    ($this->channel)('Sports 2', 'pregame');
    ($this->channel)('Sports 3', 'replay');
    seedEpgProgrammeCache($this->epg, [
        ['live', ['title' => 'NHL: Utah Mammoth at Toronto Maple Leafs', 'start' => '2026-11-04T00:30:00Z', 'stop' => '2026-11-04T03:00:00Z']],
        ['pregame', ['title' => 'NHL Preview: Utah Mammoth at Toronto Maple Leafs', 'start' => '2026-11-03T23:00:00Z', 'stop' => '2026-11-04T00:05:00Z']],
        ['replay', ['title' => 'NHL Replay: Utah Mammoth at Toronto Maple Leafs', 'start' => '2026-11-04T05:00:00Z', 'stop' => '2026-11-04T08:00:00Z']],
    ]);

    $channels = sportsChannelsRequest($this)->assertOk()->json('channels.hockey/nhl:401');

    expect(array_column($channels, 'stream_id'))->toBe([$live->id]);
});

it('applies the guide rules to programme text', function () {
    sportsChannelsEnable();
    ($this->channel)('Sports 1', 'wrong-sport');
    seedEpgProgrammeCache($this->epg, [
        ['wrong-sport', ['title' => 'Basketball: Utah Mammoth at Toronto Maple Leafs', 'start' => '2026-11-04T00:00:00Z', 'stop' => '2026-11-04T03:00:00Z']],
    ]);

    sportsChannelsRequest($this)->assertOk()->assertExactJson(['channels' => []]);
});

it('never reads programme descriptions', function () {
    sportsChannelsEnable();
    ($this->channel)('Sports 1', 'described');
    seedEpgProgrammeCache($this->epg, [
        ['described', ['title' => 'Late Sports', 'desc' => 'Utah Mammoth at Toronto Maple Leafs', 'start' => '2026-11-04T00:00:00Z', 'stop' => '2026-11-04T03:00:00Z']],
    ]);

    sportsChannelsRequest($this)->assertOk()->assertExactJson(['channels' => []]);
});

it('answers repeat requests from a short cache', function () {
    sportsChannelsEnable();
    $first = ($this->channel)('NHL 01: Utah Mammoth at Toronto Maple Leafs');

    sportsChannelsRequest($this)->assertOk();
    ($this->channel)('NHL 02: Utah Mammoth at Toronto Maple Leafs');

    expect(array_column(sportsChannelsRequest($this)->json('channels.hockey/nhl:401'), 'stream_id'))->toBe([$first->id]);

    $this->travel(6)->minutes();

    expect(sportsChannelsRequest($this)->json('channels.hockey/nhl:401'))->toHaveCount(2);
});

it('caps the channels of one game', function () {
    sportsChannelsEnable();
    foreach (range(1, 31) as $number) {
        ($this->channel)("NHL {$number}: Utah Mammoth at Toronto Maple Leafs");
    }

    expect(sportsChannelsRequest($this)->json('channels.hockey/nhl:401'))->toHaveCount(30);
});

it('matches a custom playlist through its own channels', function () {
    sportsChannelsEnable();
    $outside = ($this->channel)('NHL 01: Utah Mammoth at Toronto Maple Leafs');
    $inside = ($this->channel)('NHL 02: Utah Mammoth at Toronto Maple Leafs');
    $custom = CustomPlaylist::factory()->for($this->user)->create();
    $custom->channels()->attach($inside->id);

    $channels = app(SportsChannelMatcher::class)->channelsFor(
        $custom,
        ['hockey/nhl'],
        CarbonImmutable::parse('2026-11-03 07:00:00'),
        CarbonImmutable::parse('2026-11-04 07:00:00'),
    );

    expect(array_column($channels['hockey/nhl:401'], 'stream_id'))->toBe([$inside->id])
        ->and($outside->id)->not->toBe($inside->id);
});
