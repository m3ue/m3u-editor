<?php

use App\Models\MediaServerIntegration;
use App\Models\User;
use App\Services\PlexManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Bus::fake();
    $this->user = User::factory()->create();
    $this->integration = MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Test Plex Server',
            'type' => 'plex',
            'host' => 'plex.example.com',
            'port' => 32400,
            'ssl' => false,
            'api_key' => 'test-plex-token',
            'enabled' => true,
            'user_id' => $this->user->id,
            'plex_management_enabled' => true,
        ]);
    });
});

it('throws exception for non-plex integration', function () {
    $embyIntegration = MediaServerIntegration::withoutEvents(function () {
        return MediaServerIntegration::create([
            'name' => 'Emby Server',
            'type' => 'emby',
            'host' => '192.168.1.100',
            'api_key' => 'test-key',
            'user_id' => $this->user->id,
        ]);
    });

    PlexManagementService::make($embyIntegration);
})->throws(InvalidArgumentException::class, 'PlexManagementService requires a Plex integration');

it('can get server info', function () {
    Http::fake([
        'plex.example.com:32400/' => Http::response([
            'MediaContainer' => [
                'friendlyName' => 'My Plex Server',
                'version' => '1.40.0',
                'platform' => 'Linux',
                'machineIdentifier' => 'abc123',
            ],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getServerInfo();

    expect($result['success'])->toBeTrue();
    expect($result['data']['name'])->toBe('My Plex Server');
    expect($result['data']['version'])->toBe('1.40.0');
    expect($result['data']['platform'])->toBe('Linux');
    expect($result['data']['machine_id'])->toBe('abc123');
});

it('handles server info failure gracefully', function () {
    Http::fake([
        'plex.example.com:32400/*' => Http::response(null, 500),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getServerInfo();

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('500');
});

it('can get active sessions', function () {
    Http::fake([
        'plex.example.com:32400/status/sessions' => Http::response([
            'MediaContainer' => [
                'Metadata' => [
                    [
                        'sessionKey' => '1',
                        'title' => 'Test Movie',
                        'type' => 'movie',
                        'User' => ['title' => 'TestUser'],
                        'Player' => ['title' => 'Chrome', 'state' => 'playing'],
                        'viewOffset' => 60000,
                        'duration' => 7200000,
                    ],
                ],
            ],
        ]),
        'plex.example.com:32400/transcode/sessions' => Http::response([
            'MediaContainer' => ['size' => 0],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getActiveSessions();

    expect($result['success'])->toBeTrue();
    expect($result['data'])->toHaveCount(1);
    expect($result['data']->first()['title'])->toBe('Test Movie');
    expect($result['data']->first()['user'])->toBe('TestUser');
    expect($result['data']->first()['state'])->toBe('playing');
    expect($result['data']->first()['live'])->toBeFalse();
});

it('can get live tv sessions from Video key', function () {
    Http::fake([
        'plex.example.com:32400/status/sessions' => Http::response([
            'MediaContainer' => [
                'size' => 1,
                'Video' => [
                    [
                        'sessionKey' => '5',
                        'title' => 'Live News',
                        'grandparentTitle' => 'News Channel',
                        'type' => 'clip',
                        'live' => '1',
                        'User' => ['title' => 'Admin'],
                        'Player' => ['title' => 'Plex Web', 'state' => 'playing'],
                        'viewOffset' => 0,
                        'duration' => 0,
                        'TranscodeSession' => ['key' => 'abc'],
                    ],
                ],
            ],
        ]),
        'plex.example.com:32400/transcode/sessions' => Http::response([
            'MediaContainer' => ['size' => 0],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getActiveSessions();

    expect($result['success'])->toBeTrue();
    expect($result['data'])->toHaveCount(1);
    expect($result['data']->first()['title'])->toBe('Live News');
    expect($result['data']->first()['live'])->toBeTrue();
    expect($result['data']->first()['transcode'])->toBeTrue();
    expect($result['data']->first()['type'])->toBe('clip');
});

it('merges transcode sessions not in status sessions', function () {
    Http::fake([
        'plex.example.com:32400/status/sessions' => Http::response([
            'MediaContainer' => [
                'size' => 1,
                'Metadata' => [
                    [
                        'sessionKey' => '1',
                        'title' => 'Regular Movie',
                        'type' => 'movie',
                        'User' => ['title' => 'User1'],
                        'Player' => ['title' => 'TV', 'state' => 'playing'],
                    ],
                ],
            ],
        ]),
        'plex.example.com:32400/transcode/sessions' => Http::response([
            'MediaContainer' => [
                'size' => 1,
                'Metadata' => [
                    [
                        'sessionKey' => '2',
                        'title' => 'Live Channel',
                        'type' => 'clip',
                        'live' => '1',
                        'User' => ['title' => 'User2'],
                        'Player' => ['title' => 'iPad', 'state' => 'playing'],
                        'TranscodeSession' => ['key' => 'xyz'],
                    ],
                ],
            ],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getActiveSessions();

    expect($result['success'])->toBeTrue();
    expect($result['data'])->toHaveCount(2);
    expect($result['data']->pluck('title')->toArray())->toBe(['Regular Movie', 'Live Channel']);
});

it('does not duplicate sessions already in status and transcode', function () {
    Http::fake([
        'plex.example.com:32400/status/sessions' => Http::response([
            'MediaContainer' => [
                'Metadata' => [
                    [
                        'sessionKey' => '1',
                        'title' => 'Same Movie',
                        'type' => 'movie',
                        'User' => ['title' => 'User1'],
                        'Player' => ['title' => 'TV', 'state' => 'playing'],
                    ],
                ],
            ],
        ]),
        'plex.example.com:32400/transcode/sessions' => Http::response([
            'MediaContainer' => [
                'Metadata' => [
                    [
                        'sessionKey' => '1',
                        'title' => 'Same Movie',
                        'type' => 'movie',
                        'User' => ['title' => 'User1'],
                        'Player' => ['title' => 'TV', 'state' => 'playing'],
                    ],
                ],
            ],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getActiveSessions();

    expect($result['success'])->toBeTrue();
    expect($result['data'])->toHaveCount(1);
});

it('can get DVR configurations', function () {
    Http::fake([
        'plex.example.com:32400/livetv/dvrs' => Http::response([
            'MediaContainer' => [
                'Dvr' => [
                    [
                        'key' => '1',
                        'uuid' => 'dvr-uuid-123',
                        'title' => 'My DVR',
                        'lineup' => 'http://example.com/epg.xml',
                        'language' => 'en',
                        'country' => 'US',
                        'Device' => [
                            [
                                'key' => 'device-1',
                                'uri' => 'http://192.168.1.100:8080',
                                'make' => 'Silicondust',
                                'model' => 'HDHR5-4K',
                                'tuners' => 4,
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getDvrs();

    expect($result['success'])->toBeTrue();
    expect($result['data'])->toHaveCount(1);
    expect($result['data']->first()['id'])->toBe('1');
    expect($result['data']->first()['device_count'])->toBe(1);
});

it('prefers the provided hdhr url when fetching discover json', function () {
    $requestedUrls = [];

    Http::fake(function ($request) use (&$requestedUrls) {
        $requestedUrls[] = $request->url();

        return match ($request->url()) {
            'https://iptv.chorkley.uk/test-playlist-uuid/hdhr/discover.json' => Http::response([
                'DeviceID' => 'hdhr-device-123',
                'DeviceAuth' => 'auth-abc',
            ]),
            default => Http::response(null, 500),
        };
    });

    $service = new class($this->integration) extends PlexManagementService
    {
        public function fetchDiscoverPayloadForTest(string $hdhrBaseUrl): array
        {
            return $this->fetchDiscoverPayload($hdhrBaseUrl);
        }
    };

    $result = $service->fetchDiscoverPayloadForTest('https://iptv.chorkley.uk/test-playlist-uuid/hdhr');

    expect($result['success'])->toBeTrue();
    expect($result['data']['DeviceID'])->toBe('hdhr-device-123');
    expect($requestedUrls)->toContain('https://iptv.chorkley.uk/test-playlist-uuid/hdhr/discover.json');
    expect($requestedUrls)->not->toContain('http://localhost:443/test-playlist-uuid/hdhr/discover.json');
    expect($requestedUrls)->not->toContain('http://127.0.0.1:443/test-playlist-uuid/hdhr/discover.json');
});

it('can register a DVR device', function () {
    $devicesCallCount = 0;
    $dvrsCallCount = 0;

    Http::fake(function ($request) use (&$devicesCallCount, &$dvrsCallCount) {
        $url = $request->url();

        // Step 1: discover.json fetched via the configured HDHR URL
        if (str_contains($url, '/hdhr/discover.json')) {
            return Http::response([
                'DeviceID' => 'hdhr-device-123',
                'DeviceAuth' => 'auth-abc',
                'FriendlyName' => 'm3u-editor HDHR',
                'ModelNumber' => 'HDHR5-4K',
            ]);
        }

        // Step 2: Create device in Plex (POST/PUT to /media/grabbers/devices with query params)
        if (str_contains($url, '/media/grabbers/devices') && str_contains($url, 'uri=')) {
            return Http::response([], 200);
        }

        // Step 3: GET /media/grabbers/devices (no query params)
        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?')) {
            $devicesCallCount++;

            return Http::response([
                'MediaContainer' => [
                    'Device' => [
                        [
                            'key' => 'device-99',
                            'uuid' => 'device-uuid-abc',
                            'uri' => 'http://m3u-editor/hdhr',
                            'make' => 'Silicondust',
                            'model' => 'm3u-editor HDHR',
                        ],
                    ],
                ],
            ]);
        }

        // DVR create: POST /livetv/dvrs with device= query param
        if (str_contains($url, '/livetv/dvrs') && str_contains($url, 'device=') && $request->method() === 'POST') {
            return Http::response([
                'MediaContainer' => [
                    'Dvr' => [['key' => '42', 'uuid' => 'dvr-uuid-new']],
                ],
            ], 201);
        }

        // Attach device: PUT /livetv/dvrs/42/devices/device-99
        if (str_contains($url, '/livetv/dvrs/42/devices/device-99')) {
            return Http::response([], 200);
        }

        // GET /livetv/dvrs (sequence: first empty, then with DVR)
        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, '?') && ! str_contains($url, '/devices/')) {
            $dvrsCallCount++;

            if ($dvrsCallCount <= 2) {
                // First two calls: getDvrs() mapped call + raw fetch
                return Http::response(['MediaContainer' => ['Dvr' => []]]);
            }

            // After DVR creation: has DVR
            return Http::response(['MediaContainer' => ['Dvr' => [
                [
                    'key' => '42',
                    'uuid' => 'dvr-uuid-new',
                    'lineup' => 'lineup://tv.plex.providers.epg.xmltv/test-lineup',
                    'Device' => [
                        ['key' => 'device-99', 'uuid' => 'device-uuid-abc'],
                    ],
                ],
            ]]]);
        }

        // lineup.json for auto-sync after registration
        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '1', 'GuideName' => 'Channel 1', 'URL' => 'http://example.com/1'],
            ]);
        }

        // lineupchannels for auto-sync
        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            return Http::response(['MediaContainer' => ['Lineup' => [
                ['uuid' => 'lineup://test', 'type' => 'lineup', 'Channel' => [
                    ['identifier' => 'ch-1', 'channelVcn' => '1', 'key' => '1', 'title' => 'Ch 1', 'callSign' => 'Ch 1'],
                ]],
            ]]]);
        }

        // channelmap PUT for auto-sync
        if (str_contains($url, '/channelmap')) {
            return Http::response([], 200);
        }

        return Http::response([], 404);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->addDvrDevice('http://m3u-editor/hdhr', 'http://m3u-editor/epg.xml', 'de', 'de', 'test-playlist-uuid');

    expect($result['success'])->toBeTrue();
    expect($result['dvr_id'])->toBe('42');

    $this->integration->refresh();
    expect($this->integration->plex_dvr_id)->toBe('42');
    $tuners = $this->integration->plex_dvr_tuners;
    expect($tuners)->toBeArray()->toHaveCount(1);
    expect($tuners[0]['device_key'])->toBe('device-99');
    expect($tuners[0]['playlist_uuid'])->toBe('test-playlist-uuid');
});

it('fails DVR registration when HDHR device is unreachable', function () {
    Http::fake([
        '*/hdhr/discover.json' => Http::response(null, 500),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->addDvrDevice('http://m3u-editor/hdhr', 'http://m3u-editor/epg.xml');

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('discover.json');
});

it('can remove a DVR', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake([
        'plex.example.com:32400/livetv/dvrs/42' => Http::response([], 200),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->removeDvr('42');

    expect($result['success'])->toBeTrue();
    $this->integration->refresh();
    expect($this->integration->plex_dvr_id)->toBeNull();
    expect($this->integration->plex_dvr_tuners)->toBeNull();
});

it('can get all libraries', function () {
    Http::fake([
        'plex.example.com:32400/library/sections' => Http::response([
            'MediaContainer' => [
                'Directory' => [
                    [
                        'key' => '1',
                        'title' => 'Movies',
                        'type' => 'movie',
                        'agent' => 'tv.plex.agents.movie',
                        'Location' => [['path' => '/media/movies']],
                    ],
                    [
                        'key' => '2',
                        'title' => 'TV Shows',
                        'type' => 'show',
                        'agent' => 'tv.plex.agents.series',
                        'Location' => [['path' => '/media/tv']],
                    ],
                ],
            ],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getAllLibraries();

    expect($result['success'])->toBeTrue();
    expect($result['data'])->toHaveCount(2);
    expect($result['data']->first()['title'])->toBe('Movies');
    expect($result['data']->last()['title'])->toBe('TV Shows');
});

it('can scan a library', function () {
    Http::fake([
        'plex.example.com:32400/library/sections/1/refresh' => Http::response([], 200),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->scanLibrary('1');

    expect($result['success'])->toBeTrue();
});

it('can get recordings', function () {
    Http::fake([
        'plex.example.com:32400/media/subscriptions' => Http::response([
            'MediaContainer' => [
                'MediaSubscription' => [
                    [
                        'key' => 'rec-1',
                        'title' => 'Evening News',
                        'type' => 'recording',
                        'createdAt' => 1711612800,
                    ],
                ],
            ],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getRecordings();

    expect($result['success'])->toBeTrue();
    expect($result['data'])->toHaveCount(1);
    expect($result['data']->first()['title'])->toBe('Evening News');
});

it('can cancel a recording', function () {
    Http::fake([
        'plex.example.com:32400/media/subscriptions/rec-1' => Http::response([], 200),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->cancelRecording('rec-1');

    expect($result['success'])->toBeTrue();
});

it('can terminate a session', function () {
    Http::fake([
        'plex.example.com:32400/status/sessions/terminate/player*' => Http::response([], 200),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->terminateSession('session-1');

    expect($result['success'])->toBeTrue();
});

it('can get dashboard summary', function () {
    Http::fake([
        'plex.example.com:32400/' => Http::response([
            'MediaContainer' => [
                'friendlyName' => 'My Plex',
                'version' => '1.40.0',
                'platform' => 'Linux',
                'machineIdentifier' => 'abc',
            ],
        ]),
        'plex.example.com:32400/status/sessions' => Http::response([
            'MediaContainer' => ['Metadata' => []],
        ]),
        'plex.example.com:32400/transcode/sessions' => Http::response([
            'MediaContainer' => ['size' => 0],
        ]),
        'plex.example.com:32400/livetv/dvrs' => Http::response([
            'MediaContainer' => ['Dvr' => []],
        ]),
        'plex.example.com:32400/library/sections' => Http::response([
            'MediaContainer' => ['Directory' => []],
        ]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getDashboardSummary();

    expect($result['success'])->toBeTrue();
    expect($result['data']['server']['name'])->toBe('My Plex');
    expect($result['data']['active_sessions'])->toBe(0);
    expect($result['data']['dvr_count'])->toBe(0);
    expect($result['data']['library_count'])->toBe(0);
});

it('stores plex management fields on model', function () {
    expect($this->integration->plex_management_enabled)->toBeTrue();
    expect($this->integration->plex_dvr_id)->toBeNull();
    expect($this->integration->plex_machine_id)->toBeNull();

    $this->integration->update([
        'plex_dvr_id' => 'dvr-42',
        'plex_machine_id' => 'machine-abc',
        'plex_dvr_tuners' => [
            ['device_key' => 'device-99', 'playlist_uuid' => 'playlist-uuid-abc'],
        ],
    ]);

    $this->integration->refresh();
    expect($this->integration->plex_dvr_id)->toBe('dvr-42');
    expect($this->integration->plex_machine_id)->toBe('machine-abc');
    expect($this->integration->plex_dvr_tuners)->toBeArray()->toHaveCount(1);
    expect($this->integration->plex_dvr_tuners[0]['device_key'])->toBe('device-99');
    expect($this->integration->plex_dvr_tuners[0]['playlist_uuid'])->toBe('playlist-uuid-abc');
});

it('can sync DVR channels when in sync', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '1', 'GuideName' => 'Ch 1', 'URL' => 'http://example.com/1'],
                ['GuideNumber' => '2', 'GuideName' => 'Ch 2', 'URL' => 'http://example.com/2'],
            ]);
        }

        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            return Http::response(['MediaContainer' => ['Lineup' => [
                ['uuid' => 'lineup://test', 'type' => 'lineup', 'Channel' => [
                    ['identifier' => 'ch-1', 'channelVcn' => '1', 'key' => '1', 'title' => 'Ch 1', 'callSign' => 'Ch 1'],
                    ['identifier' => 'ch-2', 'channelVcn' => '2', 'key' => '2', 'title' => 'Ch 2', 'callSign' => 'Ch 2'],
                ]],
            ]]]);
        }

        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Device' => [
                [
                    'key' => 'device-99',
                    'uri' => 'http://m3u-editor/hdhr',
                    'ChannelMapping' => [
                        ['lineupIdentifier' => 'ch-1', 'enabled' => '1'],
                        ['lineupIdentifier' => 'ch-2', 'enabled' => '1'],
                    ],
                ],
            ]]]);
        }

        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Dvr' => [
                ['key' => '42', 'lineup' => 'lineup://tv.plex.providers.epg.xmltv/test-lineup', 'Lineup' => [
                    ['id' => 'lineup://tv.plex.providers.epg.xmltv/test-lineup', 'title' => 'XMLTV Guide'],
                ], 'Device' => [['key' => 'device-99']]],
            ]]]);
        }

        return Http::response([], 200);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->syncDvrChannels();

    expect($result['success'])->toBeTrue();
    expect($result['changed'])->toBeFalse();
    expect($result['mapped_channels'])->toBe(2);
});

it('can sync DVR channels when out of sync', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '1', 'GuideName' => 'Ch 1', 'URL' => 'http://example.com/1'],
                ['GuideNumber' => '2', 'GuideName' => 'Ch 2', 'URL' => 'http://example.com/2'],
                ['GuideNumber' => '3', 'GuideName' => 'Ch 3', 'URL' => 'http://example.com/3'],
            ]);
        }

        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            return Http::response(['MediaContainer' => ['Lineup' => [
                ['uuid' => 'lineup://test', 'type' => 'lineup', 'Channel' => [
                    ['identifier' => 'ch-1', 'channelVcn' => '1', 'key' => '1', 'title' => 'Ch 1', 'callSign' => 'Ch 1'],
                    ['identifier' => 'ch-2', 'channelVcn' => '2', 'key' => '2', 'title' => 'Ch 2', 'callSign' => 'Ch 2'],
                    ['identifier' => 'ch-3', 'channelVcn' => '3', 'key' => '3', 'title' => 'Ch 3', 'callSign' => 'Ch 3'],
                ]],
            ]]]);
        }

        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Device' => [
                [
                    'key' => 'device-99',
                    'uri' => 'http://m3u-editor/hdhr',
                    'ChannelMapping' => [
                        ['lineupIdentifier' => 'ch-1', 'enabled' => '1'],
                    ],
                ],
            ]]]);
        }

        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Dvr' => [
                ['key' => '42', 'lineup' => 'lineup://tv.plex.providers.epg.xmltv/test-lineup', 'Lineup' => [
                    ['id' => 'lineup://tv.plex.providers.epg.xmltv/test-lineup', 'title' => 'XMLTV Guide'],
                ], 'Device' => [['key' => 'device-99']]],
            ]]]);
        }

        if (str_contains($url, '/channelmap')) {
            return Http::response([], 200);
        }

        return Http::response([], 200);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->syncDvrChannels();

    expect($result['success'])->toBeTrue();
    expect($result['changed'])->toBeTrue();
    expect($result['mapped_channels'])->toBe(3);
});

it('returns error when syncing without DVR configured', function () {
    $service = PlexManagementService::make($this->integration);
    $result = $service->syncDvrChannels();

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('not fully configured');
});

it('can refresh EPG guides', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
    ]);

    Http::fake([
        'plex.example.com:32400/livetv/dvrs/42' => Http::response(['MediaContainer' => ['Dvr' => [['Setting' => [
            ['id' => 'ButlerTaskRefreshEpgGuides', 'value' => 'true', 'default' => 'true'],
            ['id' => 'xmltvCustomRefreshInHours', 'value' => '24', 'default' => '24'],
        ]]]]], 200),
        'plex.example.com:32400/livetv/dvrs/42/prefs*' => Http::response([], 200),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->refreshGuides();

    expect($result['success'])->toBeTrue();
    expect($result['message'])->toContain('refresh');
});

it('can configure DVR preferences', function () {
    Http::fake([
        'plex.example.com:32400/livetv/dvrs/42' => Http::response(['MediaContainer' => ['Dvr' => [['Setting' => [
            ['id' => 'minVideoQuality', 'value' => '0', 'default' => '0'],
            ['id' => 'xmltvCustomRefreshInHours', 'value' => '24', 'default' => '24'],
            ['id' => 'ButlerTaskRefreshEpgGuides', 'value' => 'true', 'default' => 'true'],
        ]]]]], 200),
        'plex.example.com:32400/livetv/dvrs/42/prefs*' => Http::response([], 200),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->configureDvrPrefs('42', ['xmltvCustomRefreshInHours' => '6']);

    expect($result['success'])->toBeTrue();
    expect($result['message'])->toBe('DVR preferences updated');
});

it('can run sync plex dvr command', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([]);
        }

        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            return Http::response(['MediaContainer' => ['Lineup' => []]]);
        }

        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Device' => []]]);
        }

        if (str_contains($url, '/livetv/dvrs')) {
            return Http::response(['MediaContainer' => ['Dvr' => []]]);
        }

        return Http::response([], 200);
    });

    $this->artisan('app:sync-plex-dvr')
        ->assertExitCode(0);
});

it('resolves lineup ID from Lineup array entries', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '5', 'GuideName' => 'Ch 5', 'URL' => 'http://example.com/5'],
            ]);
        }

        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            // Return a channel using the lineup ID from Lineup array
            return Http::response(['MediaContainer' => ['Lineup' => [
                ['uuid' => 'lineup://test', 'type' => 'lineup', 'Channel' => [
                    ['identifier' => 'ch-5-from-lineup-array', 'channelVcn' => '5', 'key' => '5', 'title' => 'Ch 5', 'callSign' => 'Ch 5'],
                ]],
            ]]]);
        }

        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Device' => [
                [
                    'key' => 'device-99',
                    'ChannelMapping' => [
                        ['lineupIdentifier' => 'ch-5-from-lineup-array', 'enabled' => '1'],
                    ],
                ],
            ]]]);
        }

        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, '?')) {
            // DVR with Lineup array (not just top-level lineup field)
            return Http::response(['MediaContainer' => ['Dvr' => [
                [
                    'key' => '42',
                    'lineup' => 'lineup://wrong-top-level',
                    'Lineup' => [
                        ['id' => 'lineup://tv.plex.providers.epg.xmltv/correct-lineup', 'title' => 'XMLTV Guide from array'],
                    ],
                    'Device' => [['key' => 'device-99']],
                ],
            ]]]);
        }

        return Http::response([], 200);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->syncDvrChannels();

    expect($result['success'])->toBeTrue();
    expect($result['mapped_channels'])->toBe(1);
});

