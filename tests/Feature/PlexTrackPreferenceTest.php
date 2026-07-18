<?php

use App\Models\MediaServerIntegration;
use App\Services\PlexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

function makePlexTrackPreferenceService(): PlexService
{
    return PlexService::make(new MediaServerIntegration([
        'type' => 'plex',
        'host' => 'plex.local',
        'port' => 32400,
        'ssl' => false,
        'api_key' => 'plex-token',
    ]));
}

function fakePlexMetadataWithStreams(): void
{
    Http::fake([
        'http://plex.local:32400/library/metadata/item-1' => Http::response([
            'MediaContainer' => [
                'Metadata' => [[
                    'Media' => [[
                        'Part' => [[
                            'key' => '/library/parts/file.ts',
                            'Stream' => [
                                [
                                    'id' => 101,
                                    'streamType' => 2,
                                    'languageCode' => 'eng',
                                    'displayTitle' => 'English AAC 2.0',
                                ],
                                [
                                    'id' => 102,
                                    'streamType' => 2,
                                    'languageCode' => 'jpn',
                                    'displayTitle' => 'Japanese AAC 2.0',
                                ],
                                [
                                    'id' => 201,
                                    'streamType' => 3,
                                    'languageCode' => 'eng',
                                    'displayTitle' => 'English Forced',
                                    'codec' => 'srt',
                                ],
                            ],
                        ]],
                    ]],
                ]],
            ],
        ], 200),
    ]);
}

it('adds preferred Plex audio and subtitle stream ids to direct URLs', function () {
    fakePlexMetadataWithStreams();

    $request = new Request;
    $request->merge([
        'PreferredAudioTrack' => 'jpn',
        'PreferredSubtitleTrack' => 'eng',
    ]);

    $url = makePlexTrackPreferenceService()->getDirectStreamUrl($request, 'item-1');

    expect($url)->toContain('audioStreamID=102')
        ->and($url)->toContain('subtitleStreamID=201');
});

it('allows exact Plex stream ids as track preferences', function () {
    fakePlexMetadataWithStreams();

    $request = new Request;
    $request->merge([
        'PreferredAudioTrack' => '102',
        'PreferredSubtitleTrack' => '201',
    ]);

    $url = makePlexTrackPreferenceService()->getDirectStreamUrl($request, 'item-1');

    expect($url)->toContain('audioStreamID=102')
        ->and($url)->toContain('subtitleStreamID=201');
});

it('omits stream ids when the Plex language code does not match any stream', function () {
    fakePlexMetadataWithStreams();

    $request = new Request;
    $request->merge([
        'PreferredAudioTrack' => 'fra',
        'PreferredSubtitleTrack' => 'deu',
    ]);

    $url = makePlexTrackPreferenceService()->getDirectStreamUrl($request, 'item-1');

    expect($url)->not->toContain('audioStreamID')
        ->and($url)->not->toContain('subtitleStreamID');
});

it('ignores whitespace-only Plex track preferences', function () {
    fakePlexMetadataWithStreams();

    $request = new Request;
    $request->merge([
        'PreferredAudioTrack' => '   ',
        'PreferredSubtitleTrack' => "\t",
    ]);

    $url = makePlexTrackPreferenceService()->getDirectStreamUrl($request, 'item-1');

    expect($url)->not->toContain('audioStreamID')
        ->and($url)->not->toContain('subtitleStreamID');
});

it('prefers exact language code match over partial match for Plex streams', function () {
    Http::fake([
        'http://plex.local:32400/library/metadata/item-1' => Http::response([
            'MediaContainer' => [
                'Metadata' => [[
                    'Media' => [[
                        'Part' => [[
                            'key' => '/library/parts/file.ts',
                            'Stream' => [
                                [
                                    'id' => 201,
                                    'streamType' => 2,
                                    'languageCode' => 'fre',
                                    'displayTitle' => 'French AAC',
                                ],
                                [
                                    'id' => 202,
                                    'streamType' => 2,
                                    'languageCode' => 'eng',
                                    'displayTitle' => 'English AAC',
                                ],
                            ],
                        ]],
                    ]],
                ]],
            ],
        ], 200),
    ]);

    $request = new Request;
    $request->merge(['PreferredAudioTrack' => 'eng']);

    $url = makePlexTrackPreferenceService()->getDirectStreamUrl($request, 'item-1');

    // Should match the exact 'eng' code, not 'fre' which contains 'e' but not 'eng'
    expect($url)->toContain('audioStreamID=202')
        ->and($url)->not->toContain('audioStreamID=201');
});

it('getAvailableTracks lists real audio and subtitle streams for the picker UI', function () {
    fakePlexMetadataWithStreams();

    $tracks = makePlexTrackPreferenceService()->getAvailableTracks('item-1');

    // 'index' is a composite "{type_relative_position}:{plex_stream_id}" — position
    // 0/1 among audio streams specifically (not Plex's database-wide stream ID
    // 101/102, which appears after the colon and is meaningless to FFmpeg directly).
    expect($tracks['audio'])->toHaveCount(2)
        ->and($tracks['subtitle'])->toHaveCount(1)
        ->and(collect($tracks['audio'])->pluck('index')->all())->toBe(['0:101', '1:102'])
        ->and($tracks['subtitle'][0]['index'])->toBe('0:201')
        ->and($tracks['subtitle'][0]['language'])->toBe('eng');
});

