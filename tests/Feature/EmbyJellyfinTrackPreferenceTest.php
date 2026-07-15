<?php

use App\Models\MediaServerIntegration;
use App\Services\EmbyJellyfinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

function makeEmbyTrackPreferenceService(): EmbyJellyfinService
{
    return EmbyJellyfinService::make(new MediaServerIntegration([
        'type' => 'emby',
        'host' => 'emby.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'emby-token',
    ]));
}

function fakeEmbyItemWithStreams(): void
{
    Http::fake([
        'http://emby.local:8096/Items/item-1*' => Http::response([
            'MediaSources' => [[
                'MediaStreams' => [
                    [
                        'Index' => 0,
                        'Type' => 'Video',
                        'Language' => 'und',
                        'DisplayTitle' => 'H.264 1080p',
                    ],
                    [
                        'Index' => 1,
                        'Type' => 'Audio',
                        'Language' => 'eng',
                        'DisplayTitle' => 'English AAC 2.0',
                    ],
                    [
                        'Index' => 2,
                        'Type' => 'Audio',
                        'Language' => 'jpn',
                        'DisplayTitle' => 'Japanese AAC 2.0',
                    ],
                    [
                        'Index' => 3,
                        'Type' => 'Subtitle',
                        'Language' => 'eng',
                        'DisplayTitle' => 'English (Forced)',
                    ],
                ],
            ]],
        ], 200),
    ]);
}

it('resolves preferred Emby audio and subtitle tracks by language code', function () {
    fakeEmbyItemWithStreams();

    $request = new Request;
    $request->merge([
        'PreferredAudioTrack' => 'jpn',
        'PreferredSubtitleTrack' => 'eng',
    ]);

    $url = makeEmbyTrackPreferenceService()->getDirectStreamUrl($request, 'item-1');

    expect($url)->toContain('AudioStreamIndex=2')
        ->and($url)->toContain('SubtitleStreamIndex=3');
});

it('allows exact stream indexes as Emby track preferences', function () {
    fakeEmbyItemWithStreams();

    $request = new Request;
    $request->merge([
        'PreferredAudioTrack' => '2',
        'PreferredSubtitleTrack' => '3',
    ]);

    $url = makeEmbyTrackPreferenceService()->getDirectStreamUrl($request, 'item-1');

    expect($url)->toContain('AudioStreamIndex=2')
        ->and($url)->toContain('SubtitleStreamIndex=3');
});

it('omits stream indexes when Emby language code does not match any stream', function () {
    fakeEmbyItemWithStreams();

    $request = new Request;
    $request->merge([
        'PreferredAudioTrack' => 'fra',
        'PreferredSubtitleTrack' => 'deu',
    ]);

    $url = makeEmbyTrackPreferenceService()->getDirectStreamUrl($request, 'item-1');

    expect($url)->not->toContain('AudioStreamIndex')
        ->and($url)->not->toContain('SubtitleStreamIndex');
});

it('ignores whitespace-only Emby track preferences', function () {
    fakeEmbyItemWithStreams();

    $request = new Request;
    $request->merge([
        'PreferredAudioTrack' => '   ',
        'PreferredSubtitleTrack' => "\t",
    ]);

    $url = makeEmbyTrackPreferenceService()->getDirectStreamUrl($request, 'item-1');

    expect($url)->not->toContain('AudioStreamIndex')
        ->and($url)->not->toContain('SubtitleStreamIndex');
});

it('does not fetch Emby metadata when no track preferences are set', function () {
    Http::fake();

    $request = new Request;
    $request->merge(['StartTimeTicks' => 100]);

    makeEmbyTrackPreferenceService()->getDirectStreamUrl($request, 'item-1');

    Http::assertNothingSent();
});

it('prefers exact language match over partial match for Emby streams', function () {
    Http::fake([
        'http://emby.local:8096/Items/item-1*' => Http::response([
            'MediaSources' => [[
                'MediaStreams' => [
                    [
                        'Index' => 1,
                        'Type' => 'Audio',
                        'Language' => 'fre',
                        'DisplayTitle' => 'French AAC',
                    ],
                    [
                        'Index' => 2,
                        'Type' => 'Audio',
                        'Language' => 'eng',
                        'DisplayTitle' => 'English AAC',
                    ],
                ],
            ]],
        ], 200),
    ]);

    $request = new Request;
    $request->merge(['PreferredAudioTrack' => 'eng']);

    $url = makeEmbyTrackPreferenceService()->getDirectStreamUrl($request, 'item-1');

    expect($url)->toContain('AudioStreamIndex=2')
        ->and($url)->not->toContain('AudioStreamIndex=1');
});
