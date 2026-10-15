<?php

use App\Http\Controllers\LogoProxyController;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Dedicated backend feature coverage for the `get_epg_batch` Xtream action
 * (#1387) — m3u-tv relies on the complete response contract, but until now
 * client tests relied on hand-written fixtures with no server-side pin.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Storage::fake('local');

    Carbon::setTestNow(Carbon::parse('2026-08-13 12:00:00', 'UTC'));

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->username = 'xtream_user_'.uniqid();
    $this->password = 'xtream_pass';

    $this->auth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => $this->username,
        'password' => $this->password,
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);

    $this->playlist->playlistAuths()->attach($this->auth->id);
});

afterEach(function () {
    Carbon::setTestNow();
});

function batchUrl(string $username, string $password, array $params = []): string
{
    return route('xtream.api.player').'?'.http_build_query(array_merge([
        'username' => $username,
        'password' => $password,
        'action' => 'get_epg_batch',
    ], $params));
}

/** @return array{epg: Epg, channel: Channel, epgChannelId: string} */
function makeBatchChannel(User $user, Playlist $playlist, string $epgChannelId, array $channelAttrs = []): array
{
    $epg = Epg::factory()->for($user)->create(['is_cached' => true]);

    $epgChannel = EpgChannel::factory()
        ->for($user)
        ->for($epg)
        ->create(['channel_id' => $epgChannelId]);

    $channel = Channel::factory()
        ->for($user)
        ->for($playlist)
        ->create(array_merge([
            'enabled' => true,
            'epg_channel_id' => $epgChannel->id,
            // No group: the default ChannelFactory group_id spins up a throwaway
            // Group -> Playlist -> dummy EPG per channel, which this suite does
            // not need.
            'group_id' => null,
        ], $channelAttrs));

    return ['epg' => $epg, 'channel' => $channel, 'epgChannelId' => $epgChannelId];
}

/**
 * Append one programme to an EPG's SQLite programme cache. The `$date` argument
 * is retained for call-site readability; the real date bucket is derived from
 * the programme's own `start` by {@see seedEpgProgrammeCache()}. Repeated calls
 * for the same EPG accumulate and rebuild the store.
 */
function putProgramme(Epg $epg, string $date, string $epgChannelId, array $programme): void
{
    static $seeded = [];

    $seeded[$epg->uuid][] = [$epgChannelId, $programme];

    seedEpgProgrammeCache($epg, $seeded[$epg->uuid]);
}

it('rejects missing stream_ids', function () {
    $this->getJson(batchUrl($this->username, $this->password))
        ->assertStatus(400)
        ->assertJsonPath('error', 'stream_ids parameter is required');
});

it('returns a top-level map keyed by stream_id with a nested epg_listings shape', function () {
    $ctx = makeBatchChannel($this->user, $this->playlist, 'channel.one', ['catchup' => null]);

    $today = Carbon::now()->format('Y-m-d');
    putProgramme($ctx['epg'], $today, 'channel.one', [
        'id' => 'prog-1',
        'title' => 'Morning Show',
        'desc' => 'Wake up.',
        'start' => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHour()->format('Y-m-d H:i:s'),
        'lang' => 'en',
    ]);

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => (string) $ctx['channel']->id,
    ]))->assertOk();

    $key = (string) $ctx['channel']->id;
    $response->assertJsonStructure([
        $key => ['epg_listings' => [['id', 'epg_id', 'title', 'description', 'lang', 'start', 'end', 'channel_id', 'start_timestamp', 'stop_timestamp', 'now_playing', 'has_archive']]],
    ]);

    expect($response->json("{$key}.epg_listings.0.title"))->toBe(base64_encode('Morning Show'))
        ->and($response->json("{$key}.epg_listings.0.channel_id"))->toBe('channel.one');
});

it('includes an empty epg_listings entry for a requested channel with no EPG mapping', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
        'epg_channel_id' => null,
        'group_id' => null,
    ]);

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => (string) $channel->id,
    ]))->assertOk();

    expect($response->json((string) $channel->id))->toBe(['epg_listings' => []]);
});

