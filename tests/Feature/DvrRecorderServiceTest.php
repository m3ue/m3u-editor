<?php

/**
 * Tests for DvrRecorderService
 *
 * Covers:
 * - When use_proxy = false, recording uses the channel's direct URL
 * - When use_proxy = true, recording uses the channel's proxy URL
 * - DvrSetting use_proxy defaults to false
 */

use App\Enums\DvrRecordingStatus;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Models\User;
use App\Services\DvrRecorderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Build a SCHEDULED DvrRecording with a real Channel attached.
 *
 * @param  array<string, mixed>  $settingOverrides
 * @param  array<string, mixed>  $channelOverrides
 */
function makeScheduledRecording(array $settingOverrides = [], array $channelOverrides = []): DvrRecording
{
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $setting = DvrSetting::factory()
        ->enabled()
        ->for($user)
        ->for($playlist)
        ->create($settingOverrides);

    $channel = Channel::factory()
        ->for($user)
        ->for($playlist)
        ->create(array_merge(['url' => 'http://direct.example.com/stream.ts'], $channelOverrides));

    return DvrRecording::factory()
        ->for($setting, 'dvrSetting')
        ->for($user)
        ->create([
            'status' => DvrRecordingStatus::Scheduled,
            'channel_id' => $channel->id,
            'stream_url' => null,
            'season' => null,
            'episode' => null,
            'metadata' => null,
        ]);
}

/**
 * Attempt to start a recording, swallowing any proc_open / ffmpeg failures
 * that are expected in a test environment (no real FFmpeg binary or stream).
 * The URL-selection logic runs and persists to the DB before proc_open is called.
 */
function attemptStart(DvrRecording $recording): void
{
    Storage::fake('dvr');
    config(['dvr.storage_disk' => 'dvr', 'dvr.ffmpeg_path' => '/nonexistent/ffmpeg']);

    try {
        app(DvrRecorderService::class)->start($recording);
    } catch (Exception) {
        // Expected — proc_open fails in test environment
    }
}

// ── URL selection ─────────────────────────────────────────────────────────────

it('uses the channel direct URL when use_proxy is false', function () {
    $recording = makeScheduledRecording(['use_proxy' => false]);

    attemptStart($recording);

    expect($recording->fresh()->stream_url)->toBe('http://direct.example.com/stream.ts');
});

it('uses the channel proxy URL when use_proxy is true', function () {
    $recording = makeScheduledRecording(['use_proxy' => true]);

    attemptStart($recording);

    $proxyUrl = $recording->fresh()->stream_url;

    // Proxy URL is built by Channel::getProxyUrl() — verify it is a proxy URL
    // rather than the direct stream URL, and contains the ?proxy=true marker.
    expect($proxyUrl)
        ->not->toBe('http://direct.example.com/stream.ts')
        ->toContain('proxy=true');
});

// ── use_proxy default ─────────────────────────────────────────────────────────

it('DvrSetting use_proxy defaults to false', function () {
    $setting = DvrSetting::factory()->create();

    expect($setting->use_proxy)->toBeFalse();
});

it('DvrSetting use_proxy can be set to true', function () {
    $setting = DvrSetting::factory()->create(['use_proxy' => true]);

    expect($setting->fresh()->use_proxy)->toBeTrue();
});
