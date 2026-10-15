<?php

use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Playlist;
use App\Services\M3uProxyService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        '*/broadcast/*/start' => Http::response(['status' => 'started', 'ffmpeg_pid' => 12345], 200),
        '*' => Http::response([], 200),
    ]);
});

/**
 * Call startDvrBroadcast for a recording whose DvrSetting has the given
 * transcode_recordings value, and capture the payload sent to the proxy.
 */
function captureDvrBroadcastPayload(bool $transcodeRecordings): array
{
    $playlist = Playlist::factory()->create();
    $setting = DvrSetting::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'enabled' => true,
        'use_proxy' => true,
        'transcode_recordings' => $transcodeRecordings,
    ]);

    $recording = DvrRecording::factory()->create([
        'dvr_setting_id' => $setting->id,
        'user_id' => $playlist->user_id,
        'scheduled_start' => now(),
        'scheduled_end' => now()->addHour(),
    ]);

    app(M3uProxyService::class)->startDvrBroadcast($recording, $setting, 'http://example.com/stream.ts');

    $captured = [];
    Http::assertSent(function ($request) use (&$captured) {
        if (str_contains($request->url(), '/broadcast/') && str_contains($request->url(), '/start')) {
            $captured = $request->data();

            return true;
        }

        return false;
    });

    return $captured;
}

it('sends transcode and deinterlace false when transcode_recordings is off', function () {
    $payload = captureDvrBroadcastPayload(false);

    expect($payload)
        ->toHaveKey('transcode', false)
        ->toHaveKey('deinterlace', false)
        ->toHaveKey('dvr_mode', true);
});

it('sends transcode and deinterlace true when transcode_recordings is on', function () {
    $payload = captureDvrBroadcastPayload(true);

    expect($payload)
        ->toHaveKey('transcode', true)
        ->toHaveKey('deinterlace', true)
        ->toHaveKey('dvr_mode', true);
});

it('records only what is left of the airing window when started late or resumed', function () {
    $playlist = Playlist::factory()->create();
    $setting = DvrSetting::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $playlist->user_id,
        'enabled' => true,
        'default_end_late_seconds' => 0,
    ]);
    $recording = DvrRecording::factory()->create([
        'dvr_setting_id' => $setting->id,
        'user_id' => $playlist->user_id,
        'scheduled_start' => now()->subMinutes(20),
        'scheduled_end' => now()->addMinutes(40),
    ]);

    app(M3uProxyService::class)->startDvrBroadcast($recording, $setting, 'http://example.com/stream.ts');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/start')
        && abs($request->data()['duration_seconds'] - 2400) <= 2);
});