it('resolves programmes independently from multiple EPG sources in one request', function () {
    $ctxA = makeBatchChannel($this->user, $this->playlist, 'channel.a');
    $ctxB = makeBatchChannel($this->user, $this->playlist, 'channel.b');

    $today = Carbon::now()->format('Y-m-d');
    putProgramme($ctxA['epg'], $today, 'channel.a', [
        'id' => 'a-1', 'title' => 'Show A', 'desc' => '',
        'start' => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHour()->format('Y-m-d H:i:s'),
        'lang' => 'en',
    ]);
    putProgramme($ctxB['epg'], $today, 'channel.b', [
        'id' => 'b-1', 'title' => 'Show B', 'desc' => '',
        'start' => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHour()->format('Y-m-d H:i:s'),
        'lang' => 'en',
    ]);

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => $ctxA['channel']->id.','.$ctxB['channel']->id,
    ]))->assertOk();

    expect($response->json((string) $ctxA['channel']->id.'.epg_listings.0.title'))->toBe(base64_encode('Show A'))
        ->and($response->json((string) $ctxB['channel']->id.'.epg_listings.0.title'))->toBe(base64_encode('Show B'));
});

it('merges the requested day and the following day into one listing', function () {
    $ctx = makeBatchChannel($this->user, $this->playlist, 'channel.spanning');

    $today = Carbon::now()->format('Y-m-d');
    $tomorrow = Carbon::now()->addDay()->format('Y-m-d');

    putProgramme($ctx['epg'], $today, 'channel.spanning', [
        'id' => 'today-1', 'title' => 'Tonight', 'desc' => '',
        'start' => Carbon::parse($today)->setTime(23, 0)->format('Y-m-d H:i:s'),
        'stop' => Carbon::parse($today)->setTime(23, 59)->format('Y-m-d H:i:s'),
        'lang' => 'en',
    ]);
    putProgramme($ctx['epg'], $tomorrow, 'channel.spanning', [
        'id' => 'tomorrow-1', 'title' => 'Early Tomorrow', 'desc' => '',
        'start' => Carbon::parse($tomorrow)->setTime(0, 0)->format('Y-m-d H:i:s'),
        'stop' => Carbon::parse($tomorrow)->setTime(1, 0)->format('Y-m-d H:i:s'),
        'lang' => 'en',
    ]);

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => (string) $ctx['channel']->id,
        'date' => $today,
    ]))->assertOk();

    $titles = collect($response->json((string) $ctx['channel']->id.'.epg_listings'))
        ->pluck('title')
        ->map(fn ($t) => base64_decode($t))
        ->all();

    expect($titles)->toContain('Tonight')
        ->and($titles)->toContain('Early Tomorrow');
});

it('flags now_playing only for the programme currently in progress', function () {
    $this->playlist->update(['enable_proxy' => false]);
    $ctx = makeBatchChannel($this->user, $this->playlist, 'channel.now');

    $today = Carbon::now()->format('Y-m-d');
    putProgramme($ctx['epg'], $today, 'channel.now', [
        'id' => 'past', 'title' => 'Past Show', 'desc' => '',
        'start' => Carbon::now()->subHours(2)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->subHour()->format('Y-m-d H:i:s'),
        'lang' => 'en',
    ]);
    putProgramme($ctx['epg'], $today, 'channel.now', [
        'id' => 'current', 'title' => 'Live Now', 'desc' => '',
        'start' => Carbon::now()->subMinutes(10)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addMinutes(10)->format('Y-m-d H:i:s'),
        'lang' => 'en',
    ]);

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => (string) $ctx['channel']->id,
    ]))->assertOk();

    $listings = collect($response->json((string) $ctx['channel']->id.'.epg_listings'))
        ->keyBy(fn ($p) => base64_decode($p['title']));

    // now_playing additionally requires playlist proxy to be enabled and the
    // stream to be actively watched; with proxy disabled it is always 0.
    expect($listings['Past Show']['now_playing'])->toBe(0)
        ->and($listings['Live Now']['now_playing'])->toBe(0);
});

