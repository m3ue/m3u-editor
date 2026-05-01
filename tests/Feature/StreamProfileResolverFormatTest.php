<?php

/**
 * Tests for StreamProfile resolver backend format behaviour.
 *
 * Covers:
 * - StreamProfile::isResolver() identifies streamlink/ytdlp vs ffmpeg correctly
 * - Factory states produce resolver profiles with correct backend/format values
 * - Resolver profile format drives the URL extension in Channel::getProxyUrl()
 * - A resolver profile with mp4 format produces a .mp4 proxy URL
 * - A resolver profile with ts format produces a .ts proxy URL
 * - FFmpeg profile format is unchanged by the fix (regression guard)
 * - M3uProxyService::createTranscodedStream() sends the correct resolver payload
 */

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\StreamProfile;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'name' => 'testuser',
        'permissions' => ['use_proxy'],
    ]);
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => true,
        'xtream' => false,
        'uuid' => 'test-uuid',
    ]);
});

// ── StreamProfile::isResolver() ───────────────────────────────────────────────

test('isResolver returns false for ffmpeg backend', function () {
    $profile = StreamProfile::factory()->for($this->user)->create(['backend' => 'ffmpeg']);

    expect($profile->isResolver())->toBeFalse();
});

test('isResolver returns true for streamlink backend', function () {
    $profile = StreamProfile::factory()->for($this->user)->streamlink()->create();

    expect($profile->isResolver())->toBeTrue();
});

test('isResolver returns true for ytdlp backend', function () {
    $profile = StreamProfile::factory()->for($this->user)->ytdlp()->create();

    expect($profile->isResolver())->toBeTrue();
});

// ── Factory states ────────────────────────────────────────────────────────────

test('streamlink factory state sets correct backend and default format', function () {
    $profile = StreamProfile::factory()->for($this->user)->streamlink()->create();

    expect($profile->backend)->toBe('streamlink')
        ->and($profile->format)->toBe('ts')
        ->and($profile->args)->toBe('best');
});

test('streamlink factory state accepts custom quality and format', function () {
    $profile = StreamProfile::factory()->for($this->user)->streamlink('720p', 'mp4')->create();

    expect($profile->backend)->toBe('streamlink')
        ->and($profile->format)->toBe('mp4')
        ->and($profile->args)->toBe('720p');
});

test('ytdlp factory state sets correct backend and default format', function () {
    $profile = StreamProfile::factory()->for($this->user)->ytdlp()->create();

    expect($profile->backend)->toBe('ytdlp')
        ->and($profile->format)->toBe('ts')
        ->and($profile->args)->toBe('bestvideo+bestaudio/best');
});

test('ytdlp factory state accepts custom format selector and format', function () {
    $profile = StreamProfile::factory()->for($this->user)->ytdlp('best[height<=720]', 'mp4')->create();

    expect($profile->backend)->toBe('ytdlp')
        ->and($profile->format)->toBe('mp4')
        ->and($profile->args)->toBe('best[height<=720]');
});

// ── Resolver profile format drives Channel::getProxyUrl() ────────────────────

test('resolver profile with ts format produces ts extension in proxy URL', function () {
    $profile = StreamProfile::factory()->for($this->user)->streamlink('best', 'ts')->create();

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $proxyUrl = $channel->getProxyUrl(profileFormat: $profile->format);

    expect($proxyUrl)->toContain("/{$channel->id}.ts");
});

test('resolver profile with mp4 format produces mp4 extension in proxy URL', function () {
    $profile = StreamProfile::factory()->for($this->user)->streamlink('best', 'mp4')->create();

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $proxyUrl = $channel->getProxyUrl(profileFormat: $profile->format);

    expect($proxyUrl)->toContain("/{$channel->id}.mp4");
});

test('ytdlp resolver profile with mkv format produces mkv extension in proxy URL', function () {
    $profile = StreamProfile::factory()->for($this->user)->ytdlp('bestvideo+bestaudio/best', 'mkv')->create();

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $proxyUrl = $channel->getProxyUrl(profileFormat: $profile->format);

    expect($proxyUrl)->toContain("/{$channel->id}.mkv");
});

test('resolver profile format overrides the detected format from the channel URL', function () {
    // Channel URL has .ts extension — resolver profile says mp4 — mp4 should win
    $profile = StreamProfile::factory()->for($this->user)->streamlink('best', 'mp4')->create();

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/live/user/pass/123.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $proxyUrl = $channel->getProxyUrl(profileFormat: $profile->format);

    expect($proxyUrl)->toContain("/{$channel->id}.mp4")
        ->and($proxyUrl)->not->toContain("/{$channel->id}.ts");
});

// ── FFmpeg profile format regression ─────────────────────────────────────────

