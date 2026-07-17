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

it('does not set static=true when a preferred Emby audio track resolves to an index', function () {
    fakeEmbyItemWithStreams();

    $request = new Request;
    $request->merge(['PreferredAudioTrack' => 'jpn']);

    $url = makeEmbyTrackPreferenceService()->getDirectStreamUrl($request, 'item-1');

    expect($url)->toContain('AudioStreamIndex=2')
        ->and($url)->toContain('VideoCodec=copy')
        ->and($url)->not->toContain('static=true');
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

/**
 * fetchItemWithMediaStreams() (used by getSubtitleUrl and getAvailableTracks) calls
 * GET /Items?Ids={id} — a different endpoint shape than getDirectStreamUrl's
 * GET /Items/{id}, so these tests fake that shape specifically.
 */
function fakeEmbyItemsEndpointWithStreams(): void
{
    Http::fake([
        'http://emby.local:8096/Items*' => Http::response([
            'Items' => [[
                'Id' => 'item-1',
                'MediaSources' => [['Id' => 'ms-1']],
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
                        'DisplayTitle' => 'English SRT',
                        'Codec' => 'srt',
                        'IsTextSubtitleStream' => true,
                    ],
                    [
                        'Index' => 4,
                        'Type' => 'Subtitle',
                        'Language' => 'jpn',
                        'DisplayTitle' => 'Japanese SRT',
                        'Codec' => 'srt',
                        'IsTextSubtitleStream' => true,
                    ],
                    [
                        'Index' => 5,
                        'Type' => 'Subtitle',
                        'Language' => 'spa',
                        'DisplayTitle' => 'Spanish PGS (bitmap)',
                        'Codec' => 'pgssub',
                        'IsTextSubtitleStream' => false,
                    ],
                ],
            ]],
        ], 200),
    ]);
}

it('getSubtitleUrl returns the stream matching the preferred language', function () {
    fakeEmbyItemsEndpointWithStreams();

    $subtitle = makeEmbyTrackPreferenceService()->getSubtitleUrl('item-1', 0, 'jpn');

    expect($subtitle)->not->toBeNull()
        ->and($subtitle['language'])->toBe('jpn')
        ->and($subtitle['url'])->toContain('/Subtitles/4/');
});

it('getSubtitleUrl falls back to the first text subtitle when the preferred language has no match', function () {
    fakeEmbyItemsEndpointWithStreams();

    $subtitle = makeEmbyTrackPreferenceService()->getSubtitleUrl('item-1', 0, 'fra');

    expect($subtitle)->not->toBeNull()
        ->and($subtitle['language'])->toBe('eng')
        ->and($subtitle['url'])->toContain('/Subtitles/3/');
});

it('getSubtitleUrl skips bitmap-only subtitle streams even when they match the preferred language', function () {
    fakeEmbyItemsEndpointWithStreams();

    $subtitle = makeEmbyTrackPreferenceService()->getSubtitleUrl('item-1', 0, 'spa');

    // 'spa' only exists as a bitmap (PGS) stream, which can't be converted to WebVTT —
    // falls back to the first text stream instead of returning the unusable bitmap one.
    expect($subtitle)->not->toBeNull()
        ->and($subtitle['language'])->toBe('eng');
});

it('getAvailableTracks lists real audio and subtitle streams for the picker UI', function () {
    fakeEmbyItemsEndpointWithStreams();

    $tracks = makeEmbyTrackPreferenceService()->getAvailableTracks('item-1');

    // The Spanish PGS (bitmap) stream is excluded — it can't be converted to
    // WebVTT, so it must never be offered as a pickable per-item override.
    expect($tracks['audio'])->toHaveCount(2)
        ->and($tracks['subtitle'])->toHaveCount(2)
        ->and(collect($tracks['audio'])->pluck('language')->all())->toBe(['eng', 'jpn'])
        // 'index' is a composite "{type_relative_position}:{absolute_container_index}"
        // — position 0/1 among subtitle streams specifically (not Emby's absolute
        // Index 3/4, which is what appears after the colon).
        ->and(collect($tracks['subtitle'])->pluck('index')->all())->toBe(['0:3', '1:4']);
});