it('triggers EPG refresh after channel map changes', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    $butlerCalled = false;
    Http::fake(function ($request) use (&$butlerCalled) {
        $url = $request->url();

        if (str_contains($url, '/butler/RefreshEPGGuides')) {
            $butlerCalled = true;

            return Http::response([], 200);
        }

        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '1', 'GuideName' => 'Ch 1', 'URL' => 'http://example.com/1'],
            ]);
        }

        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            return Http::response(['MediaContainer' => ['Lineup' => []]]);
        }

        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Device' => [
                ['key' => 'device-99', 'ChannelMapping' => []],
            ]]]);
        }

        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Dvr' => [
                ['key' => '42', 'lineup' => 'lineup://test', 'Device' => [['key' => 'device-99']]],
            ]]]);
        }

        if (str_contains($url, '/channelmap')) {
            return Http::response([], 200);
        }

        return Http::response([], 200);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->syncDvrChannels();

    expect($result['success'])->toBeTrue();
    expect($result['changed'])->toBeTrue();
    expect($butlerCalled)->toBeTrue();
    expect($result['message'])->toContain('EPG refresh triggered');
});

it('handles single channel response from Plex lineup', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '10', 'GuideName' => 'Ch 10', 'URL' => 'http://example.com/10'],
            ]);
        }

        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            // Single lineup returned as object (not array) — Plex does this for single results
            return Http::response(['MediaContainer' => ['Lineup' => [
                'uuid' => 'lineup://test', 'type' => 'lineup', 'Channel' => [
                    ['identifier' => 'single-ch-10', 'channelVcn' => '10', 'key' => '10', 'title' => 'Ch 10', 'callSign' => 'Ch 10'],
                ],
            ]]]);
        }

        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Device' => [
                ['key' => 'device-99', 'ChannelMapping' => []],
            ]]]);
        }

        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Dvr' => [
                ['key' => '42', 'lineup' => 'lineup://test', 'Device' => [['key' => 'device-99']]],
            ]]]);
        }

        if (str_contains($url, '/channelmap')) {
            return Http::response([], 200);
        }

        return Http::response([], 200);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->syncDvrChannels();

    expect($result['success'])->toBeTrue();
    expect($result['mapped_channels'])->toBe(1);
});

