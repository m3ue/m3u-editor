<?php

/**
 * Catchup players request the timeshift URL again for every seek (one request per Range
 * probe). FFmpeg-based players (mpv) do so because they restart each seek from the original
 * URL unless the redirect is cacheable; ExoPlayer never caches redirects at all.
 *
 * - Timeshift redirects are cacheable, so mpv sends its probes straight to the target.
 * - With the proxy, the same client gets its own running stream back for the same programme
 *   window instead of a fresh capacity check and provider profile selection per probe.
 * - Live pool reuse never hands out a catchup stream.
 */

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    config(['proxy.m3u_proxy_host' => 'http://localhost', 'proxy.m3u_proxy_port' => 8765]);
    config(['proxy.m3u_proxy_token' => 'test-token']);
    config(['cache.default' => 'array']);

    $this->user = User::factory()->create(['permissions' => ['use_proxy']]);
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => false,
        'profiles_enabled' => false,
        'available_streams' => 0,
        'xtream' => false,
    ]);
    $this->channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
        'url' => 'https://provider.domain/live/user/pass/464938.ts',
        'catchup' => '1',
    ]);

    $playlistAuth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => 'catchup_user',
        'password' => 'catchup_pass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($playlistAuth);

    $this->timeshiftUrl = fn (array $query = [], string $date = '2026-10-01:20-00-00') => route('xtream.stream.timeshift.root', [
        'username' => 'catchup_user',
        'password' => 'catchup_pass',
        'duration' => 60,
        'date' => $date,
        'streamId' => $this->channel->id,
        'format' => 'ts',
        ...$query,
    ]);

    $this->fakeProxy = function (bool $streamStillRunning = true): void {
        Http::fake([
            '*/streams/by-metadata*' => Http::response(['matching_streams' => []]),
            '*/streams/catchup-stream' => $streamStillRunning
                ? Http::response(['client_count' => 1])
                : Http::response(['detail' => 'Stream not found'], 404),
            '*/streams' => Http::response(['stream_id' => 'catchup-stream']),
        ]);
    };

    $this->createdStreams = fn () => Http::recorded(
        fn (ClientRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/streams')
    )->count();

    Redis::shouldReceive('exists')->andReturn(0);
});

it('makes a direct timeshift redirect cacheable', function () {
    $response = $this->get(($this->timeshiftUrl)());

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/timeshift/')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=3600')->toContain('private');
});

it('makes a direct utc catchup redirect cacheable but leaves live redirects uncacheable', function () {
    $liveUrl = route('xtream.stream.live.root', [
        'username' => 'catchup_user',
        'password' => 'catchup_pass',
        'streamId' => $this->channel->id,
        'format' => 'ts',
    ]);

    $catchup = $this->get($liveUrl.'?utc='.(time() - 3600).'&lutc='.time());
    $live = $this->get($liveUrl);

    expect($catchup->headers->get('Cache-Control'))->toContain('max-age=3600')
        ->and($live->headers->get('Cache-Control'))->not->toContain('max-age');
});

it('hands a proxied catchup client its running stream back on every seek', function () {
    $this->playlist->update(['enable_proxy' => true]);
    ($this->fakeProxy)();

    $first = $this->get(($this->timeshiftUrl)());
    $seek = $this->get(($this->timeshiftUrl)());

    expect($first->headers->get('Location'))->toContain('stream/catchup-stream')
        ->and($seek->headers->get('Location'))->toBe($first->headers->get('Location'))
        ->and($seek->headers->get('Cache-Control'))->toContain('max-age='.M3uProxyService::TIMESHIFT_REDIRECT_MAX_AGE)->toContain('private')
        ->and(($this->createdStreams)())->toBe(1);

    Http::assertSent(fn (ClientRequest $request) => $request->method() === 'POST'
        && ($request['metadata']['timeshift'] ?? null) === 'true');
});

it('resolves a new proxy stream once the remembered one has stopped', function () {
    $this->playlist->update(['enable_proxy' => true]);
    ($this->fakeProxy)(streamStillRunning: false);

    $this->get(($this->timeshiftUrl)());
    $this->get(($this->timeshiftUrl)());

    expect(($this->createdStreams)())->toBe(2);
});

it('does not share a catchup session between clients or programme windows', function () {
    $this->playlist->update(['enable_proxy' => true]);
    ($this->fakeProxy)();

    $this->get(($this->timeshiftUrl)(['client_id' => 'tv-a']));
    $this->get(($this->timeshiftUrl)(['client_id' => 'tv-b']));
    $this->get(($this->timeshiftUrl)(['client_id' => 'tv-a'], '2026-10-01:21-00-00'));
    $this->get(($this->timeshiftUrl)(['client_id' => 'tv-a']));

    expect(($this->createdStreams)())->toBe(3);
});

it('never reuses a running catchup stream for a live request on the same channel', function () {
    $this->playlist->update(['enable_proxy' => true]);

    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [[
            'stream_id' => 'catchup-stream',
            'client_count' => 1,
            'metadata' => [
                'original_channel_id' => (string) $this->channel->id,
                'original_playlist_uuid' => $this->playlist->uuid,
                'transcoding' => 'false',
                'timeshift' => 'true',
            ],
        ]]]),
        '*/streams' => Http::response(['stream_id' => 'live-stream']),
    ]);

    $url = app(M3uProxyService::class)->getChannelUrl($this->playlist, $this->channel);

    expect($url)->toContain('stream/live-stream');
});