it('flags has_archive only for past programmes when catchup is enabled and not disabled at the playlist level', function () {
    $ctxCatchup = makeBatchChannel($this->user, $this->playlist, 'channel.catchup', ['catchup' => 'default']);
    $ctxNoCatchup = makeBatchChannel($this->user, $this->playlist, 'channel.nocatchup', ['catchup' => null]);

    $today = Carbon::now()->format('Y-m-d');
    foreach ([$ctxCatchup, $ctxNoCatchup] as $ctx) {
        putProgramme($ctx['epg'], $today, $ctx['epgChannelId'], [
            'id' => 'past-'.$ctx['epgChannelId'], 'title' => 'Past Show', 'desc' => '',
            'start' => Carbon::now()->subHours(2)->format('Y-m-d H:i:s'),
            'stop' => Carbon::now()->subHour()->format('Y-m-d H:i:s'),
            'lang' => 'en',
        ]);
    }

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => $ctxCatchup['channel']->id.','.$ctxNoCatchup['channel']->id,
    ]))->assertOk();

    expect($response->json((string) $ctxCatchup['channel']->id.'.epg_listings.0.has_archive'))->toBe(1)
        ->and($response->json((string) $ctxNoCatchup['channel']->id.'.epg_listings.0.has_archive'))->toBe(0);
});

it('fills gaps with dummy programmes when a channel has no cached programmes for the window', function () {
    $ctx = makeBatchChannel($this->user, $this->playlist, 'channel.empty');

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => (string) $ctx['channel']->id,
    ]))->assertOk();

    $listings = $response->json((string) $ctx['channel']->id.'.epg_listings');

    expect($listings)->not->toBeEmpty();
    foreach ($listings as $listing) {
        expect(base64_decode($listing['description']))->toBe('No information available');
    }
});

it('round-trips unicode titles and descriptions through base64 unmodified', function () {
    $ctx = makeBatchChannel($this->user, $this->playlist, 'channel.unicode');

    $today = Carbon::now()->format('Y-m-d');
    putProgramme($ctx['epg'], $today, 'channel.unicode', [
        'id' => 'u-1', 'title' => '深夜劇場 — 東京', 'desc' => 'Café münüde şöyle yazıyor: 你好',
        'start' => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHour()->format('Y-m-d H:i:s'),
        'lang' => 'ja',
    ]);

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => (string) $ctx['channel']->id,
    ]))->assertOk();

    $listing = $response->json((string) $ctx['channel']->id.'.epg_listings.0');

    expect(base64_decode($listing['title']))->toBe('深夜劇場 — 東京')
        ->and(base64_decode($listing['description']))->toBe('Café münüde şöyle yazıyor: 你好');
});

it('caps requested channels at 100 and silently drops the rest', function () {
    $channels = Channel::factory()
        ->for($this->user)
        ->for($this->playlist)
        ->count(101)
        // group_id null: avoid 101 throwaway Group -> Playlist -> dummy EPG
        // cascades from the default ChannelFactory state.
        ->sequence(fn ($sequence) => ['enabled' => true, 'group_id' => null])
        ->create();

    $ids = $channels->pluck('id')->all();

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => implode(',', $ids),
    ]))->assertOk();

    $body = $response->json();

    expect($body)->toHaveCount(100);

    $droppedId = (string) $channels->last()->id;
    $keptIds = array_slice($ids, 0, 100);

    expect(array_key_exists($droppedId, $body))->toBeFalse()
        ->and(array_key_exists((string) $keptIds[0], $body))->toBeTrue();
});

it('uses a deterministic historical date when the date parameter is in the past', function () {
    $ctx = makeBatchChannel($this->user, $this->playlist, 'channel.historical');

    $pastDate = Carbon::now()->subDays(3)->format('Y-m-d');
    putProgramme($ctx['epg'], $pastDate, 'channel.historical', [
        'id' => 'hist-1', 'title' => 'Old Episode', 'desc' => '',
        'start' => Carbon::parse($pastDate)->setTime(10, 0)->format('Y-m-d H:i:s'),
        'stop' => Carbon::parse($pastDate)->setTime(11, 0)->format('Y-m-d H:i:s'),
        'lang' => 'en',
    ]);

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => (string) $ctx['channel']->id,
        'date' => $pastDate,
    ]))->assertOk();

    $listings = collect($response->json((string) $ctx['channel']->id.'.epg_listings'))
        ->keyBy(fn ($p) => base64_decode($p['title']));

    expect($listings->has('Old Episode'))->toBeTrue()
        ->and($listings['Old Episode']['now_playing'])->toBe(0)
        ->and($listings['Old Episode']['has_archive'])->toBe(0); // catchup not enabled on this channel
});