it('clears local state when removing a DVR that was already deleted in Plex', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake([
        'plex.example.com:32400/livetv/dvrs/42' => Http::response(null, 404),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->removeDvr('42');

    expect($result['success'])->toBeTrue();
    expect($result['message'])->toBe('DVR removed from Plex');

    $this->integration->refresh();
    expect($this->integration->plex_dvr_id)->toBeNull();
    expect($this->integration->plex_dvr_tuners)->toBeNull();
});

it('can remove a tuner when no DVR is registered in Plex', function () {
    $this->integration->update([
        'plex_dvr_id' => null,
        'plex_dvr_tuners' => [
            ['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid'],
            ['device_key' => 'device-100', 'playlist_uuid' => 'test-uuid-2'],
        ],
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->removeTuner('device-99');

    expect($result['success'])->toBeTrue();
    expect($result['message'])->toContain('no DVR registered');

    $this->integration->refresh();
    expect($this->integration->plex_dvr_tuners)->toHaveCount(1);
    expect($this->integration->plex_dvr_tuners[0]['device_key'])->toBe('device-100');
});

it('returns empty collection when getDvrChannels returns 404', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake([
        'plex.example.com:32400/livetv/dvrs/42' => Http::response(null, 404),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->getDvrChannels('42');

    // 404 means DVR not found, return empty
    expect($result['success'])->toBeTrue();
    expect($result['data'])->toHaveCount(0);

    // DVR state should NOT be cleared — getDvrChannels doesn't manage state
    $this->integration->refresh();
    expect($this->integration->plex_dvr_id)->toBe('42');
    expect($this->integration->plex_dvr_tuners)->not->toBeNull();
});

it('detects stale DVR during sync and cleans up', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    Http::fake([
        'plex.example.com:32400/livetv/dvrs' => Http::response(['MediaContainer' => ['Dvr' => []]]),
    ]);

    $service = PlexManagementService::make($this->integration);
    $result = $service->syncDvrChannels();

    expect($result['success'])->toBeFalse();
    expect($result['message'])->toContain('no longer exists');

    $this->integration->refresh();
    expect($this->integration->plex_dvr_id)->toBeNull();
    expect($this->integration->plex_dvr_tuners)->toBeNull();
});

it('refreshes lineup device before syncing channels', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [['device_key' => 'device-99', 'playlist_uuid' => 'test-uuid']],
    ]);

    $scanCalled = false;
    Http::fake(function ($request) use (&$scanCalled) {
        $url = $request->url();

        // Track the device scan call (lineup refresh)
        if (str_contains($url, '/media/grabbers/devices/device-99/scan')) {
            $scanCalled = true;

            return Http::response([], 200);
        }

        if (str_contains($url, '/hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '40', 'GuideName' => 'Kabel Eins', 'URL' => 'http://example.com/40'],
            ]);
        }

        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            return Http::response(['MediaContainer' => ['Lineup' => [
                ['uuid' => 'lineup://test', 'type' => 'lineup', 'Channel' => [
                    ['identifier' => 'ch-40', 'channelVcn' => '40', 'key' => '40', 'title' => 'Kabel Eins', 'callSign' => 'Kabel Eins'],
                ]],
            ]]]);
        }

        if (str_contains($url, '/media/grabbers/devices') && ! str_contains($url, '?') && ! str_contains($url, '/scan')) {
            return Http::response(['MediaContainer' => ['Device' => [
                ['key' => 'device-99', 'ChannelMapping' => [
                    ['lineupIdentifier' => 'ch-40', 'enabled' => '1'],
                ]],
            ]]]);
        }

        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, '?')) {
            return Http::response(['MediaContainer' => ['Dvr' => [
                ['key' => '42', 'lineup' => 'lineup://test', 'Lineup' => [
                    ['id' => 'lineup://tv.plex.providers.epg.xmltv/test', 'title' => 'XMLTV Guide'],
                ], 'Device' => [['key' => 'device-99']]],
            ]]]);
        }

        return Http::response([], 200);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->syncDvrChannels();

    expect($result['success'])->toBeTrue();
    expect($scanCalled)->toBeTrue();
});

