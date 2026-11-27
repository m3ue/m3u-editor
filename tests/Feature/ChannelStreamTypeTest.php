<?php

/**
 * Channel stream type: the provider's type (M3U `tvg-type` / Xtream `stream_type`) is stored
 * in `stream_type` on every sync, the user-set `tvg_type` overrides it, and both the M3U
 * (`tvg-type`) and Xtream API (`stream_type`) outputs honor them, each in its own vocabulary.
 */

use App\Jobs\ProcessM3uImport;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid('', true).'.sqlite';
    touch($this->tempJobsDb);
    config(['database.connections.jobs.database' => $this->tempJobsDb]);
    DB::purge('jobs');

    $migration = require database_path('migrations/2025_02_13_215803_create_jobs_table.php');
    $migration->up();
});

afterEach(function () {
    DB::purge('jobs');
    config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);

    if (isset($this->tempJobsDb) && file_exists($this->tempJobsDb)) {
        @unlink($this->tempJobsDb);
    }
    if (isset($this->tempM3uPath) && file_exists($this->tempM3uPath)) {
        @unlink($this->tempM3uPath);
    }
});

// ---------------------------------------------------------------------------
// Resolution
// ---------------------------------------------------------------------------

it('resolves the Xtream live stream_type from the override, then the provider, limited to live types', function (?string $tvgType, ?string $streamType, string $expected) {
    $channel = new Channel(['tvg_type' => $tvgType, 'stream_type' => $streamType]);

    expect($channel->resolveXtreamLiveStreamType())->toBe($expected);
})->with([
    'nothing set' => [null, null, 'live'],
    'blank values' => ['  ', '', 'live'],
    'provider value' => [null, 'Radio_Streams', 'radio_streams'],
    'override wins' => ['created_live', 'radio_streams', 'created_live'],
    // Players can build the playback URL from stream_type, so a live channel must never
    // report a VOD or series type.
    'movie override falls back' => ['movies', 'radio_streams', 'live'],
    'series provider falls back' => [null, 'tvshows', 'live'],
    'unknown value falls back' => [null, 'radio', 'live'],
]);

it('resolves the M3U tvg-type from the override, then the provider, then live/movies', function (?string $tvgType, ?string $streamType, bool $isVod, string $expected) {
    $channel = new Channel(['tvg_type' => $tvgType, 'stream_type' => $streamType, 'is_vod' => $isVod]);

    expect($channel->resolveM3uTvgType())->toBe($expected);
})->with([
    'live default' => [null, null, false, 'live'],
    'vod default' => [null, null, true, 'movies'],
    'xtream vod keeps movies' => [null, 'movie', true, 'movies'],
    'xtream series' => [null, 'series', false, 'tvshows'],
    'provider value' => [null, 'radio_streams', false, 'radio_streams'],
    'override wins' => ['live', 'movies', true, 'live'],
]);

// ---------------------------------------------------------------------------
// Import
// ---------------------------------------------------------------------------

it('stores the M3U tvg-type attribute as the provider stream_type', function () {
    $user = User::factory()->create();

    $this->tempM3uPath = sys_get_temp_dir().'/playlist_stream_type_'.uniqid().'.m3u';
    file_put_contents($this->tempM3uPath, implode("\n", [
        '#EXTM3U',
        '#EXTINF:-1 tvg-id="radio-1" tvg-type="radio_streams" group-title="Radio",Radio One',
        'http://example.test/stream/1',
        '#EXTINF:-1 tvg-id="news-1" group-title="News",News One',
        'http://example.test/stream/2',
        '#EXTINF:-1 tvg-id="long-1" tvg-type="'.str_repeat('x', 300).'" group-title="News",Long One',
        'http://example.test/stream/3',
    ]));

    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
        'url' => $this->tempM3uPath,
        'xtream' => false,
        'import_prefs' => [],
        'auto_sort' => false,
        'enable_channels' => true,
    ]));

    Bus::fake();
    (new ProcessM3uImport($playlist, force: true, isNew: true))->handle();

    $types = Job::all()->flatMap(fn (Job $job) => $job->payload)->pluck('stream_type', 'stream_id');

    expect($types->get('radio-1'))->toBe('radio_streams')
        ->and($types->get('news-1'))->toBeNull()
        ->and($types->get('long-1'))->toBe(str_repeat('x', 255));
});