it('rejects batch EPG for network playlists', function () {
    $networkPlaylist = Playlist::factory()->for($this->user)->create(['is_network_playlist' => true]);
    $auth = PlaylistAuth::create([
        'name' => 'Network Auth',
        'username' => 'network_'.uniqid(),
        'password' => 'network_pass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $networkPlaylist->playlistAuths()->attach($auth->id);

    $this->getJson(batchUrl($auth->username, $auth->password, ['stream_ids' => '1']))
        ->assertStatus(400)
        ->assertJsonPath('error', 'Batch EPG not supported for network playlists');
});

it('omits extended programme details unless the client asks for them', function () {
    $ctx = makeBatchChannel($this->user, $this->playlist, 'channel.plain');

    putProgramme($ctx['epg'], Carbon::now()->format('Y-m-d'), 'channel.plain', [
        'id' => 'plain-1', 'title' => 'Plain Show', 'desc' => '',
        'start' => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHour()->format('Y-m-d H:i:s'),
        'category' => 'Drama',
        'icon' => 'https://images.example/plain.jpg',
    ]);

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => (string) $ctx['channel']->id,
    ]))->assertOk();

    $listing = collect($response->json((string) $ctx['channel']->id.'.epg_listings'))
        ->first(fn ($p) => $p['id'] === 'plain-1');

    expect($listing)->not->toHaveKeys(['category', 'icon']);
});

it('includes extended programme details when details=1', function () {
    $this->playlist->update(['enable_logo_proxy' => false]);
    $ctx = makeBatchChannel($this->user, $this->playlist, 'channel.rich');

    putProgramme($ctx['epg'], Carbon::now()->format('Y-m-d'), 'channel.rich', [
        'id' => 'rich-1', 'title' => 'Rich Show', 'desc' => 'Plenty going on.',
        'start' => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHour()->format('Y-m-d H:i:s'),
        'category' => 'Drama',
        'rating' => 'TV-14',
        'icon' => 'https://images.example/rich.jpg',
        'episode_num' => '1.4.',
        'episode_nums' => [['system' => 'xmltv_ns', 'value' => '1.4.']],
        'new' => true,
        'premiere' => false,
        'previously_shown' => false,
        'production_year' => 2024,
    ]);
    putProgramme($ctx['epg'], Carbon::now()->format('Y-m-d'), 'channel.rich', [
        'id' => 'bare-1', 'title' => 'Bare Show', 'desc' => '',
        'start' => Carbon::now()->addHours(2)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHours(3)->format('Y-m-d H:i:s'),
    ]);

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => (string) $ctx['channel']->id,
        'details' => 1,
    ]))->assertOk();

    $listings = collect($response->json((string) $ctx['channel']->id.'.epg_listings'))->keyBy('id');

    expect($listings['rich-1'])->toMatchArray([
        'category' => 'Drama',
        'rating' => 'TV-14',
        'icon' => 'https://images.example/rich.jpg',
        'season' => 2,
        'episode' => 5,
        'is_new' => 1,
        'year' => 2024,
    ])
        ->and($listings['rich-1'])->not->toHaveKeys(['premiere', 'previously_shown'])
        ->and($listings['bare-1'])->not->toHaveKeys(['category', 'rating', 'icon', 'season', 'episode', 'is_new', 'year']);
});

it('routes extended programme icons through the logo proxy when the playlist enables it', function () {
    $this->playlist->update(['enable_logo_proxy' => true]);
    $ctx = makeBatchChannel($this->user, $this->playlist, 'channel.proxied');

    putProgramme($ctx['epg'], Carbon::now()->format('Y-m-d'), 'channel.proxied', [
        'id' => 'proxied-1', 'title' => 'Proxied Show', 'desc' => '',
        'start' => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHour()->format('Y-m-d H:i:s'),
        'icon' => 'https://images.example/proxied.jpg',
    ]);

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => (string) $ctx['channel']->id,
        'details' => 1,
    ]))->assertOk();

    $listing = collect($response->json((string) $ctx['channel']->id.'.epg_listings'))
        ->first(fn ($p) => $p['id'] === 'proxied-1');

    expect($listing['icon'])->toBe(LogoProxyController::generateProxyUrl('https://images.example/proxied.jpg'));
});

