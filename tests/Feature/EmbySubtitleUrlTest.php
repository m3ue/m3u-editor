<?php

use App\Models\MediaServerIntegration;
use App\Services\EmbyJellyfinService;
use Illuminate\Support\Facades\Http;

it('resolves a subtitle url for a text-based subtitle stream, embedded or external', function () {
    $integration = new MediaServerIntegration([
        'type' => 'emby',
        'host' => 'emby.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'emby-token',
    ]);

    Http::fake([
        'http://emby.local:8096/Items*' => Http::response([
            'Items' => [
                [
                    'MediaSources' => [
                        ['Id' => 'mediasource_20074'],
                    ],
                    'MediaStreams' => [
                        ['Type' => 'Video', 'Index' => 0],
                        ['Type' => 'Audio', 'Index' => 1, 'Language' => 'eng'],
                        [
                            'Type' => 'Subtitle',
                            'Index' => 2,
                            'Codec' => 'srt',
                            'Language' => 'eng',
                            'IsExternal' => true,
                            'IsTextSubtitleStream' => true,
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = EmbyJellyfinService::make($integration);
    $result = $service->getSubtitleUrl('20074');

    expect($result)->not->toBeNull()
        ->and($result['language'])->toBe('eng')
        ->and($result['url'])->toContain('/Videos/20074/mediasource_20074/Subtitles/2/Stream.srt')
        ->and($result['url'])->toContain('api_key=emby-token')
        ->and($result['server_seeked'])->toBeFalse();
});

it('seeks the subtitle url server-side via startPositionTicks to match the video seek', function () {
    $integration = new MediaServerIntegration([
        'type' => 'emby',
        'host' => 'emby.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'emby-token',
    ]);

    Http::fake([
        'http://emby.local:8096/Items*' => Http::response([
            'Items' => [
                [
                    'MediaSources' => [
                        ['Id' => 'mediasource_465'],
                    ],
                    'MediaStreams' => [
                        [
                            'Type' => 'Subtitle',
                            'Index' => 3,
                            'Codec' => 'srt',
                            'Language' => 'en',
                            'IsExternal' => true,
                            'IsTextSubtitleStream' => true,
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = EmbyJellyfinService::make($integration);

    // 845 seconds -> 8_450_000_000 ticks, injected as a path segment before Stream.srt so
    // Emby rebases the cues to zero at that content-time (mirrors the video's StartTimeTicks).
    $result = $service->getSubtitleUrl('465', 845);

    expect($result)->not->toBeNull()
        ->and($result['server_seeked'])->toBeTrue()
        ->and($result['url'])->toContain('/Videos/465/mediasource_465/Subtitles/3/8450000000/Stream.srt')
        ->and($result['url'])->not->toContain('/Subtitles/3/Stream.srt');
});

it('resolves a numeric per-item override to its type-relative position, not a language match', function () {
    // resolveTrackPreference() resolves a per-item override to a numeric type-relative
    // position (the same "Nth embedded subtitle stream" position getAvailableTracks()
    // reports), not a language code — even though this same $preferredLanguage parameter
    // does carry a real language string for the network-level default. A prior version
    // only ever matched against Language fields, so a numeric override like "1" matched
    // nothing and silently fell back to the first subtitle stream regardless of what was
    // actually picked.
    $integration = new MediaServerIntegration([
        'type' => 'emby',
        'host' => 'emby.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'emby-token',
    ]);

    Http::fake([
        'http://emby.local:8096/Items*' => Http::response([
            'Items' => [
                [
                    'MediaSources' => [
                        ['Id' => 'mediasource_20074'],
                    ],
                    'MediaStreams' => [
                        ['Type' => 'Video', 'Index' => 0],
                        ['Type' => 'Audio', 'Index' => 1, 'Language' => 'eng'],
                        [
                            'Type' => 'Subtitle',
                            'Index' => 2,
                            'Codec' => 'srt',
                            'Language' => 'fre',
                            'IsExternal' => false,
                            'IsTextSubtitleStream' => true,
                        ],
                        [
                            'Type' => 'Subtitle',
                            'Index' => 3,
                            'Codec' => 'srt',
                            'Language' => 'eng',
                            'IsExternal' => false,
                            'IsTextSubtitleStream' => true,
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = EmbyJellyfinService::make($integration);

    // Position "1" is the second embedded subtitle stream (Index 3, English) — not the
    // first (Index 2, French), and not a language match against the literal string "1".
    $result = $service->getSubtitleUrl('20074', 0, '1');

    expect($result)->not->toBeNull()
        ->and($result['language'])->toBe('eng')
        ->and($result['url'])->toContain('/Videos/20074/mediasource_20074/Subtitles/3/Stream.srt');
});

it('counts a bitmap subtitle stream toward the numeric position even though it is never offered', function () {
    // Mirrors getAvailableTracks()'s position counting: a bitmap subtitle still occupies
    // a position slot even though it's skipped from the offered list, since FFmpeg's own
    // 0:s:N addressing counts it as a real container slot regardless of codec.
    $integration = new MediaServerIntegration([
        'type' => 'emby',
        'host' => 'emby.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'emby-token',
    ]);

    Http::fake([
        'http://emby.local:8096/Items*' => Http::response([
            'Items' => [
                [
                    'MediaSources' => [
                        ['Id' => 'mediasource_1'],
                    ],
                    'MediaStreams' => [
                        [
                            'Type' => 'Subtitle',
                            'Index' => 2,
                            'Codec' => 'pgssub',
                            'Language' => 'spa',
                            'IsExternal' => false,
                            'IsTextSubtitleStream' => false,
                        ],
                        [
                            'Type' => 'Subtitle',
                            'Index' => 3,
                            'Codec' => 'srt',
                            'Language' => 'fre',
                            'IsExternal' => false,
                            'IsTextSubtitleStream' => true,
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = EmbyJellyfinService::make($integration);

    // Position "1" is the French text stream (Index 3) — the bitmap stream at position 0
    // still claimed its slot even though it's never offered as a pick.
    $result = $service->getSubtitleUrl('1', 0, '1');

    expect($result)->not->toBeNull()
        ->and($result['language'])->toBe('fre')
        ->and($result['url'])->toContain('/Videos/1/mediasource_1/Subtitles/3/Stream.srt');
});

it('skips bitmap subtitle streams since ffmpeg cannot convert them to webvtt', function () {
    $integration = new MediaServerIntegration([
        'type' => 'emby',
        'host' => 'emby.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'emby-token',
    ]);

    Http::fake([
        'http://emby.local:8096/Items*' => Http::response([
            'Items' => [
                [
                    'MediaSources' => [
                        ['Id' => 'mediasource_1'],
                    ],
                    'MediaStreams' => [
                        [
                            'Type' => 'Subtitle',
                            'Index' => 2,
                            'Codec' => 'pgssub',
                            'Language' => 'eng',
                            'IsExternal' => false,
                            'IsTextSubtitleStream' => false,
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = EmbyJellyfinService::make($integration);

    expect($service->getSubtitleUrl('1'))->toBeNull();
});

it('retries when Emby returns a successful but empty MediaStreams payload', function () {
    $integration = new MediaServerIntegration([
        'type' => 'emby',
        'host' => 'emby.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'emby-token',
    ]);

    Http::fake([
        'http://emby.local:8096/Items*' => Http::sequence()
            // Emby/Jellyfin has been observed to momentarily return 200 with no
            // MediaStreams for a valid item under concurrent API load.
            ->push(['Items' => [['MediaSources' => [], 'MediaStreams' => []]]], 200)
            ->push([
                'Items' => [
                    [
                        'MediaSources' => [['Id' => 'mediasource_465']],
                        'MediaStreams' => [
                            [
                                'Type' => 'Subtitle',
                                'Index' => 3,
                                'Codec' => 'srt',
                                'Language' => 'en',
                                'IsExternal' => true,
                                'IsTextSubtitleStream' => true,
                            ],
                        ],
                    ],
                ],
            ], 200),
    ]);

    $service = EmbyJellyfinService::make($integration);
    $result = $service->getSubtitleUrl('465');

    expect($result)->not->toBeNull()
        ->and($result['language'])->toBe('en');

    Http::assertSentCount(2);
});

it('returns null after exhausting retries when MediaStreams stays empty', function () {
    $integration = new MediaServerIntegration([
        'type' => 'emby',
        'host' => 'emby.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'emby-token',
    ]);

    Http::fake([
        'http://emby.local:8096/Items*' => Http::response(
            ['Items' => [['MediaSources' => [], 'MediaStreams' => []]]],
            200
        ),
    ]);

    $service = EmbyJellyfinService::make($integration);

    expect($service->getSubtitleUrl('465'))->toBeNull();

    Http::assertSentCount(3);
});

it('returns null when the item has no subtitle stream at all', function () {
    $integration = new MediaServerIntegration([
        'type' => 'emby',
        'host' => 'emby.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'emby-token',
    ]);

    Http::fake([
        'http://emby.local:8096/Items*' => Http::response([
            'Items' => [
                [
                    'MediaSources' => [
                        ['Id' => 'mediasource_1'],
                    ],
                    'MediaStreams' => [
                        ['Type' => 'Video', 'Index' => 0],
                        ['Type' => 'Audio', 'Index' => 1, 'Language' => 'eng'],
                    ],
                ],
            ],
        ], 200),
    ]);

    $service = EmbyJellyfinService::make($integration);

    expect($service->getSubtitleUrl('1'))->toBeNull();
});
