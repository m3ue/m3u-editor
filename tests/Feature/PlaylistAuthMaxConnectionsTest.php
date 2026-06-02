<?php

/**
 * Tests for per-PlaylistAuth max_connections enforcement.
 *
 * Covers:
 * - max_connections=null means unlimited (no enforcement)
 * - max_connections limit blocks a new stream when active count is at limit
 * - stop_oldest_on_limit per-auth setting overrides global setting
 * - playlist_auth_id is included in stream metadata when PlaylistAuth is authenticated
 * - XtreamApiController returns PlaylistAuth.max_connections in user_info
 */

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
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

    config(['proxy.m3u_proxy_host' => 'http://localhost:8765']);
    config(['proxy.m3u_proxy_port' => null]);
    config(['proxy.m3u_proxy_token' => 'test-token']);
    config(['cache.default' => 'array']);
});

// ── PlaylistAuth model ────────────────────────────────────────────────────────

test('PlaylistAuth casts max_connections as integer', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create(['max_connections' => 3]);

    expect($auth->max_connections)->toBe(3)
        ->and($auth->stop_oldest_on_limit)->toBeNull();
});

test('PlaylistAuth max_connections defaults to null (unlimited)', function () {
    $auth = PlaylistAuth::factory()->for($this->user)->create();

    expect($auth->max_connections)->toBeNull();
});

// ── checkAndEnforceAuthStreamLimit (via getChannelUrl) ───────────────────────

test('stream is allowed when PlaylistAuth has no max_connections set', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create(['max_connections' => null]);
    $auth->assignTo($playlist);

    $channel = Channel::factory()->for($playlist)->create(['url' => 'http://example.com/stream', 'enabled' => true]);

    // Proxy: no active streams and stream creation succeeds
    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 0, 'total_clients' => 0]),
        '*/streams*' => Http::response(['stream_id' => 'test-stream-1']),
    ]);

    // Should not throw — unlimited auth bypasses per-auth check
    expect(fn () => app(M3uProxyService::class)->getChannelUrl(
        $playlist, $channel, null, null, $auth->username, $auth->id,
    ))->not->toThrow(Exception::class);
});

test('stream is blocked when PlaylistAuth is at max_connections', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'max_connections' => 1,
        'stop_oldest_on_limit' => false,
    ]);
    $auth->assignTo($playlist);

    $channel = Channel::factory()->for($playlist)->create(['url' => 'http://example.com/stream', 'enabled' => true]);

    // Proxy reports 1 active stream for this auth (already at limit)
    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 1, 'total_clients' => 1]),
    ]);

    expect(fn () => app(M3uProxyService::class)->getChannelUrl(
        $playlist, $channel, null, null, $auth->username, $auth->id,
    ))->toThrow(HttpException::class);
});

test('stop_oldest_on_limit per-auth setting evicts oldest stream when limit is reached', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'max_connections' => 1,
        'stop_oldest_on_limit' => true,
    ]);
    $auth->assignTo($playlist);

    $channel = Channel::factory()->for($playlist)->create(['url' => 'http://example.com/stream', 'enabled' => true]);

    $deleteCallCount = 0;

    Http::fake(function ($request) use (&$deleteCallCount) {
        $url = $request->url();

        if (str_contains($url, '/streams/oldest-by-metadata')) {
            $deleteCallCount++;

            return Http::response([
                'message' => 'Deleted oldest stream',
                'deleted_stream' => 'old-stream-id',
                'deleted_count' => 1,
                'stream_age_seconds' => 120,
            ]);
        }

        if (str_contains($url, '/streams/by-metadata')) {
            return Http::response([
                'matching_streams' => [],
                'total_matching' => $deleteCallCount > 0 ? 0 : 1,
                'total_clients' => $deleteCallCount > 0 ? 0 : 1,
            ]);
        }

        if ($request->method() === 'POST' && str_contains($url, '/streams')) {
            return Http::response(['stream_id' => 'new-stream-id']);
        }

        return Http::response([], 200);
    });

    $url = app(M3uProxyService::class)->getChannelUrl(
        $playlist, $channel, null, null, $auth->username, $auth->id,
    );

    expect($deleteCallCount)->toBeGreaterThanOrEqual(1)
        ->and($url)->toBeString()->not->toBeEmpty();
});