it('verifies DVR sync status when all channels are in sync', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [
            ['device_key' => 'device-1', 'playlist_uuid' => 'playlist-abc'],
        ],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        // DVR detail (getDvrChannels) — must match before the broader /livetv/dvrs check
        if (preg_match('#/livetv/dvrs/42$#', parse_url($url, PHP_URL_PATH) ?? '')) {
            return Http::response(['MediaContainer' => ['Dvr' => [
                ['key' => '42', 'lineup' => 'lineup://test', 'Lineup' => [
                    ['id' => 'lineup://tv.plex.providers.epg.xmltv/test', 'title' => 'XMLTV Guide'],
                ], 'Device' => [['key' => 'device-1', 'ChannelMapping' => [
                    ['channelKey' => '10', 'deviceIdentifier' => '10', 'enabled' => '1', 'lineupIdentifier' => '10'],
                    ['channelKey' => '20', 'deviceIdentifier' => '20', 'enabled' => '1', 'lineupIdentifier' => '20'],
                ]]]],
            ]]]);
        }

        // DVR list (verifyDvrExists)
        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, 'lineupchannels')) {
            return Http::response(['MediaContainer' => ['Dvr' => [
                ['key' => '42', 'lineup' => 'lineup://test', 'Lineup' => [
                    ['id' => 'lineup://tv.plex.providers.epg.xmltv/test', 'title' => 'XMLTV Guide'],
                ], 'Device' => [['key' => 'device-1']]],
            ]]]);
        }

        // Lineup channels (EPG mapping)
        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            return Http::response(['MediaContainer' => ['Lineup' => [
                ['uuid' => 'lineup://test', 'type' => 'lineup', 'Channel' => [
                    ['identifier' => 'ch-10', 'channelVcn' => '10', 'key' => '10', 'title' => 'Channel One', 'callSign' => 'Ch One'],
                    ['identifier' => 'ch-20', 'channelVcn' => '20', 'key' => '20', 'title' => 'Channel Two', 'callSign' => 'Ch Two'],
                ]],
            ]]]);
        }

        // HDHR lineup (local)
        if (str_contains($url, 'hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '10', 'GuideName' => 'Channel One', 'URL' => 'http://test/1'],
                ['GuideNumber' => '20', 'GuideName' => 'Channel Two', 'URL' => 'http://test/2'],
            ]);
        }

        return Http::response([], 200);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->verifyDvrSync();

    expect($result['success'])->toBeTrue();
    expect($result['data']['status'])->toBe('ok');
    expect($result['data']['total_channels'])->toBe(2);
    expect($result['data']['total_in_plex'])->toBe(2);
    expect($result['data']['total_epg_mapped'])->toBe(2);
    expect($result['data']['total_epg_missing'])->toBe(0);
    expect($result['data']['summary'])->toContain('All 2 channels synchronized');
});