it('prefers the alternative image closest to 16:9 for the guide artwork', function () {
    $this->playlist->update(['enable_logo_proxy' => false]);
    $ctx = makeBatchChannel($this->user, $this->playlist, 'channel.art');

    putProgramme($ctx['epg'], Carbon::now()->format('Y-m-d'), 'channel.art', [
        'id' => 'wide-1', 'title' => 'Wide Art', 'desc' => '',
        'start' => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHour()->format('Y-m-d H:i:s'),
        'icon' => 'https://images.example/square.jpg',
        'images' => [
            ['url' => 'https://images.example/poster.jpg', 'type' => 'poster', 'width' => 480, 'height' => 720, 'orient' => 'P', 'size' => 2],
            ['url' => 'https://images.example/four-three.jpg', 'type' => 'poster', 'width' => 720, 'height' => 540, 'orient' => 'L', 'size' => 2],
            ['url' => 'https://images.example/wide-small.jpg', 'type' => 'backdrop', 'width' => 480, 'height' => 270, 'orient' => 'L', 'size' => 1],
            ['url' => 'https://images.example/wide.jpg', 'type' => 'poster', 'width' => 960, 'height' => 540, 'orient' => 'L', 'size' => 2],
        ],
    ]);
    putProgramme($ctx['epg'], Carbon::now()->format('Y-m-d'), 'channel.art', [
        'id' => 'tall-1', 'title' => 'Tall Art', 'desc' => '',
        'start' => Carbon::now()->addHours(2)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHours(3)->format('Y-m-d H:i:s'),
        'icon' => 'https://images.example/primary.jpg',
        'images' => [
            ['url' => 'https://images.example/poster.jpg', 'type' => 'poster', 'width' => 480, 'height' => 720, 'orient' => 'P', 'size' => 2],
        ],
    ]);

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => (string) $ctx['channel']->id,
        'details' => 1,
    ]))->assertOk();

    $listings = collect($response->json((string) $ctx['channel']->id.'.epg_listings'))->keyBy('id');

    expect($listings['wide-1']['icon'])->toBe('https://images.example/wide.jpg')
        ->and($listings['tall-1']['icon'])->toBe('https://images.example/primary.jpg');
});

it('adds role-specific poster and backdrop artwork to detailed batch listings without changing the guide icon', function () {
    $this->playlist->update(['enable_logo_proxy' => false]);
    $ctx = makeBatchChannel($this->user, $this->playlist, 'channel.roles');

    putProgramme($ctx['epg'], Carbon::now()->format('Y-m-d'), 'channel.roles', [
        'id' => 'roles-1', 'title' => 'Role Art', 'desc' => '',
        'start' => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHour()->format('Y-m-d H:i:s'),
        'icon' => 'https://images.example/primary.jpg',
        'images' => [
            // Schedules Direct labels its 16:9 "Iconic" art `poster`; geometry wins.
            ['url' => 'https://images.example/iconic-wide.jpg', 'type' => 'poster', 'width' => 1920, 'height' => 1080, 'orient' => 'L', 'size' => 3],
            ['url' => 'https://images.example/small-poster.jpg', 'type' => 'poster', 'width' => 240, 'height' => 360, 'orient' => 'P', 'size' => 2],
            ['url' => 'https://images.example/big-poster.jpg', 'type' => 'poster', 'width' => 1000, 'height' => 1500, 'orient' => 'P', 'size' => 3],
            ['url' => 'https://images.example/headshot.jpg', 'type' => 'person', 'width' => 2000, 'height' => 3000, 'orient' => 'P', 'size' => 3],
        ],
    ]);
    putProgramme($ctx['epg'], Carbon::now()->format('Y-m-d'), 'channel.roles', [
        'id' => 'roles-2', 'title' => 'Landscape Only', 'desc' => '',
        'start' => Carbon::now()->addHours(2)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHours(3)->format('Y-m-d H:i:s'),
        'images' => [
            ['url' => 'https://images.example/mislabelled.jpg', 'type' => 'poster', 'width' => 1280, 'height' => 720, 'orient' => 'P', 'size' => 3],
        ],
    ]);

    putProgramme($ctx['epg'], Carbon::now()->format('Y-m-d'), 'channel.roles', [
        'id' => 'roles-3', 'title' => 'Orientless Poster', 'desc' => '',
        'start' => Carbon::now()->addHours(4)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHours(5)->format('Y-m-d H:i:s'),
        'images' => [
            // From <image type="poster" size="3">: XMLTV makes orient optional.
            ['url' => 'https://images.example/orientless.jpg', 'type' => 'poster', 'width' => 0, 'height' => 0, 'orient' => '', 'size' => 3],
            ['url' => 'https://images.example/untyped.jpg', 'type' => '', 'width' => 0, 'height' => 0, 'orient' => '', 'size' => 0],
        ],
    ]);

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => (string) $ctx['channel']->id,
        'details' => 1,
    ]))->assertOk();

    $listings = collect($response->json((string) $ctx['channel']->id.'.epg_listings'))->keyBy('id');

    expect($listings['roles-1'])->toMatchArray([
        'icon' => 'https://images.example/iconic-wide.jpg',
        'poster_url' => 'https://images.example/big-poster.jpg',
        'backdrop_url' => 'https://images.example/iconic-wide.jpg',
    ])
        ->and($listings['roles-2']['backdrop_url'])->toBe('https://images.example/mislabelled.jpg')
        ->and($listings['roles-2'])->not->toHaveKey('poster_url')
        ->and($listings['roles-3']['poster_url'])->toBe('https://images.example/orientless.jpg');
});

