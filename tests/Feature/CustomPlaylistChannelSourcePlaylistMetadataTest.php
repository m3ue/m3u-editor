<?php

/**
 * Regression coverage for GitHub issue #1525.
 *
 * When a channel has no true source Playlist (e.g. a "custom" channel created
 * directly under a CustomPlaylist rather than pulled in from a real M3U/Xtream
 * playlist), M3uProxyService::getChannelUrl() used to always send a
 * `source_playlist_uuid` metadata key, set to `null` in that case. The
 * m3u-proxy API's Pydantic metadata validator rejects `null` values outright
 * ("metadata value for 'source_playlist_uuid' must be string, int, float, or
 * bool"), so every stream request for such a channel failed with a 422 - even
 * though a missing source playlist is a perfectly valid, expected state.
 *
 * The fix: only add `source_playlist_uuid` to the metadata payload when the
 * channel actually has a source Playlist, mirroring how `provider_profile_id`
 * and `playlist_auth_id` are already conditionally added.
 */

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

test('source_playlist_uuid is omitted from stream metadata for a channel with no source playlist', function () {
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create(['enable_proxy' => true, 'available_streams' => 0]);

    // A "custom" channel created directly under the CustomPlaylist - no source
    // Playlist, so playlist_id is null and custom_playlist_id is set instead.
    $channel = Channel::factory()
        ->for($this->user)
        ->for($customPlaylist, 'customPlaylist')
        ->create([
            'playlist_id' => null,
            'enabled' => true,
            'is_custom' => true,
            'url' => 'http://example.com/custom-channel',
        ]);

    expect($channel->playlist)->toBeNull();

    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 0]),
        '*/streams' => function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['streams' => [], 'total' => 0]);
            }

            return Http::response(['stream_id' => 'new-stream-id']);
        },
    ]);

    $url = app(M3uProxyService::class)->getChannelUrl($customPlaylist, $channel);

    expect($url)->toBeString()->not->toBeEmpty();

    Http::assertSent(function ($request) {
        if ($request->method() !== 'POST' || ! str_ends_with(rtrim((string) $request->url(), '/'), '/streams')) {
            return true;
        }

        $metadata = $request->data()['metadata'] ?? [];

        return ! array_key_exists('source_playlist_uuid', $metadata);
    });
});

test('source_playlist_uuid is included in stream metadata for a channel with a real source playlist', function () {
    $sourcePlaylist = Playlist::factory()->for($this->user)->create(['enable_proxy' => true, 'available_streams' => 0]);
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create(['enable_proxy' => true, 'available_streams' => 0]);

    $channel = Channel::factory()->for($this->user)->for($sourcePlaylist)->create([
        'enabled' => true,
        'url' => 'http://example.com/source-channel',
    ]);
    $customPlaylist->channels()->attach($channel->id);

    Http::fake([
        '*/streams/by-metadata*' => Http::response(['matching_streams' => [], 'total_matching' => 0]),
        '*/streams' => function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['streams' => [], 'total' => 0]);
            }

            return Http::response(['stream_id' => 'new-stream-id']);
        },
    ]);

    $url = app(M3uProxyService::class)->getChannelUrl($customPlaylist, $channel);

    expect($url)->toBeString()->not->toBeEmpty();

    Http::assertSent(function ($request) use ($sourcePlaylist) {
        if ($request->method() !== 'POST' || ! str_ends_with(rtrim((string) $request->url(), '/'), '/streams')) {
            return true;
        }

        $metadata = $request->data()['metadata'] ?? [];

        return ($metadata['source_playlist_uuid'] ?? null) === $sourcePlaylist->uuid;
    });
});