it('excludes bitmap-format subtitle streams from getAvailableTracks (cannot convert to WebVTT)', function () {
    // The real field bug: FFmpeg crashes outright (exit code 234) when mapping a
    // PGS/VobSub bitmap subtitle stream for HLS output, since its default WebVTT
    // encoder only supports text-to-text conversion. Bitmap streams must not be
    // offered as pickable per-item overrides — but they still occupy a real
    // container stream slot, so they must remain counted toward the position
    // numbering (see the dedicated renumbering test below).
    fakeEmbyItemsEndpointWithStreams();

    $tracks = makeEmbyTrackPreferenceService()->getAvailableTracks('item-1');

    expect(collect($tracks['subtitle'])->pluck('label')->all())
        ->not->toContain('Spanish PGS (bitmap)')
        ->and(collect($tracks['subtitle'])->pluck('language')->all())
        ->not->toContain('spa');
});

it('keeps subtitle positions aligned with FFmpeg container addressing when a bitmap stream is skipped', function () {
    // The exact reported bug: a bitmap (PGS) subtitle stream sitting BETWEEN two
    // real text subtitle streams was previously skipped before incrementing the
    // position counter, so the second text stream was stored at position "1"
    // when FFmpeg's own `0:s:N` addressing (which counts every embedded stream,
    // including the bitmap one) actually sees it at position "2" — silently
    // selecting the wrong subtitle track (or the bitmap one, or nothing) when an
    // operator picked the visually-second-listed option.
    Http::fake([
        'http://emby.local:8096/Items*' => Http::response([
            'Items' => [[
                'Id' => 'item-1',
                'MediaSources' => [['Id' => 'ms-1']],
                'MediaStreams' => [
                    [
                        'Index' => 3,
                        'Type' => 'Subtitle',
                        'Language' => 'eng',
                        'DisplayTitle' => 'English (SRT)',
                        'Codec' => 'srt',
                        'IsTextSubtitleStream' => true,
                    ],
                    [
                        'Index' => 4,
                        'Type' => 'Subtitle',
                        'Language' => 'spa',
                        'DisplayTitle' => 'Spanish PGS (bitmap)',
                        'Codec' => 'pgssub',
                        'IsTextSubtitleStream' => false,
                    ],
                    [
                        'Index' => 5,
                        'Type' => 'Subtitle',
                        'Language' => 'fra',
                        'DisplayTitle' => 'French (SRT)',
                        'Codec' => 'srt',
                        'IsTextSubtitleStream' => true,
                    ],
                ],
            ]],
        ], 200),
    ]);

    $tracks = makeEmbyTrackPreferenceService()->getAvailableTracks('item-1');

    expect($tracks['subtitle'])->toHaveCount(2)
        ->and($tracks['subtitle'][0]['index'])->toBe('0:3')
        // French claims position 2 (not 1) — the bitmap stream at position 1
        // still occupies a real container slot in FFmpeg's own numbering.
        ->and($tracks['subtitle'][1]['index'])->toBe('2:5');
});

it('excludes external subtitle streams from getAvailableTracks (unaddressable via raw-file -map)', function () {
    // The real field bug: Emby/Jellyfin flags sidecar subtitle files with
    // IsExternal=true. They can never be reached via FFmpeg's `-map 0:s:{N}?`
    // type-relative addressing when opening the raw file directly (that only sees
    // streams actually embedded in the container), so they must not be offered as
    // pickable per-item overrides or counted toward the position numbering.
    Http::fake([
        'http://emby.local:8096/Items*' => Http::response([
            'Items' => [[
                'Id' => 'item-1',
                'MediaSources' => [['Id' => 'ms-1']],
                'MediaStreams' => [
                    [
                        'Index' => 1,
                        'Type' => 'Subtitle',
                        'Language' => 'eng',
                        'DisplayTitle' => 'English (SRT External)',
                        'Codec' => 'srt',
                        'IsTextSubtitleStream' => true,
                        'IsExternal' => true,
                    ],
                    [
                        'Index' => 2,
                        'Type' => 'Subtitle',
                        'Language' => 'eng',
                        'DisplayTitle' => 'English (SRT)',
                        'Codec' => 'srt',
                        'IsTextSubtitleStream' => true,
                        'IsExternal' => false,
                    ],
                    [
                        'Index' => 3,
                        'Type' => 'Subtitle',
                        'Language' => 'eng',
                        'DisplayTitle' => 'English (SRT External) 2',
                        'Codec' => 'srt',
                        'IsTextSubtitleStream' => true,
                        'IsExternal' => true,
                    ],
                ],
            ]],
        ], 200),
    ]);

    $tracks = makeEmbyTrackPreferenceService()->getAvailableTracks('item-1');

    expect($tracks['subtitle'])->toHaveCount(1)
        ->and($tracks['subtitle'][0]['label'])->toBe('English (SRT)')
        // The only embedded stream becomes position 0 — external streams before it
        // in the source order are not counted.
        ->and($tracks['subtitle'][0]['index'])->toBe('0:2');
});