test('ffmpeg profile format is still applied to proxy URL (regression guard)', function () {
    $profile = StreamProfile::factory()->for($this->user)->create([
        'backend' => 'ffmpeg',
        'format' => 'm3u8',
    ]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $proxyUrl = $channel->getProxyUrl(profileFormat: $profile->format);

    expect($proxyUrl)->toContain("/{$channel->id}.m3u8");
});

test('no profile format uses format detected from channel URL', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $proxyUrl = $channel->getProxyUrl();

    expect($proxyUrl)->toContain("/{$channel->id}.ts");
});

// ── isResolver() unrecognised backend ────────────────────────────────────────

test('isResolver returns false for an unrecognised backend value', function () {
    $profile = StreamProfile::factory()->for($this->user)->create(['backend' => 'unknown_backend']);

    expect($profile->isResolver())->toBeFalse();
});

test('isResolver returns false for an empty backend', function () {
    $profile = StreamProfile::factory()->for($this->user)->create(['backend' => '']);

    expect($profile->isResolver())->toBeFalse();
});

test('isResolver returns false for a null backend', function () {
    // backend has a NOT NULL DB constraint, so test via an unsaved instance.
    $profile = new StreamProfile(['backend' => null]);

    expect($profile->isResolver())->toBeFalse();
});

// ── M3uProxyService resolver payload ─────────────────────────────────────────

/**
 * Call the protected createTranscodedStream() method via reflection so we can
 * assert on the exact HTTP payload it sends without changing its visibility.
 */
function callCreateTranscodedStream(M3uProxyService $service, string $url, StreamProfile $profile, array $metadata = []): void
{
    $method = new ReflectionMethod($service, 'createTranscodedStream');
    $method->invoke($service, $url, $profile, false, null, [], $metadata);
}

describe('M3uProxyService::createTranscodedStream() resolver payload', function () {
    beforeEach(function () {
        config(['proxy.m3u_proxy_host' => 'http://127.0.0.1:19999']);
        // Catch all outbound HTTP so we never hit a real host, then assert on what was sent.
        Http::fake(['*' => Http::response(['stream_id' => 'abc123'], 200)]);
    });

    test('streamlink profile sends resolver/resolver_args/cookies_path — not profile field', function () {
        $profile = StreamProfile::factory()->for($this->user)->streamlink('best', 'ts')->create([
            'cookies_path' => '/app/cookies/cookies.txt',
        ]);

        callCreateTranscodedStream(
            app(M3uProxyService::class),
            'http://provider.test/live.ts',
            $profile,
            ['channel_id' => '1']
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/transcode')
                && ($body['resolver'] ?? null) === 'streamlink'
                && ($body['resolver_args'] ?? null) === 'best'
                && ($body['cookies_path'] ?? null) === '/app/cookies/cookies.txt'
                && ! array_key_exists('profile', $body);
        });
    });

    test('ytdlp profile sends correct resolver and resolver_args', function () {
        $profile = StreamProfile::factory()->for($this->user)->ytdlp('bestvideo+bestaudio/best', 'ts')->create();

        callCreateTranscodedStream(
            app(M3uProxyService::class),
            'http://provider.test/live.ts',
            $profile,
            ['channel_id' => '1']
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/transcode')
                && ($body['resolver'] ?? null) === 'ytdlp'
                && ($body['resolver_args'] ?? null) === 'bestvideo+bestaudio/best'
                && ! array_key_exists('profile', $body);
        });
    });

    test('cookies_path field is null when profile has no cookies_path', function () {
        $profile = StreamProfile::factory()->for($this->user)->streamlink()->create(['cookies_path' => null]);

        callCreateTranscodedStream(
            app(M3uProxyService::class),
            'http://provider.test/live.ts',
            $profile,
            ['channel_id' => '1']
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/transcode')
                && array_key_exists('cookies_path', $body)
                && $body['cookies_path'] === null;
        });
    });

    test('ffmpeg profile sends profile field — not resolver fields', function () {
        $profile = StreamProfile::factory()->for($this->user)->create([
            'backend' => 'ffmpeg',
            'args' => '-c:v copy -c:a aac',
        ]);

        callCreateTranscodedStream(
            app(M3uProxyService::class),
            'http://provider.test/live.ts',
            $profile,
            ['channel_id' => '1']
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/transcode')
                && array_key_exists('profile', $body)
                && ! array_key_exists('resolver', $body)
                && ! array_key_exists('resolver_args', $body)
                && ! array_key_exists('cookies_path', $body);
        });
    });

    test('metadata is forwarded for both resolver and ffmpeg profiles', function () {
        $profile = StreamProfile::factory()->for($this->user)->streamlink()->create();

        callCreateTranscodedStream(
            app(M3uProxyService::class),
            'http://provider.test/live.ts',
            $profile,
            ['channel_id' => '42', 'playlist_uuid' => 'test-uuid']
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '/transcode')
                && ($body['metadata']['channel_id'] ?? null) === '42'
                && ($body['metadata']['playlist_uuid'] ?? null) === 'test-uuid';
        });
    });
});