it('keeps programme artwork out of batch listings unless details=1', function () {
    $ctx = makeBatchChannel($this->user, $this->playlist, 'channel.roles-plain');

    putProgramme($ctx['epg'], Carbon::now()->format('Y-m-d'), 'channel.roles-plain', [
        'id' => 'roles-plain-1', 'title' => 'Plain', 'desc' => '',
        'start' => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHour()->format('Y-m-d H:i:s'),
        'images' => [
            ['url' => 'https://images.example/poster.jpg', 'type' => 'poster', 'width' => 1000, 'height' => 1500, 'orient' => 'P', 'size' => 3],
        ],
    ]);

    $response = $this->getJson(batchUrl($this->username, $this->password, [
        'stream_ids' => (string) $ctx['channel']->id,
    ]))->assertOk();

    expect($response->json((string) $ctx['channel']->id.'.epg_listings.0'))->not->toHaveKeys(['poster_url', 'backdrop_url']);
});

it('adds role-specific artwork to get_short_epg and get_simple_data_table through the logo proxy', function () {
    $this->playlist->update(['enable_logo_proxy' => true]);
    $ctx = makeBatchChannel($this->user, $this->playlist, 'channel.short');

    putProgramme($ctx['epg'], Carbon::now()->format('Y-m-d'), 'channel.short', [
        'id' => 'short-1', 'title' => 'Short Art', 'desc' => '',
        'start' => Carbon::now()->addMinutes(5)->format('Y-m-d H:i:s'),
        'stop' => Carbon::now()->addHour()->format('Y-m-d H:i:s'),
        'images' => [
            ['url' => 'https://images.example/poster.jpg', 'type' => 'poster', 'width' => 1000, 'height' => 1500, 'orient' => 'P', 'size' => 3],
            ['url' => 'https://images.example/backdrop.jpg', 'type' => 'backdrop', 'width' => 1920, 'height' => 1080, 'orient' => 'L', 'size' => 3],
        ],
    ]);

    foreach (['get_short_epg', 'get_simple_data_table'] as $action) {
        $response = $this->getJson(batchUrl($this->username, $this->password, [
            'action' => $action,
            'stream_id' => (string) $ctx['channel']->id,
        ]))->assertOk();

        expect($response->json('epg_listings.0'))->toMatchArray([
            'poster_url' => LogoProxyController::generateProxyUrl('https://images.example/poster.jpg'),
            'backdrop_url' => LogoProxyController::generateProxyUrl('https://images.example/backdrop.jpg'),
        ]);
    }
});
