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

    expect($tracks['audio'])->toHaveCount(2)
        ->and($tracks['subtitle'])->toHaveCount(1)
        ->and(collect($tracks['audio'])->pluck('index')->all())->toBe([101, 102])
        ->and($tracks['subtitle'][0]['index'])->toBe(201)
        ->and($tracks['subtitle'][0]['language'])->toBe('eng');
});

it('getAvailableTracks returns empty arrays when the metadata request fails', function () {
    Http::fake([
        'http://plex.local:32400/library/metadata/item-1' => Http::response([], 500),
    ]);

    $tracks = makePlexTrackPreferenceService()->getAvailableTracks('item-1');

    expect($tracks)->toBe(['audio' => [], 'subtitle' => []]);
});