it('excludes external subtitle streams from getAvailableTracks (unaddressable via raw-file -map)', function () {
    // The real field bug, reproduced exactly: Plex listed a genuinely embedded
    // "English (SRT)" track alongside two external sidecar files it itself labels
    // "(SRT External)" in extendedDisplayTitle. External streams can never be
    // reached via FFmpeg's `-map 0:s:{N}?` type-relative addressing when opening
    // the raw file directly, so they must be excluded from both the offered
    // options and the position numbering — otherwise a picked "position" silently
    // maps to nothing once translated to a raw ffmpeg map.
    Http::fake([
        'http://plex.local:32400/library/metadata/item-1' => Http::response([
            'MediaContainer' => [
                'Metadata' => [[
                    'Media' => [[
                        'Part' => [[
                            'key' => '/library/parts/file.ts',
                            'Stream' => [
                                [
                                    'id' => 301,
                                    'streamType' => 3,
                                    'languageCode' => 'unk',
                                    'extendedDisplayTitle' => 'Unknown (SRT External)',
                                    'key' => '/library/streams/301',
                                ],
                                [
                                    'id' => 302,
                                    'streamType' => 3,
                                    'languageCode' => 'eng',
                                    'extendedDisplayTitle' => 'English (SRT)',
                                    'codec' => 'srt',
                                ],
                                [
                                    'id' => 303,
                                    'streamType' => 3,
                                    'languageCode' => 'unk',
                                    'extendedDisplayTitle' => 'Unknown (SRT External)',
                                    'key' => '/library/streams/303',
                                    'codec' => 'srt',
                                ],
                            ],
                        ]],
                    ]],
                ]],
            ],
        ], 200),
    ]);

    $tracks = makePlexTrackPreferenceService()->getAvailableTracks('item-1');

    expect($tracks['subtitle'])->toHaveCount(1)
        ->and($tracks['subtitle'][0]['label'])->toBe('English (SRT)')
        ->and($tracks['subtitle'][0]['index'])->toBe('0:302');
});

it('excludes bitmap-format subtitle streams from getAvailableTracks (cannot convert to WebVTT)', function () {
    // The real field bug: FFmpeg crashes outright (exit code 234) when mapping a
    // PGS/VobSub bitmap subtitle stream for HLS output, since its default WebVTT
    // encoder only supports text-to-text conversion. Plex has no equivalent of
    // Emby's IsTextSubtitleStream flag, so PlexService allowlists known text
    // codec names instead — a stream with an unrecognized/bitmap codec must be
    // excluded from the picker, but still occupy a real container stream slot
    // (see the dedicated renumbering test below).
    Http::fake([
        'http://plex.local:32400/library/metadata/item-1' => Http::response([
            'MediaContainer' => [
                'Metadata' => [[
                    'Media' => [[
                        'Part' => [[
                            'key' => '/library/parts/file.ts',
                            'Stream' => [
                                [
                                    'id' => 401,
                                    'streamType' => 3,
                                    'languageCode' => 'eng',
                                    'extendedDisplayTitle' => 'English (SRT)',
                                    'codec' => 'srt',
                                ],
                                [
                                    'id' => 402,
                                    'streamType' => 3,
                                    'languageCode' => 'spa',
                                    'extendedDisplayTitle' => 'Spanish (PGS)',
                                    'codec' => 'pgssub',
                                ],
                            ],
                        ]],
                    ]],
                ]],
            ],
        ], 200),
    ]);

    $tracks = makePlexTrackPreferenceService()->getAvailableTracks('item-1');

    expect($tracks['subtitle'])->toHaveCount(1)
        ->and($tracks['subtitle'][0]['label'])->toBe('English (SRT)')
        ->and($tracks['subtitle'][0]['index'])->toBe('0:401');
});

it('keeps subtitle positions aligned with FFmpeg container addressing when a bitmap stream is skipped', function () {
    // The exact reported bug: a bitmap (PGS) subtitle stream sitting BETWEEN two
    // real text subtitle streams was previously skipped before incrementing the
    // position counter, so the second text stream was stored at position "1"
    // when FFmpeg's own `0:s:N` addressing (which counts every embedded stream,
    // including the bitmap one) actually sees it at position "2" — silently
    // selecting the wrong subtitle track when an operator picked the
    // visually-second-listed option.
    Http::fake([
        'http://plex.local:32400/library/metadata/item-1' => Http::response([
            'MediaContainer' => [
                'Metadata' => [[
                    'Media' => [[
                        'Part' => [[
                            'key' => '/library/parts/file.ts',
                            'Stream' => [
                                [
                                    'id' => 501,
                                    'streamType' => 3,
                                    'languageCode' => 'eng',
                                    'extendedDisplayTitle' => 'English (SRT)',
                                    'codec' => 'srt',
                                ],
                                [
                                    'id' => 502,
                                    'streamType' => 3,
                                    'languageCode' => 'spa',
                                    'extendedDisplayTitle' => 'Spanish (PGS)',
                                    'codec' => 'pgssub',
                                ],
                                [
                                    'id' => 503,
                                    'streamType' => 3,
                                    'languageCode' => 'fre',
                                    'extendedDisplayTitle' => 'French (SRT)',
                                    'codec' => 'srt',
                                ],
                            ],
                        ]],
                    ]],
                ]],
            ],
        ], 200),
    ]);

    $tracks = makePlexTrackPreferenceService()->getAvailableTracks('item-1');

    expect($tracks['subtitle'])->toHaveCount(2)
        ->and($tracks['subtitle'][0]['index'])->toBe('0:501')
        // French claims position 2 (not 1) — the bitmap stream at position 1
        // still occupies a real container slot in FFmpeg's own numbering.
        ->and($tracks['subtitle'][1]['index'])->toBe('2:503');
});

it('getAvailableTracks returns empty arrays when the metadata request fails', function () {
    Http::fake([
        'http://plex.local:32400/library/metadata/item-1' => Http::response([], 500),
    ]);

    $tracks = makePlexTrackPreferenceService()->getAvailableTracks('item-1');

    expect($tracks)->toBe(['audio' => [], 'subtitle' => []]);
});
