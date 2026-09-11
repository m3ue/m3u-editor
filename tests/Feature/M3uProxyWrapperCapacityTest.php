<?php

/**
 * Regression coverage for wrapper-aware DVR capacity accounting in
 * M3uProxyService::getChannelUrl().
 *
 * Before this fix, only ONE playlist's available_streams was ever enforced —
 * whichever of the wrapper (CustomPlaylist/MergedPlaylist) or its source
 * Playlist happened to be picked as authoritative — so the other one's
 * configured limit was silently dropped. It also didn't count active DVR
 * recordings against the limit, and "stop oldest on limit" eviction had no
 * way to avoid stopping a recording-backed stream.
 *
 * Locks in:
 *   1. The source Playlist's own cap (e.g. a hardware tuner pool) is enforced
 *      even when accessed through an unlimited wrapper.
 *   2. The wrapper's own cap is enforced even when its source Playlist is
 *      unlimited.
 *   3. An active DVR recording counts toward the limit and is never evicted
 *      by "stop oldest on limit" — a live viewer stream is evicted instead.
 */

use App\Enums\DvrRecordingStatus;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Episode;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\M3uProxyService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();

    config([
        'proxy.m3u_proxy_host' => 'http://localhost',
        'proxy.m3u_proxy_port' => 8765,
        'proxy.m3u_proxy_token' => 'test-token',
        'cache.default' => 'array',
    ]);
});

test('the source playlist cap is enforced through an unlimited merged wrapper', function () {
    $source = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 1,
    ]);
    $merged = MergedPlaylist::factory()->for($this->user)->create(['available_streams' => 0]);
    $merged->playlists()->attach($source->id);

    $channelA = Channel::factory()->for($source)->create(['enabled' => true, 'url' => 'http://example.com/a']);
    $channelB = Channel::factory()->for($source)->create(['enabled' => true, 'url' => 'http://example.com/b']);

    // Channel A is already streaming through the wrapper — tagged with
    // source_playlist_uuid = the source playlist's uuid, not playlist_uuid.
    Http::fake([
        '*/streams/by-metadata*' => function ($request) use ($source, $channelA) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['field'] ?? null) === 'source_playlist_uuid' && ($query['value'] ?? null) === $source->uuid) {
                return Http::response([
                    'matching_streams' => [['stream_id' => 'live-a', 'metadata' => ['channel_id' => (string) $channelA->id]]],
                    'total_matching' => 1,
                ]);
            }

            return Http::response(['matching_streams' => [], 'total_matching' => 0]);
        },
    ]);

    expect(fn () => app(M3uProxyService::class)->getChannelUrl($merged, $channelB))
        ->toThrow(HttpException::class);
});

test('the wrapper playlist cap is enforced when its source playlist is unlimited', function () {
    $source = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $merged = MergedPlaylist::factory()->for($this->user)->create(['available_streams' => 1]);
    $merged->playlists()->attach($source->id);

    $channelA = Channel::factory()->for($source)->create(['enabled' => true, 'url' => 'http://example.com/a']);
    $channelB = Channel::factory()->for($source)->create(['enabled' => true, 'url' => 'http://example.com/b']);

    // Channel A is already streaming directly through the wrapper.
    Http::fake([
        '*/streams/by-metadata*' => function ($request) use ($merged, $channelA) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['field'] ?? null) === 'playlist_uuid' && ($query['value'] ?? null) === $merged->uuid) {
                return Http::response([
                    'matching_streams' => [['stream_id' => 'live-a', 'metadata' => ['channel_id' => (string) $channelA->id]]],
                    'total_matching' => 1,
                ]);
            }

            return Http::response(['matching_streams' => [], 'total_matching' => 0]);
        },
    ]);

    expect(fn () => app(M3uProxyService::class)->getChannelUrl($merged, $channelB))
        ->toThrow(HttpException::class);
});

