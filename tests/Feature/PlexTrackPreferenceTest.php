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