it('stores the Xtream stream_type as the provider stream_type', function () {
    $user = User::factory()->create();

    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://xtream.example.test:8080',
            'username' => 'user',
            'password' => 'pass',
            'output' => 'ts',
            'import_options' => ['live'],
        ],
        'import_prefs' => [],
    ]));

    Http::fake([
        'http://xtream.example.test:8080/player_api.php*' => function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return match ($query['action'] ?? null) {
                'get_live_streams' => Http::response(json_encode([
                    ['stream_id' => 1, 'name' => 'Radio One', 'category_id' => '1', 'stream_type' => 'radio_streams'],
                    ['stream_id' => 2, 'name' => 'News One', 'category_id' => '1'],
                ]), 200),
                'get_live_categories' => Http::response(json_encode([
                    ['category_id' => '1', 'category_name' => 'Mixed'],
                ]), 200),
                default => Http::response(json_encode(['user_info' => ['status' => 'Active']]), 200),
            };
        },
    ]);

    Bus::fake();
    (new ProcessM3uImport($playlist, force: true, isNew: true))->handle();

    $types = Job::all()->flatMap(fn (Job $job) => $job->payload)->pluck('stream_type', 'source_id');

    expect($types->get(1))->toBe('radio_streams')
        ->and($types->get(2))->toBeNull();
});

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

/**
 * Creates an enabled channel on a fresh playlist and returns [user, playlist, channel].
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $playlistAttributes
 * @return array{0: User, 1: Playlist, 2: Channel}
 */
function streamTypeTestChannel(array $attributes, array $playlistAttributes = []): array
{
    Queue::fake();
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create($playlistAttributes);
    $isVod = (bool) ($attributes['is_vod'] ?? false);
    $group = Group::factory()->for($playlist)->for($user)->create(['type' => $isVod ? 'vod' : 'live']);

    $channel = Channel::factory()->for($user)->for($playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => $isVod,
        'title' => 'Test Channel',
        'url' => 'http://example.com/stream/1',
        ...$attributes,
    ]);

    return [$user, $playlist, $channel];
}

it('outputs the stored stream type from get_live_streams', function (?string $tvgType, ?string $streamType, string $expected) {
    [$user, $playlist] = streamTypeTestChannel(['tvg_type' => $tvgType, 'stream_type' => $streamType]);

    $streams = test()->getJson('/player_api.php?username='.urlencode($user->name).'&password='.urlencode($playlist->uuid).'&action=get_live_streams')
        ->assertOk()
        ->json();

    expect($streams[0]['stream_type'])->toBe($expected);
})->with([
    'unchanged default' => [null, null, 'live'],
    'provider value' => [null, 'radio_streams', 'radio_streams'],
    'override wins' => ['created_live', 'radio_streams', 'created_live'],
    'non-live override falls back' => ['movies', null, 'live'],
]);

it('always outputs movie from get_vod_streams', function (?string $tvgType, ?string $streamType, string $expected) {
    [$user, $playlist] = streamTypeTestChannel(['is_vod' => true, 'tvg_type' => $tvgType, 'stream_type' => $streamType]);

    $streams = test()->getJson('/player_api.php?username='.urlencode($user->name).'&password='.urlencode($playlist->uuid).'&action=get_vod_streams')
        ->assertOk()
        ->json();

    expect($streams[0]['stream_type'])->toBe($expected);
})->with([
    'unchanged default' => [null, null, 'movie'],
    'provider value' => [null, 'movie', 'movie'],
    'series override ignored' => ['tvshows', 'movie', 'movie'],
]);

it('outputs the M3U tvg-type from the override or provider when enabled', function () {
    [, $playlist] = streamTypeTestChannel(['stream_type' => 'radio_streams'], ['output_tvg_type' => true]);

    $content = test()->get("/{$playlist->uuid}/playlist.m3u")->assertOk()->streamedContent();

    expect($content)->toContain('tvg-type="radio_streams"');
});

it('keeps tvg-type out of the M3U when the playlist toggle is off', function () {
    [, $playlist] = streamTypeTestChannel(['stream_type' => 'radio_streams'], ['output_tvg_type' => false]);

    $content = test()->get("/{$playlist->uuid}/playlist.m3u")->assertOk()->streamedContent();

    expect($content)->not->toContain('tvg-type=');
});