test('an active DVR recording counts toward capacity and is never evicted — a live viewer is evicted instead', function () {
    // Mock GeneralSettings so "stop oldest on limit" is enabled without DB persistence.
    $settings = Mockery::mock(GeneralSettings::class)->makePartial();
    $settings->proxy_stop_oldest_on_limit = true;
    app()->instance(GeneralSettings::class, $settings);

    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 2,
    ]);
    $dvrSetting = DvrSetting::factory()->enabled()->for($this->user)->for($playlist)->create();

    $channelLive = Channel::factory()->for($playlist)->create(['enabled' => true, 'url' => 'http://example.com/live']);
    $channelRecording = Channel::factory()->for($playlist)->create(['enabled' => true, 'url' => 'http://example.com/rec']);
    $channelNew = Channel::factory()->for($playlist)->create(['enabled' => true, 'url' => 'http://example.com/new']);

    DvrRecording::factory()
        ->for($dvrSetting, 'dvrSetting')
        ->for($this->user)
        ->for($channelRecording)
        ->create(['status' => DvrRecordingStatus::Recording]);

    $deletedStreamId = null;

    Http::fake([
        '*/streams/by-metadata*' => function ($request) use ($playlist, $channelLive, &$deletedStreamId) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['field'] ?? null) === 'playlist_uuid' && ($query['value'] ?? null) === $playlist->uuid) {
                return Http::response([
                    'matching_streams' => $deletedStreamId ? [] : [['stream_id' => 'live-a', 'metadata' => ['channel_id' => (string) $channelLive->id]]],
                    'total_matching' => $deletedStreamId ? 0 : 1,
                ]);
            }

            return Http::response(['matching_streams' => [], 'total_matching' => 0]);
        },
        '*/streams/*' => function ($request) use (&$deletedStreamId) {
            if ($request->method() === 'DELETE') {
                $deletedStreamId = (string) collect(explode('/', (string) parse_url($request->url(), PHP_URL_PATH)))->last();

                return Http::response([], 200);
            }

            return Http::response([], 200);
        },
        '*/streams' => function ($request) use ($playlist, $channelLive, $channelRecording) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'streams' => [
                        [
                            'stream_id' => 'live-a',
                            'created_at' => now()->subMinutes(5)->toIso8601String(),
                            'is_active' => true,
                            'client_count' => 1,
                            'metadata' => ['channel_id' => (string) $channelLive->id, 'playlist_uuid' => $playlist->uuid],
                        ],
                        // A recording-backed stream — must never be picked as an eviction candidate.
                        [
                            'stream_id' => 'recording-stream',
                            'created_at' => now()->subMinutes(30)->toIso8601String(),
                            'is_active' => true,
                            'client_count' => 1,
                            'metadata' => ['channel_id' => (string) $channelRecording->id, 'playlist_uuid' => $playlist->uuid],
                        ],
                    ],
                    'total' => 2,
                ]);
            }

            // POST create
            return Http::response(['stream_id' => 'new-stream-id']);
        },
    ]);

    $url = app(M3uProxyService::class)->getChannelUrl($playlist, $channelNew);

    expect($deletedStreamId)->toBe('live-a')
        ->and($url)->toBeString()->not->toBeEmpty();
});

test('the source playlist cap is enforced through an unlimited merged wrapper for episode streaming', function () {
    $source = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 1,
    ]);
    $merged = MergedPlaylist::factory()->for($this->user)->create(['available_streams' => 0]);
    $merged->playlists()->attach($source->id);

    $series = Series::factory()->for($this->user)->for($source)->create();
    $episodeA = Episode::factory()->for($this->user)->for($source)->for($series)->create(['url' => 'http://example.com/a.mkv']);
    $episodeB = Episode::factory()->for($this->user)->for($source)->for($series)->create(['url' => 'http://example.com/b.mkv']);

    // Episode A is already streaming through the wrapper — tagged with
    // source_playlist_uuid = the source playlist's uuid, not playlist_uuid.
    Http::fake([
        '*/streams/by-metadata*' => function ($request) use ($source, $episodeA) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['field'] ?? null) === 'source_playlist_uuid' && ($query['value'] ?? null) === $source->uuid) {
                return Http::response([
                    'matching_streams' => [['stream_id' => 'live-a', 'metadata' => ['type' => 'episode', 'episode_id' => (string) $episodeA->id]]],
                    'total_matching' => 1,
                ]);
            }

            return Http::response(['matching_streams' => [], 'total_matching' => 0]);
        },
    ]);

    expect(fn () => app(M3uProxyService::class)->getEpisodeUrl($merged, $episodeB))
        ->toThrow(HttpException::class);
});

test('the wrapper playlist cap is enforced for episode streaming when its source playlist is unlimited', function () {
    $source = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $merged = MergedPlaylist::factory()->for($this->user)->create(['available_streams' => 1]);
    $merged->playlists()->attach($source->id);

    $series = Series::factory()->for($this->user)->for($source)->create();
    $episodeA = Episode::factory()->for($this->user)->for($source)->for($series)->create(['url' => 'http://example.com/a.mkv']);
    $episodeB = Episode::factory()->for($this->user)->for($source)->for($series)->create(['url' => 'http://example.com/b.mkv']);

    // Episode A is already streaming directly through the wrapper.
    Http::fake([
        '*/streams/by-metadata*' => function ($request) use ($merged, $episodeA) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['field'] ?? null) === 'playlist_uuid' && ($query['value'] ?? null) === $merged->uuid) {
                return Http::response([
                    'matching_streams' => [['stream_id' => 'live-a', 'metadata' => ['type' => 'episode', 'episode_id' => (string) $episodeA->id]]],
                    'total_matching' => 1,
                ]);
            }

            return Http::response(['matching_streams' => [], 'total_matching' => 0]);
        },
    ]);

    expect(fn () => app(M3uProxyService::class)->getEpisodeUrl($merged, $episodeB))
        ->toThrow(HttpException::class);
});
