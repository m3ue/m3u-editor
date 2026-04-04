<?php

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    $this->user = User::factory()->create([
        'name' => 'testuser',
        'permissions' => ['use_proxy'],
    ]);

    $this->playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => false,
    ]);
});

it('inherits playlist proxy setting when channel override is null', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enable_proxy' => null,
    ]);

    expect($channel->shouldProxy($this->playlist))->toBeFalse();

    $this->playlist->update(['enable_proxy' => true]);
    $channel->refresh();

    expect($channel->shouldProxy($this->playlist->fresh()))->toBeTrue();
});

it('always proxies when channel override is true even if playlist proxy is off', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enable_proxy' => true,
    ]);

    expect($channel->shouldProxy($this->playlist))->toBeTrue();
});

it('never proxies when channel override is false even if playlist proxy is on', function () {
    $this->playlist->update(['enable_proxy' => true]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enable_proxy' => false,
    ]);

    expect($channel->shouldProxy($this->playlist->fresh()))->toBeFalse();
});

it('redirects directly for live stream when channel forces direct and playlist proxy is on', function () {
    $this->playlist->update(['enable_proxy' => true]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
        'enable_proxy' => false,
        'url' => 'http://provider.test/live/stream.ts',
        'is_vod' => false,
    ]);

    $response = $this->get(route('xtream.stream.live.root', [
        'username' => $this->user->name,
        'password' => $this->playlist->uuid,
        'streamId' => $channel->id,
        'format' => 'ts',
    ]));

    $response->assertRedirect('http://provider.test/live/stream.ts');
});

it('redirects to proxy output when channel forces proxy and playlist proxy is off', function () {
    config([
        'proxy.m3u_proxy_host' => 'http://localhost',
        'proxy.m3u_proxy_port' => 8765,
        'proxy.m3u_proxy_token' => 'test-token',
        'cache.default' => 'array',
    ]);

    Http::fake([
        '*/streams/by-metadata*' => Http::response([
            'matching_streams' => [],
            'total_matching' => 0,
            'total_clients' => 0,
        ], 200),
        'http://localhost:8765/streams' => Http::response([
            'stream_id' => 'stream-123',
        ], 200),
    ]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
        'enable_proxy' => true,
        'url' => 'http://provider.test/live/stream.ts',
        'is_vod' => false,
    ]);

    $response = $this->get(route('xtream.stream.live.root', [
        'username' => $this->user->name,
        'password' => $this->playlist->uuid,
        'streamId' => $channel->id,
        'format' => 'ts',
    ]));

    $response->assertRedirectContains('/m3u-proxy/stream/stream-123?username=testuser');
});