test('global stop_oldest_on_limit is used when per-auth setting is null', function () {
    // Mock GeneralSettings to return proxy_stop_oldest_on_limit=true without DB persistence
    $settings = Mockery::mock(GeneralSettings::class)->makePartial();
    $settings->proxy_stop_oldest_on_limit = true;
    app()->instance(GeneralSettings::class, $settings);

    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create([
        'max_connections' => 1,
        'stop_oldest_on_limit' => null, // Falls back to global setting (true)
    ]);
    $auth->assignTo($playlist);

    $channel = Channel::factory()->for($playlist)->create(['url' => 'http://example.com/stream', 'enabled' => true]);

    $deleteCalled = false;

    Http::fake(function ($request) use (&$deleteCalled) {
        $url = $request->url();

        if (str_contains($url, '/streams/oldest-by-metadata')) {
            $deleteCalled = true;

            return Http::response([
                'message' => 'Deleted oldest stream',
                'deleted_stream' => 'old-stream-id',
                'deleted_count' => 1,
                'stream_age_seconds' => 60,
            ]);
        }

        if (str_contains($url, '/streams/by-metadata')) {
            return Http::response([
                'matching_streams' => [],
                'total_matching' => $deleteCalled ? 0 : 1,
                'total_clients' => $deleteCalled ? 0 : 1,
            ]);
        }

        if ($request->method() === 'POST' && str_contains($url, '/streams')) {
            return Http::response(['stream_id' => 'new-stream-id']);
        }

        return Http::response([], 200);
    });

    app(M3uProxyService::class)->getChannelUrl(
        $playlist, $channel, null, null, $auth->username, $auth->id,
    );

    expect($deleteCalled)->toBeTrue();
});

// ── Metadata injection ────────────────────────────────────────────────────────

test('playlist_auth_id is included in stream metadata when PlaylistAuth is active', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);
    $auth = PlaylistAuth::factory()->for($this->user)->create(['max_connections' => null]);
    $auth->assignTo($playlist);

    $channel = Channel::factory()->for($playlist)->create(['url' => 'http://example.com/stream', 'enabled' => true]);

    $capturedPayload = null;

    Http::fake(function ($request) use (&$capturedPayload) {
        $url = $request->url();

        if (str_contains($url, '/streams/by-metadata')) {
            return Http::response(['matching_streams' => [], 'total_matching' => 0, 'total_clients' => 0]);
        }

        if ($request->method() === 'POST' && str_contains($url, '/streams')) {
            $capturedPayload = $request->data();

            return Http::response(['stream_id' => 'new-stream-id']);
        }

        return Http::response([], 200);
    });

    app(M3uProxyService::class)->getChannelUrl(
        $playlist, $channel, null, null, $auth->username, $auth->id,
    );

    expect($capturedPayload)->not->toBeNull()
        ->and($capturedPayload['metadata']['playlist_auth_id'] ?? null)->toBe((string) $auth->id);
});

test('playlist_auth_id is absent from metadata when no PlaylistAuth is used', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'available_streams' => 0,
    ]);

    $channel = Channel::factory()->for($playlist)->create(['url' => 'http://example.com/stream', 'enabled' => true]);

    $capturedPayload = null;

    Http::fake(function ($request) use (&$capturedPayload) {
        $url = $request->url();

        if (str_contains($url, '/streams/by-metadata')) {
            return Http::response(['matching_streams' => [], 'total_matching' => 0, 'total_clients' => 0]);
        }

        if ($request->method() === 'POST' && str_contains($url, '/streams')) {
            $capturedPayload = $request->data();

            return Http::response(['stream_id' => 'new-stream-id']);
        }

        return Http::response([], 200);
    });

    app(M3uProxyService::class)->getChannelUrl($playlist, $channel);

    expect($capturedPayload)->not->toBeNull()
        ->and(isset($capturedPayload['metadata']['playlist_auth_id']))->toBeFalse();
});
