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
 */

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\StreamProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