it('verifies DVR sync status when EPG mapping is missing', function () {
    $this->integration->update([
        'plex_dvr_id' => '42',
        'plex_dvr_tuners' => [
            ['device_key' => 'device-1', 'playlist_uuid' => 'playlist-abc'],
        ],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        // DVR detail (getDvrChannels)
        if (preg_match('#/livetv/dvrs/42$#', parse_url($url, PHP_URL_PATH) ?? '')) {
            return Http::response(['MediaContainer' => ['Dvr' => [
                ['key' => '42', 'lineup' => 'lineup://test', 'Lineup' => [
                    ['id' => 'lineup://tv.plex.providers.epg.xmltv/test', 'title' => 'XMLTV Guide'],
                ], 'Device' => [['key' => 'device-1', 'ChannelMapping' => [
                    ['channelKey' => '10', 'deviceIdentifier' => '10', 'enabled' => '1', 'lineupIdentifier' => '10'],
                    ['channelKey' => '20', 'deviceIdentifier' => '20', 'enabled' => '1', 'lineupIdentifier' => '20'],
                ]]]],
            ]]]);
        }

        // DVR list (verifyDvrExists)
        if (str_contains($url, '/livetv/dvrs') && ! str_contains($url, 'lineupchannels')) {
            return Http::response(['MediaContainer' => ['Dvr' => [
                ['key' => '42', 'lineup' => 'lineup://test', 'Lineup' => [
                    ['id' => 'lineup://tv.plex.providers.epg.xmltv/test', 'title' => 'XMLTV Guide'],
                ], 'Device' => [['key' => 'device-1']]],
            ]]]);
        }

        // Only one channel has EPG mapping
        if (str_contains($url, '/livetv/epg/lineupchannels')) {
            return Http::response(['MediaContainer' => ['Lineup' => [
                ['uuid' => 'lineup://test', 'type' => 'lineup', 'Channel' => [
                    ['identifier' => 'ch-10', 'channelVcn' => '10', 'key' => '10', 'title' => 'Channel One', 'callSign' => 'Ch One'],
                ]],
            ]]]);
        }

        if (str_contains($url, 'hdhr/lineup.json')) {
            return Http::response([
                ['GuideNumber' => '10', 'GuideName' => 'Channel One', 'URL' => 'http://test/1'],
                ['GuideNumber' => '20', 'GuideName' => 'Channel Two', 'URL' => 'http://test/2'],
            ]);
        }

        return Http::response([], 200);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->verifyDvrSync();

    expect($result['success'])->toBeTrue();
    expect($result['data']['status'])->toBe('warning');
    expect($result['data']['total_epg_missing'])->toBe(1);
    expect($result['data']['summary'])->toContain('missing EPG mapping');
});

it('verifies DVR sync returns not configured when no DVR registered', function () {
    $service = PlexManagementService::make($this->integration);
    $result = $service->verifyDvrSync();

    expect($result['success'])->toBeTrue();
    expect($result['data']['status'])->toBe('not_configured');
    expect($result['data']['summary'])->toContain('No DVR tuner registered');
});

it('verifies DVR sync detects stale DVR', function () {
    $this->integration->update([
        'plex_dvr_id' => '99',
        'plex_dvr_tuners' => [
            ['device_key' => 'device-1', 'playlist_uuid' => 'playlist-abc'],
        ],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        // Return empty DVRs — DVR 99 no longer exists
        if (str_contains($url, '/livetv/dvrs')) {
            return Http::response(['MediaContainer' => ['Dvr' => []]]);
        }

        return Http::response([], 200);
    });

    $service = PlexManagementService::make($this->integration);
    $result = $service->verifyDvrSync();

    expect($result['success'])->toBeTrue();
    expect($result['data']['status'])->toBe('error');
    expect($result['data']['summary'])->toContain('no longer exists');

    // Verify local state was cleaned up
    $this->integration->refresh();
    expect($this->integration->plex_dvr_id)->toBeNull();
});
