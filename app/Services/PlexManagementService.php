<?php

namespace App\Services;

use App\Facades\PlaylistFacade;
use App\Models\MediaServerIntegration;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlexManagementService
{
    protected MediaServerIntegration $integration;

    protected string $baseUrl;

    protected string $apiKey;

    public function __construct(MediaServerIntegration $integration)
    {
        if (! $integration->isPlex()) {
            throw new \InvalidArgumentException('PlexManagementService requires a Plex integration');
        }

        $this->integration = $integration;
        $this->baseUrl = $integration->base_url;
        $this->apiKey = $integration->api_key;
    }

    public static function make(MediaServerIntegration $integration): self
    {
        return new self($integration);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout(30)
            ->withHeaders([
                'X-Plex-Token' => $this->apiKey,
                'Accept' => 'application/json',
                'X-Plex-Client-Identifier' => 'm3u-editor',
                'X-Plex-Product' => 'm3u-editor',
            ]);
    }

    /**
     * Get Plex server information.
     *
     * @return array{success: bool, data?: array{name: string, version: string, platform: string, machine_id: string, transcoder: bool}, message?: string}
     */
    public function getServerInfo(): array
    {
        try {
            $response = $this->client()->get('/');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Server returned status: '.$response->status()];
            }

            $container = $response->json('MediaContainer', []);

            return [
                'success' => true,
                'data' => [
                    'name' => $container['friendlyName'] ?? 'Unknown',
                    'version' => $container['version'] ?? 'Unknown',
                    'platform' => $container['platform'] ?? 'Unknown',
                    'machine_id' => $container['machineIdentifier'] ?? '',
                    'transcoder' => (bool) ($container['transcoderActiveVideoSessions'] ?? false),
                ],
            ];
        } catch (Exception $e) {
            Log::warning('PlexManagementService: Failed to get server info', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get active sessions (currently streaming).
     *
     * Checks both /status/sessions (regular + Live TV playback) and
     * /transcode/sessions (active transcodes) for completeness.
     *
     * @return array{success: bool, data?: Collection, message?: string}
     */
    public function getActiveSessions(): array
    {
        try {
            $response = $this->client()->get('/status/sessions');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch sessions: '.$response->status()];
            }

            $container = $response->json('MediaContainer', []);

            // Plex may nest sessions under 'Metadata', 'Video', or 'Track'
            // depending on what is being played (movies, live tv, music).
            $metadata = $container['Metadata']
                ?? $container['Video']
                ?? $container['Track']
                ?? [];

            $reportedSize = (int) ($container['size'] ?? 0);

            if ($reportedSize > 0 && empty($metadata)) {
                Log::warning('PlexManagementService: Plex reports sessions but no Metadata/Video/Track key found', [
                    'integration_id' => $this->integration->id,
                    'reported_size' => $reportedSize,
                    'container_keys' => array_keys($container),
                ]);
            }

            $sessions = collect($metadata)
                ->map(fn (array $session) => [
                    'id' => $session['sessionKey'] ?? null,
                    'title' => $session['title'] ?? $session['grandparentTitle'] ?? 'Unknown',
                    'type' => $session['type'] ?? 'unknown',
                    'user' => $session['User']['title'] ?? 'Unknown',
                    'player' => $session['Player']['title'] ?? 'Unknown',
                    'state' => $session['Player']['state'] ?? $session['Session']['location'] ?? 'unknown',
                    'progress' => $session['viewOffset'] ?? 0,
                    'duration' => $session['duration'] ?? 0,
                    'transcode' => ! empty($session['TranscodeSession']),
                    'live' => ! empty($session['live']),
                ]);

            // Also merge in active transcode sessions that may not appear in /status/sessions
            $transcodeSessions = $this->getTranscodeSessions();
            if ($transcodeSessions->isNotEmpty()) {
                $existingIds = $sessions->pluck('id')->filter()->all();
                $transcodeSessions->each(function (array $ts) use ($sessions, $existingIds): void {
                    if (! in_array($ts['id'], $existingIds, true)) {
                        $sessions->push($ts);
                    }
                });
            }

            return ['success' => true, 'data' => $sessions];
        } catch (Exception $e) {
            Log::warning('PlexManagementService: Failed to get sessions', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get active transcode sessions from Plex.
     *
     * These may include Live TV streams being transcoded that don't
     * always appear in /status/sessions.
     */
    protected function getTranscodeSessions(): Collection
    {
        try {
            $response = $this->client()->get('/transcode/sessions');

            if (! $response->successful()) {
                return collect();
            }

            $container = $response->json('MediaContainer', []);
            $metadata = $container['Metadata'] ?? $container['TranscodeSession'] ?? [];

            return collect($metadata)
                ->filter(fn (array $session) => ! empty($session['sessionKey'] ?? $session['key'] ?? null))
                ->map(fn (array $session) => [
                    'id' => $session['sessionKey'] ?? $session['key'] ?? null,
                    'title' => $session['title'] ?? $session['grandparentTitle'] ?? 'Transcoding',
                    'type' => $session['type'] ?? 'transcode',
                    'user' => $session['User']['title'] ?? 'Unknown',
                    'player' => $session['Player']['title'] ?? 'Unknown',
                    'state' => $session['Player']['state'] ?? 'transcoding',
                    'progress' => $session['viewOffset'] ?? 0,
                    'duration' => $session['duration'] ?? 0,
                    'transcode' => true,
                    'live' => ! empty($session['live']),
                ]);
        } catch (Exception $e) {
            Log::debug('PlexManagementService: Failed to get transcode sessions', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Terminate an active Plex session.
     */
    public function terminateSession(string $sessionId, string $reason = 'Session terminated by m3u-editor'): array
    {
        try {
            $response = $this->client()->get('/status/sessions/terminate/player', [
                'sessionId' => $sessionId,
                'reason' => $reason,
            ]);

            return [
                'success' => $response->successful(),
                'message' => $response->successful() ? 'Session terminated' : 'Failed: '.$response->status(),
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get all DVR configurations from Plex.
     *
     * @return array{success: bool, data?: Collection, message?: string}
     */
    public function getDvrs(): array
    {
        try {
            $response = $this->client()->get('/livetv/dvrs');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch DVRs: '.$response->status()];
            }

            $dvrs = collect($response->json('MediaContainer.Dvr', []))
                ->map(fn (array $dvr) => [
                    'id' => $dvr['key'] ?? null,
                    'uuid' => $dvr['uuid'] ?? null,
                    'title' => $dvr['title'] ?? 'DVR',
                    'lineup' => $dvr['lineup'] ?? null,
                    'language' => $dvr['language'] ?? null,
                    'country' => $dvr['country'] ?? null,
                    'device_count' => count($dvr['Device'] ?? []),
                    'devices' => collect($dvr['Device'] ?? [])->map(fn (array $device) => [
                        'key' => $device['key'] ?? null,
                        'uri' => $device['uri'] ?? null,
                        'make' => $device['make'] ?? 'Unknown',
                        'model' => $device['model'] ?? 'Unknown',
                        'source' => $device['source'] ?? null,
                        'tuners' => $device['tuners'] ?? 0,
                    ])->toArray(),
                ]);

            return ['success' => true, 'data' => $dvrs];
        } catch (Exception $e) {
            Log::warning('PlexManagementService: Failed to get DVRs', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get all grabber devices registered in Plex.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function getDevices(): array
    {
        try {
            $response = $this->client()->get('/media/grabbers/devices');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch devices: '.$response->status()];
            }

            return ['success' => true, 'data' => $response->json('MediaContainer.Device', [])];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Try to create a grabber device in Plex using multiple API variations.
     *
     * Plex accepts different methods/params depending on version. We try all known
     * variations (mirroring Headendarr's try_create_device approach).
     *
     * @param  array{DeviceID?: string, DeviceAuth?: string}  $discoverPayload
     */
    protected function tryCreateDevice(string $hdhrBaseUrl, array $discoverPayload = []): bool
    {
        $candidates = [
            ['POST', '/media/grabbers/devices', ['uri' => $hdhrBaseUrl]],
            ['PUT', '/media/grabbers/devices', ['uri' => $hdhrBaseUrl]],
            ['POST', '/media/grabbers/devices', ['url' => $hdhrBaseUrl]],
            ['POST', '/media/grabbers/devices', [
                'uri' => $hdhrBaseUrl,
                'deviceId' => $discoverPayload['DeviceID'] ?? '',
                'deviceAuth' => $discoverPayload['DeviceAuth'] ?? '',
            ]],
        ];

        foreach ($candidates as [$method, $path, $query]) {
            try {
                $url = $path.'?'.http_build_query($query);
                $response = match ($method) {
                    'PUT' => $this->client()->withBody('')->put($url),
                    default => $this->client()->withBody('')->post($url),
                };

                if ($response->successful()) {
                    return true;
                }

                Log::debug('PlexManagementService: Device create attempt failed', [
                    'method' => $method,
                    'path' => $path,
                    'query' => $query,
                    'status' => $response->status(),
                ]);
            } catch (Exception $e) {
                Log::debug('PlexManagementService: Device create attempt exception', [
                    'method' => $method,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return false;
    }

    /**
     * Find a device in the device list matching the given URI.
     *
     * @param  array  $devices  List of Plex device arrays
     */
    protected function findDeviceByUri(array $devices, string $expectedUri): ?array
    {
        foreach ($devices as $device) {
            if (! is_array($device)) {
                continue;
            }
            $uri = trim($device['uri'] ?? '');
            if ($uri === $expectedUri) {
                return $device;
            }
        }

        return null;
    }

    /**
     * Collect all devices from both /media/grabbers/devices and DVR device lists.
     *
     * @param  array  $grabberDevices  Devices from GET /media/grabbers/devices
     * @param  array  $dvrs  DVRs from GET /livetv/dvrs
     */
    protected function flattenAllDevices(array $grabberDevices, array $dvrs): array
    {
        $devices = [];
        $seen = [];

        foreach ($grabberDevices as $device) {
            if (! is_array($device)) {
                continue;
            }
            $key = $device['key'] ?? '';
            if ($key && ! isset($seen[$key])) {
                $devices[] = $device;
                $seen[$key] = true;
            }
        }

        foreach ($dvrs as $dvr) {
            if (! is_array($dvr)) {
                continue;
            }
            $dvrKey = $dvr['key'] ?? '';
            foreach ($dvr['Device'] ?? [] as $device) {
                if (! is_array($device)) {
                    continue;
                }
                $key = $device['key'] ?? '';
                if ($key && ! isset($seen[$key])) {
                    if (! isset($device['parentID']) && $dvrKey) {
                        $device['parentID'] = $dvrKey;
                    }
                    $devices[] = $device;
                    $seen[$key] = true;
                }
            }
        }

        return $devices;
    }

    /**
     * Build a Plex XMLTV lineup ID from an EPG URL and guide title.
     */
    protected function buildLineupId(string $epgUrl, string $guideTitle = 'm3u-editor XMLTV Guide'): string
    {
        return 'lineup://tv.plex.providers.epg.xmltv/'.rawurlencode($epgUrl).'#'.rawurlencode($guideTitle);
    }

    /**
     * Resolve the canonical playlist URLs used across the application.
     *
     * @return array{hdhr?: string, epg?: string}|null
     */
    protected function resolvePlaylistUrls(string $playlistUuid): ?array
    {
        $playlist = PlaylistFacade::resolvePlaylistByUuid($playlistUuid);

        if ($playlist) {
            return PlaylistFacade::getUrls($playlist);
        }

        return [
            'hdhr' => route('playlist.hdhr.overview', ['uuid' => $playlistUuid]),
            'epg' => route('epg.generate', ['uuid' => $playlistUuid]),
        ];
    }

    /**
     * Fetch discover.json from the same HDHR base URL we register in Plex.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    protected function fetchDiscoverPayload(string $hdhrBaseUrl): array
    {
        try {
            $discoverUrl = rtrim($hdhrBaseUrl, '/').'/discover.json';
            $response = Http::timeout(10)->get($discoverUrl);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json() ?? [],
                ];
            }

            return [
                'success' => false,
                'message' => "Could not reach HDHR discover.json (HTTP {$response->status()}) at {$discoverUrl}.",
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Could not reach HDHR discover.json: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Register an m3u-editor playlist's HDHR endpoint as a DVR tuner in Plex.
     *
     * Follows the correct Plex DVR API flow (as implemented by Headendarr):
     * 1. Fetch discover.json from the HDHR endpoint to get device info
     * 2. Create the device in Plex via POST /media/grabbers/devices
     * 3. Find the created device by URI
     * 4. Create a DVR entry linking device + XMLTV lineup
     * 5. Attach device to DVR and store DVR ID
     *
     * @param  string  $hdhrBaseUrl  The HDHR base URL reachable by Plex (e.g., http://192.168.1.x:36400/{uuid}/hdhr)
     * @param  string  $epgUrl  The EPG XML URL reachable by Plex
     * @param  string  $country  Country code for DVR (default: de)
     * @param  string  $language  Language code for DVR (default: de)
     */
    public function addDvrDevice(string $hdhrBaseUrl, string $epgUrl, string $country = 'de', string $language = 'de', ?string $playlistUuid = null): array
    {
        try {
            // Step 1: Fetch discover.json to get device info (DeviceID, DeviceAuth, etc.)
            // Use the exact HDHR URL that will be registered in Plex so this
            // follows the same public URL path used elsewhere in the app.
            $discoverResult = $this->fetchDiscoverPayload($hdhrBaseUrl);
            if (! $discoverResult['success']) {
                return [
                    'success' => false,
                    'message' => $discoverResult['message'],
                ];
            }
            $discoverPayload = $discoverResult['data'] ?? [];

            // Step 2: Create the device in Plex
            $created = $this->tryCreateDevice($hdhrBaseUrl, $discoverPayload);
            if (! $created) {
                Log::warning('PlexManagementService: All device creation attempts failed', [
                    'integration_id' => $this->integration->id,
                    'hdhr_url' => $hdhrBaseUrl,
                ]);

                return [
                    'success' => false,
                    'message' => 'Plex rejected the device creation. Ensure the HDHR URL is reachable from Plex and returns valid discover.json/lineup.json.',
                ];
            }

            // Step 3: Find the created device by URI
            $devicesResult = $this->getDevices();
            $dvrsResult = $this->getDvrs();

            $grabberDevices = $devicesResult['success'] ? ($devicesResult['data'] ?? []) : [];
            $dvrList = $dvrsResult['success'] ? ($dvrsResult['data'] ?? []) : [];

            // For flattenAllDevices we need raw DVR data, re-fetch
            $rawDvrs = [];
            if ($dvrsResult['success']) {
                $rawDvrsResponse = $this->client()->get('/livetv/dvrs');
                $rawDvrs = $rawDvrsResponse->json('MediaContainer.Dvr', []);
            }

            $allDevices = $this->flattenAllDevices($grabberDevices, $rawDvrs);
            $targetDevice = $this->findDeviceByUri($allDevices, $hdhrBaseUrl);

            if (! $targetDevice) {
                return [
                    'success' => false,
                    'message' => 'Device was created but could not be found in Plex. Try again or check Plex logs.',
                ];
            }

            $deviceKey = $targetDevice['key'] ?? null;
            $deviceUuid = $targetDevice['uuid'] ?? null;

            if (! $deviceKey || ! $deviceUuid) {
                return [
                    'success' => false,
                    'message' => 'Device found but missing key/uuid. Plex may need a restart.',
                ];
            }

            // Step 4: Check for existing DVR or create a new one
            $dvrKey = $targetDevice['parentID'] ?? null;

            // Also check if we already have a DVR stored locally
            if (! $dvrKey && $this->integration->plex_dvr_id) {
                // Verify the stored DVR still exists in Plex
                if ($this->verifyDvrExists($this->integration->plex_dvr_id)) {
                    $dvrKey = $this->integration->plex_dvr_id;
                }
            }

            // Search all existing DVRs in Plex for one that already has our specific device
            // (matched by device key or UUID — NOT by URI pattern, to avoid hijacking real HDHR devices)
            if (! $dvrKey && $deviceKey) {
                try {
                    $existingDvrsResponse = $this->client()->get('/livetv/dvrs');
                    $existingDvrs = $existingDvrsResponse->json('MediaContainer.Dvr', []);
                    foreach ($existingDvrs as $dvr) {
                        foreach ($dvr['Device'] ?? [] as $dvrDevice) {
                            if (($dvrDevice['key'] ?? '') === $deviceKey || ($dvrDevice['uuid'] ?? '') === $deviceUuid) {
                                $dvrKey = $dvr['key'] ?? null;
                                break 2;
                            }
                        }
                    }
                } catch (Exception) {
                    // Non-critical: proceed with creating a new DVR
                }
            }

            if (! $dvrKey) {
                // No existing DVR — create one
                $lineupId = $this->buildLineupId($epgUrl);
                $createDvrResponse = $this->client()->withBody('')->post('/livetv/dvrs?'.http_build_query([
                    'device' => $deviceUuid,
                    'lineup' => $lineupId,
                    'lineupTitle' => 'm3u-editor XMLTV Guide',
                    'country' => $country,
                    'language' => $language,
                ]));

                if (! $createDvrResponse->successful()) {
                    Log::warning('PlexManagementService: DVR creation failed', [
                        'integration_id' => $this->integration->id,
                        'status' => $createDvrResponse->status(),
                        'response' => $createDvrResponse->body(),
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Device registered but DVR creation failed (HTTP '.$createDvrResponse->status().').',
                    ];
                }

                // Re-fetch DVRs to find the new DVR key
                $dvrsRefresh = $this->client()->get('/livetv/dvrs');
                $freshDvrs = $dvrsRefresh->json('MediaContainer.Dvr', []);
                foreach ($freshDvrs as $dvr) {
                    foreach ($dvr['Device'] ?? [] as $dvrDevice) {
                        if (($dvrDevice['uuid'] ?? '') === $deviceUuid || ($dvrDevice['key'] ?? '') === $deviceKey) {
                            $dvrKey = $dvr['key'] ?? null;
                            break 2;
                        }
                    }
                }

                // If still no DVR key, use the first DVR
                if (! $dvrKey && ! empty($freshDvrs)) {
                    $dvrKey = $freshDvrs[0]['key'] ?? null;
                }
            }

            // Step 5: Attach device to DVR (if not already attached)
            if ($dvrKey && $deviceKey && ! ($targetDevice['parentID'] ?? null)) {
                $this->client()->withBody('')->put("/livetv/dvrs/{$dvrKey}/devices/{$deviceKey}");
            }

            // Store the DVR ID and add tuner to the tuners array
            if ($dvrKey) {
                $tuners = $this->integration->plex_dvr_tuners ?? [];
                if ($playlistUuid && $deviceKey) {
                    $tuners[] = [
                        'device_key' => $deviceKey,
                        'playlist_uuid' => $playlistUuid,
                    ];
                }
                $this->integration->update([
                    'plex_dvr_id' => $dvrKey,
                    'plex_dvr_tuners' => $tuners,
                ]);
            }

            // Sync channel map after registration.
            // Plex needs time to download and parse the XMLTV guide after
            // the DVR/lineup is created — without this wait the lineup
            // channels endpoint returns empty or stale data.
            sleep(8);
            $syncResult = $this->syncDvrChannelsForTuner($deviceKey, $playlistUuid);
            $channelInfo = $syncResult['success'] ? " ({$syncResult['mapped_channels']} channels mapped)" : '';

            return [
                'success' => true,
                'message' => 'HDHR device registered and DVR configured in Plex'.($dvrKey ? " (DVR ID: {$dvrKey})" : '').$channelInfo,
                'dvr_id' => $dvrKey,
            ];
        } catch (Exception $e) {
            Log::error('PlexManagementService: Failed to add DVR device', [
                'integration_id' => $this->integration->id,
                'hdhr_url' => $hdhrBaseUrl,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Configure DVR preferences (EPG refresh settings, recording settings).
     *
     * Plex requires ALL settings to be sent in a single PUT request.
     * Fetches current settings first, merges overrides, then sends them all.
     *
     * @param  array<string, string>  $settings
     */
    public function configureDvrPrefs(string $dvrId, array $settings = []): array
    {
        try {
            $currentSettings = $this->fetchCurrentDvrSettings($dvrId);

            $prefs = array_merge($currentSettings, $settings);

            $response = $this->client()
                ->withBody('')
                ->put("/livetv/dvrs/{$dvrId}/prefs?".http_build_query($prefs));

            Log::debug('PlexManagementService: configureDvrPrefs response', [
                'dvr_id' => $dvrId,
                'status' => $response->status(),
                'prefs' => array_keys($prefs),
            ]);

            return [
                'success' => $response->successful(),
                'message' => $response->successful()
                    ? 'DVR preferences updated'
                    : 'Failed to update DVR preferences: HTTP '.$response->status(),
            ];
        } catch (Exception $e) {
            Log::error('PlexManagementService: configureDvrPrefs exception', [
                'dvr_id' => $dvrId,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Fetch current DVR settings from Plex and return as id => value map.
     *
     * @return array<string, string>
     */
    protected function fetchCurrentDvrSettings(string $dvrId): array
    {
        $response = $this->client()->get("/livetv/dvrs/{$dvrId}");

        if (! $response->successful()) {
            return $this->defaultDvrSettings();
        }

        $dvr = $response->json('MediaContainer.Dvr.0', []);
        $settings = [];

        foreach ($dvr['Setting'] ?? [] as $setting) {
            $settings[$setting['id']] = $setting['value'] ?? $setting['default'] ?? '';
        }

        return $settings ?: $this->defaultDvrSettings();
    }

    /**
     * Default DVR settings matching Plex defaults.
     *
     * @return array<string, string>
     */
    protected function defaultDvrSettings(): array
    {
        return [
            'minVideoQuality' => '0',
            'replaceLowerQuality' => 'false',
            'recordPartials' => 'true',
            'startOffsetMinutes' => '0',
            'endOffsetMinutes' => '0',
            'useUmp' => 'false',
            'postprocessingScript' => '',
            'comskipMethod' => '0',
            'ButlerTaskRefreshEpgGuides' => 'true',
            'mediaProviderEpgXmltvGuideRefreshStartTime' => '10',
            'xmltvCustomRefreshInHours' => '24',
            'kidsCategories' => 'kids',
            'newsCategories' => 'news',
            'sportsCategories' => 'sports',
        ];
    }

    /**
     * Refresh EPG guides: ensure auto-refresh is enabled and trigger an
     * immediate re-fetch so Plex picks up changed channel IDs / programmes.
     */
    public function refreshGuides(): array
    {
        try {
            $dvrId = $this->integration->plex_dvr_id;
            if (! $dvrId) {
                return ['success' => false, 'message' => 'No DVR registered. Register a tuner first.'];
            }

            $prefsResult = $this->configureDvrPrefs($dvrId, [
                'ButlerTaskRefreshEpgGuides' => 'true',
                'xmltvCustomRefreshInHours' => '12',
            ]);

            Log::debug('PlexManagementService: refreshGuides result', [
                'dvr_id' => $dvrId,
                'success' => $prefsResult['success'],
            ]);

            if (! $prefsResult['success']) {
                return $prefsResult;
            }

            // Trigger an immediate EPG refresh via butler task
            try {
                $this->client()->withBody('')->post('/butler/RefreshEPGGuides');
            } catch (Exception) {
                // Non-critical: will refresh on next scheduled cycle
            }

            return ['success' => true, 'message' => 'EPG guide refresh triggered. Plex will re-fetch the guide data shortly.'];
        } catch (Exception $e) {
            Log::error('PlexManagementService: refreshGuides exception', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Remove a DVR configuration from Plex.
     */
    public function removeDvr(string $dvrId): array
    {
        try {
            $response = $this->client()->delete("/livetv/dvrs/{$dvrId}");

            // Treat 404 as success — DVR was already deleted in Plex
            $deleted = $response->successful() || $response->status() === 404;

            if ($deleted && (string) $this->integration->plex_dvr_id === $dvrId) {
                $this->integration->update([
                    'plex_dvr_id' => null,
                    'plex_dvr_tuners' => null,
                ]);
            }

            return [
                'success' => $deleted,
                'message' => $deleted ? 'DVR removed from Plex' : 'Failed: '.$response->status(),
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get channel lineup for a specific DVR.
     *
     * Fetches the DVR detail and extracts ChannelMapping entries from each Device.
     * The /livetv/dvrs/{id}/channels endpoint is unreliable (often returns 404),
     * so we use the DVR detail endpoint which always includes device channel mappings.
     */
    public function getDvrChannels(string $dvrId): array
    {
        try {
            $response = $this->client()->get("/livetv/dvrs/{$dvrId}");

            if ($response->status() === 404) {
                return ['success' => true, 'data' => collect()];
            }

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch DVR details: '.$response->status()];
            }

            $dvr = $response->json('MediaContainer.Dvr.0') ?? $response->json('MediaContainer.Dvr') ?? [];

            // Handle single DVR object (not wrapped in array)
            if (isset($dvr['key'])) {
                $dvr = $dvr;
            } elseif (is_array($dvr) && ! empty($dvr) && isset($dvr[0])) {
                $dvr = $dvr[0];
            }

            $devices = $dvr['Device'] ?? [];
            if (! is_array($devices) || (! empty($devices) && ! array_is_list($devices))) {
                $devices = [$devices];
            }

            $channels = collect();
            foreach ($devices as $device) {
                $mappings = $device['ChannelMapping'] ?? [];
                if (! is_array($mappings) || (! empty($mappings) && ! array_is_list($mappings))) {
                    $mappings = [$mappings];
                }

                foreach ($mappings as $mapping) {
                    if (! is_array($mapping)) {
                        continue;
                    }
                    $channels->push([
                        'id' => $mapping['channelKey'] ?? null,
                        'number' => $mapping['channelKey'] ?? null,
                        'device_identifier' => $mapping['deviceIdentifier'] ?? null,
                        'lineup_identifier' => $mapping['lineupIdentifier'] ?? null,
                        'enabled' => ! in_array(trim($mapping['enabled'] ?? '1'), ['0', 'false']),
                    ]);
                }
            }

            return ['success' => true, 'data' => $channels];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get DVR recordings (scheduled and completed).
     *
     * @return array{success: bool, data?: Collection, message?: string}
     */
    public function getRecordings(): array
    {
        try {
            $response = $this->client()->get('/media/subscriptions');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch recordings: '.$response->status()];
            }

            $recordings = collect($response->json('MediaContainer.MediaSubscription', []))
                ->map(fn (array $rec) => [
                    'id' => $rec['key'] ?? null,
                    'title' => $rec['title'] ?? 'Unknown',
                    'type' => $rec['type'] ?? 'unknown',
                    'target_library' => $rec['targetLibrarySectionID'] ?? null,
                    'created_at' => isset($rec['createdAt']) ? date('Y-m-d H:i:s', $rec['createdAt']) : null,
                ]);

            return ['success' => true, 'data' => $recordings];
        } catch (Exception $e) {
            Log::warning('PlexManagementService: Failed to get recordings', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Cancel/delete a DVR recording subscription.
     */
    public function cancelRecording(string $subscriptionId): array
    {
        try {
            $response = $this->client()->delete("/media/subscriptions/{$subscriptionId}");

            return [
                'success' => $response->successful(),
                'message' => $response->successful()
                    ? 'Recording cancelled'
                    : 'Failed to cancel recording: '.$response->status(),
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get all Plex libraries (not just movies/shows).
     *
     * @return array{success: bool, data?: Collection, message?: string}
     */
    public function getAllLibraries(): array
    {
        try {
            $response = $this->client()->get('/library/sections');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch libraries: '.$response->status()];
            }

            $libraries = collect($response->json('MediaContainer.Directory', []))
                ->map(fn (array $lib) => [
                    'id' => $lib['key'] ?? null,
                    'title' => $lib['title'] ?? 'Unknown',
                    'type' => $lib['type'] ?? 'unknown',
                    'agent' => $lib['agent'] ?? null,
                    'scanner' => $lib['scanner'] ?? null,
                    'language' => $lib['language'] ?? null,
                    'refreshing' => (bool) ($lib['refreshing'] ?? false),
                    'locations' => collect($lib['Location'] ?? [])->pluck('path')->toArray(),
                    'created_at' => isset($lib['createdAt']) ? date('Y-m-d H:i:s', $lib['createdAt']) : null,
                    'scanned_at' => isset($lib['scannedAt']) ? date('Y-m-d H:i:s', $lib['scannedAt']) : null,
                ]);

            return ['success' => true, 'data' => $libraries];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Trigger a library scan/refresh in Plex.
     */
    public function scanLibrary(string $sectionKey): array
    {
        try {
            $response = $this->client()->get("/library/sections/{$sectionKey}/refresh");

            return [
                'success' => $response->successful(),
                'message' => $response->successful()
                    ? 'Library scan started'
                    : 'Failed to start scan: '.$response->status(),
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Scan all libraries in Plex.
     */
    public function scanAllLibraries(): array
    {
        $result = $this->getAllLibraries();
        if (! $result['success']) {
            return $result;
        }

        $scanned = 0;
        $errors = [];

        foreach ($result['data'] as $library) {
            $scanResult = $this->scanLibrary($library['id']);
            if ($scanResult['success']) {
                $scanned++;
            } else {
                $errors[] = $library['title'];
            }
        }

        return [
            'success' => $scanned > 0,
            'message' => "Scan started for {$scanned} libraries".(! empty($errors) ? '. Failed: '.implode(', ', $errors) : ''),
        ];
    }

    /**
     * Get Plex server preferences/settings.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function getServerPreferences(): array
    {
        try {
            $response = $this->client()->get('/:/prefs');

            if (! $response->successful()) {
                return ['success' => false, 'message' => 'Failed to fetch preferences: '.$response->status()];
            }

            $settings = collect($response->json('MediaContainer.Setting', []))
                ->keyBy('id')
                ->map(fn (array $setting) => [
                    'id' => $setting['id'],
                    'label' => $setting['label'] ?? $setting['id'],
                    'value' => $setting['value'] ?? null,
                    'default' => $setting['default'] ?? null,
                    'type' => $setting['type'] ?? 'string',
                    'hidden' => (bool) ($setting['hidden'] ?? false),
                    'group' => $setting['group'] ?? 'general',
                ]);

            // Return DVR-relevant preferences
            $dvrKeys = [
                'DvrIncrementalEpgLoader',
                'DvrComskipRemoveIntermediates',
                'DvrComskipRemoveOriginal',
                'DvrComskipEnabled',
            ];

            $dvrPrefs = $settings->only($dvrKeys);

            return ['success' => true, 'data' => $dvrPrefs->toArray()];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get a summary of the Plex server state for dashboard display.
     *
     * @return array{success: bool, data?: array, message?: string}
     */
    public function getDashboardSummary(): array
    {
        $serverInfo = $this->getServerInfo();
        if (! $serverInfo['success']) {
            return $serverInfo;
        }

        $sessions = $this->getActiveSessions();
        $dvrs = $this->getDvrs();
        $libraries = $this->getAllLibraries();

        return [
            'success' => true,
            'data' => [
                'server' => $serverInfo['data'],
                'active_sessions' => $sessions['success'] ? $sessions['data']->count() : 0,
                'sessions' => $sessions['success'] ? $sessions['data']->toArray() : [],
                'dvr_count' => $dvrs['success'] ? $dvrs['data']->count() : 0,
                'dvrs' => $dvrs['success'] ? $dvrs['data']->toArray() : [],
                'library_count' => $libraries['success'] ? $libraries['data']->count() : 0,
                'libraries' => $libraries['success'] ? $libraries['data']->toArray() : [],
            ],
        ];
    }

    /**
     * Sync the DVR channel map for all tuners.
     *
     * Iterates over each registered tuner and syncs its channel map
     * with the current HDHR lineup.
     *
     * @return array{success: bool, message: string, mapped_channels?: int, changed?: bool}
     */
    public function syncDvrChannels(): array
    {
        $tuners = $this->integration->plex_dvr_tuners ?? [];
        $dvrId = $this->integration->plex_dvr_id;

        if (! $dvrId || empty($tuners)) {
            return ['success' => false, 'message' => 'DVR not fully configured. Register a DVR tuner first.'];
        }

        // Verify the DVR still exists in Plex before syncing
        if (! $this->verifyDvrExists($dvrId)) {
            return ['success' => false, 'message' => 'DVR no longer exists in Plex. Local state has been cleaned up. Please re-register the DVR tuner.'];
        }

        // Trigger EPG refresh FIRST so Plex re-fetches the XMLTV guide before we
        // try to match HDHR channels to lineup channels.  Without this, Plex may
        // still have stale guide data (e.g. old channel IDs from before a recount)
        // and the channel map will reference non-existent lineup entries.
        $epgRefreshed = false;
        try {
            $response = $this->client()->withBody('')->post('/butler/RefreshEPGGuides');
            $epgRefreshed = $response->successful();
        } catch (Exception) {
            // Non-critical: will proceed with possibly stale guide data
        }

        // Give Plex time to re-download and parse the XMLTV guide.
        // The butler task is async — without this delay, lineup channels
        // would still reflect the old guide content.
        if ($epgRefreshed) {
            sleep(8);
        }

        $totalMapped = 0;
        $anyChanged = false;
        $errors = [];

        foreach ($tuners as $tuner) {
            $deviceKey = $tuner['device_key'] ?? null;
            $playlistUuid = $tuner['playlist_uuid'] ?? null;
            if (! $deviceKey || ! $playlistUuid) {
                continue;
            }

            $result = $this->syncDvrChannelsForTuner($deviceKey, $playlistUuid);
            if ($result['success']) {
                $totalMapped += $result['mapped_channels'] ?? 0;
                $anyChanged = $anyChanged || ($result['changed'] ?? false);
            } else {
                $errors[] = "{$playlistUuid}: {$result['message']}";
            }
        }

        if (! empty($errors) && $totalMapped === 0) {
            return ['success' => false, 'message' => implode('; ', $errors)];
        }

        $tunerCount = count($tuners);
        $message = $anyChanged
            ? "{$totalMapped} channels synced across {$tunerCount} tuner(s)"
            : "{$totalMapped} channels in sync across {$tunerCount} tuner(s)";

        if ($epgRefreshed) {
            $message .= ' — EPG refresh triggered';
        }

        return [
            'success' => true,
            'message' => $message,
            'mapped_channels' => $totalMapped,
            'changed' => $anyChanged,
        ];
    }

    /**
     * Verify that the stored DVR still exists in Plex.
     *
     * If it doesn't, clean up the stale local state and return false.
     */
    protected function verifyDvrExists(string $dvrId): bool
    {
        try {
            $response = $this->client()->get('/livetv/dvrs');
            if (! $response->successful()) {
                return true; // Assume it exists if we can't check
            }

            $dvrs = $response->json('MediaContainer.Dvr', []);
            foreach ($dvrs as $dvr) {
                if ((string) ($dvr['key'] ?? '') === $dvrId) {
                    return true;
                }
            }

            // DVR not found — clean up local state
            Log::warning('PlexManagementService: DVR no longer exists in Plex, cleaning up', [
                'integration_id' => $this->integration->id,
                'dvr_id' => $dvrId,
            ]);

            $this->integration->update([
                'plex_dvr_id' => null,
                'plex_dvr_tuners' => null,
            ]);

            return false;
        } catch (Exception $e) {
            return true; // Assume it exists on network errors
        }
    }

    /**
     * Force Plex to re-scan the HDHR lineup device so it discovers
     * updated channel numbers and guide data.
     *
     * @return bool Whether the refresh was successful
     */
    protected function refreshLineupDevice(string $deviceKey): bool
    {
        try {
            // Trigger a channel scan on the device — Plex will re-fetch lineup.json
            $response = $this->client()->withBody('')->post(
                "/media/grabbers/devices/{$deviceKey}/scan"
            );

            if ($response->successful()) {
                return true;
            }

            // Fallback: try refreshing via the DVR scan endpoint
            $dvrId = $this->integration->plex_dvr_id;
            if ($dvrId) {
                $response = $this->client()->withBody('')->post("/livetv/dvrs/{$dvrId}/scan");

                return $response->successful();
            }

            return false;
        } catch (Exception $e) {
            Log::debug('PlexManagementService: Lineup device refresh failed (non-critical)', [
                'device_key' => $deviceKey,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Sync the channel map for a single tuner (device + playlist).
     *
     * @return array{success: bool, message: string, mapped_channels?: int, changed?: bool}
     */
    public function syncDvrChannelsForTuner(string $deviceKey, string $playlistUuid): array
    {
        try {
            $urls = $this->resolvePlaylistUrls($playlistUuid);
            if (! $urls || empty($urls['hdhr'])) {
                return ['success' => false, 'message' => 'Could not resolve playlist HDHR URL.'];
            }

            // Fetch current HDHR lineup from our own endpoint
            $lineupUrl = rtrim($urls['hdhr'], '/').'/lineup.json';
            $lineupResponse = Http::timeout(15)->get($lineupUrl);

            if (! $lineupResponse->successful()) {
                return ['success' => false, 'message' => 'Could not fetch HDHR lineup (HTTP '.$lineupResponse->status().')'];
            }

            $hdhrLineup = $lineupResponse->json();
            if (! is_array($hdhrLineup)) {
                return ['success' => false, 'message' => 'HDHR lineup response is not a valid array'];
            }

            // Get the lineup ID directly from the Plex DVR data (most reliable)
            $lineupId = $this->getDvrLineupId();

            if (! $lineupId) {
                // Fallback: rebuild from our known URL
                $epgUrl = $urls['epg'] ?? null;
                if (! $epgUrl) {
                    return ['success' => false, 'message' => 'Could not resolve playlist EPG URL.'];
                }
                $lineupId = $this->buildLineupId($epgUrl);
                Log::warning('PlexManagementService: Could not get lineup ID from DVR, using fallback', [
                    'integration_id' => $this->integration->id,
                    'fallback_lineup_id' => $lineupId,
                ]);
            }

            // Force Plex to re-scan the HDHR device so it picks up channel number changes.
            // Without this, Plex's lineup channels endpoint returns stale data and
            // EPG-to-channel mapping breaks when numbers are reassigned.
            $this->refreshLineupDevice($deviceKey);

            // Give Plex a moment to process the device scan before fetching lineup channels.
            // The scan endpoint is async — without this delay, Plex returns stale/empty data.
            sleep(3);

            // Fetch Plex's current knowledge of the lineup channels
            $lineupChannelsResponse = $this->client()->get('/livetv/epg/lineupchannels', [
                'lineup' => $lineupId,
            ]);

            if (! $lineupChannelsResponse->successful()) {
                Log::warning('PlexManagementService: Failed to fetch lineup channels', [
                    'integration_id' => $this->integration->id,
                    'device_key' => $deviceKey,
                    'lineup_id' => $lineupId,
                    'status' => $lineupChannelsResponse->status(),
                ]);

                return ['success' => false, 'message' => 'Failed to fetch lineup channels from Plex (HTTP '.$lineupChannelsResponse->status().')'];
            }

            // Build the channel map payload
            $lineupChannelsPayload = $lineupChannelsResponse->json('MediaContainer') ?? [];

            Log::debug('PlexManagementService: Lineup channels response', [
                'integration_id' => $this->integration->id,
                'lineup_id' => $lineupId,
                'media_container_keys' => array_keys($lineupChannelsPayload),
                'channel_count' => is_array($lineupChannelsPayload['Lineup'] ?? $lineupChannelsPayload['Channel'] ?? null)
                    ? count($lineupChannelsPayload['Lineup'] ?? $lineupChannelsPayload['Channel'] ?? [])
                    : 0,
            ]);
            $channelMapPayload = $this->buildChannelMapPayload($hdhrLineup, $lineupChannelsPayload);

            if (empty($channelMapPayload['enabledIds'])) {
                return [
                    'success' => true,
                    'message' => 'No channels to map (lineup may be empty)',
                    'mapped_channels' => 0,
                    'changed' => false,
                ];
            }

            // Check current device channel mapping
            $devicesResult = $this->getDevices();
            $rawDvrsResponse = $this->client()->get('/livetv/dvrs');
            $rawDvrs = $rawDvrsResponse->json('MediaContainer.Dvr', []);
            $allDevices = $this->flattenAllDevices(
                $devicesResult['success'] ? ($devicesResult['data'] ?? []) : [],
                $rawDvrs
            );

            $currentDevice = null;
            foreach ($allDevices as $device) {
                if (($device['key'] ?? '') === $deviceKey) {
                    $currentDevice = $device;
                    break;
                }
            }

            $currentMappedIds = $this->extractChannelMappingIds($currentDevice ?? []);
            $desiredMappedIds = $channelMapPayload['enabledIds'];
            sort($currentMappedIds);
            sort($desiredMappedIds);

            if ($currentMappedIds === $desiredMappedIds) {
                return [
                    'success' => true,
                    'message' => count($desiredMappedIds).' channels in sync',
                    'mapped_channels' => count($desiredMappedIds),
                    'changed' => false,
                ];
            }

            // Update the channel map
            $updateResponse = $this->client()->withBody('')->put(
                "/media/grabbers/devices/{$deviceKey}/channelmap?".http_build_query($channelMapPayload['payload'])
            );

            if (! $updateResponse->successful()) {
                return ['success' => false, 'message' => 'Failed to update channel map (HTTP '.$updateResponse->status().')'];
            }

            Log::info('PlexManagementService: Channel map updated', [
                'integration_id' => $this->integration->id,
                'device_key' => $deviceKey,
                'playlist_uuid' => $playlistUuid,
                'previous_count' => count($currentMappedIds),
                'new_count' => count($desiredMappedIds),
            ]);

            return [
                'success' => true,
                'message' => count($desiredMappedIds).' channels synced to Plex (was '.count($currentMappedIds).')',
                'mapped_channels' => count($desiredMappedIds),
                'changed' => true,
            ];
        } catch (Exception $e) {
            Log::error('PlexManagementService: Failed to sync DVR channels for tuner', [
                'integration_id' => $this->integration->id,
                'device_key' => $deviceKey,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Remove a single tuner (device) from the DVR.
     *
     * Removes the device from Plex and from the stored tuners array.
     * If it was the last tuner, removes the entire DVR.
     *
     * @return array{success: bool, message: string}
     */
    public function removeTuner(string $deviceKey): array
    {
        try {
            $dvrId = $this->integration->plex_dvr_id;
            if (! $dvrId) {
                // No DVR registered — just clean up local tuner state
                $tuners = $this->integration->plex_dvr_tuners ?? [];
                $remainingTuners = array_values(array_filter($tuners, fn (array $t): bool => ($t['device_key'] ?? '') !== $deviceKey));
                $this->integration->update([
                    'plex_dvr_tuners' => $remainingTuners ?: null,
                ]);

                return ['success' => true, 'message' => 'Tuner reference removed (no DVR registered in Plex).'];
            }

            $tuners = $this->integration->plex_dvr_tuners ?? [];
            $remainingTuners = array_values(array_filter($tuners, fn (array $t): bool => ($t['device_key'] ?? '') !== $deviceKey));

            if (empty($remainingTuners)) {
                // Last tuner — remove the entire DVR
                return $this->removeDvr($dvrId);
            }

            // Remove device from DVR (ignore 404 — device may already be gone)
            $response = $this->client()->delete("/livetv/dvrs/{$dvrId}/devices/{$deviceKey}");
            if (! $response->successful() && $response->status() !== 404) {
                Log::warning('PlexManagementService: Device removal returned unexpected status', [
                    'integration_id' => $this->integration->id,
                    'device_key' => $deviceKey,
                    'status' => $response->status(),
                ]);
            }

            $this->integration->update([
                'plex_dvr_tuners' => $remainingTuners,
            ]);

            return [
                'success' => true,
                'message' => 'Tuner removed. '.count($remainingTuners).' tuner(s) remaining.',
            ];
        } catch (Exception $e) {
            Log::error('PlexManagementService: Failed to remove tuner', [
                'integration_id' => $this->integration->id,
                'device_key' => $deviceKey,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Fetch the lineup ID from the Plex DVR data.
     *
     * This is more reliable than rebuilding it from scratch because Plex
     * stores the exact lineup ID used during DVR creation.
     */
    protected function getDvrLineupId(): ?string
    {
        $dvrId = (string) ($this->integration->plex_dvr_id ?? '');
        if (! $dvrId) {
            return null;
        }

        try {
            $response = $this->client()->get('/livetv/dvrs');
            if (! $response->successful()) {
                return null;
            }

            $selectedDvr = null;
            foreach ($response->json('MediaContainer.Dvr', []) as $dvr) {
                if ((string) ($dvr['key'] ?? '') === $dvrId) {
                    $selectedDvr = $dvr;
                    break;
                }
            }

            if (! $selectedDvr) {
                return null;
            }

            // Headendarr-style: check Lineup array entries first (more reliable)
            $lineupEntries = $selectedDvr['Lineup'] ?? [];
            if (! is_array($lineupEntries)) {
                $lineupEntries = [$lineupEntries];
            } elseif (! empty($lineupEntries) && ! array_is_list($lineupEntries)) {
                // Single lineup entry returned as associative array
                $lineupEntries = [$lineupEntries];
            }

            // First pass: match by title
            foreach ($lineupEntries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $title = trim((string) ($entry['title'] ?? ''));
                $id = trim((string) ($entry['id'] ?? ''));
                if ($title && $id && stripos($title, 'xmltv') !== false) {
                    Log::debug('PlexManagementService: Resolved lineup ID from Lineup array (by title)', [
                        'integration_id' => $this->integration->id,
                        'lineup_id' => $id,
                        'title' => $title,
                    ]);

                    return $id;
                }
            }

            // Second pass: match by lineup ID containing our EPG URL pattern
            foreach ($lineupEntries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $id = trim((string) ($entry['id'] ?? ''));
                if ($id && str_contains($id, 'tv.plex.providers.epg.xmltv')) {
                    Log::debug('PlexManagementService: Resolved lineup ID from Lineup array (by provider)', [
                        'integration_id' => $this->integration->id,
                        'lineup_id' => $id,
                    ]);

                    return $id;
                }
            }

            // Third: fall back to top-level lineup field
            $topLevel = trim((string) ($selectedDvr['lineup'] ?? ''));
            if ($topLevel) {
                Log::debug('PlexManagementService: Resolved lineup ID from top-level field', [
                    'integration_id' => $this->integration->id,
                    'lineup_id' => $topLevel,
                ]);

                return $topLevel;
            }
        } catch (Exception $e) {
            Log::warning('PlexManagementService: Failed to fetch DVR lineup ID', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Extract channel entries from Plex's lineup channels response.
     *
     * Plex returns a nested structure: MediaContainer.Lineup[].Channel[]
     * where Lineup is an array of lineup objects (usually 1), each containing
     * a Channel array with the actual channel data.
     *
     * @return list<array>
     */
    protected function extractLineupChannels(array $lineupChannelsPayload): array
    {
        $lineups = $lineupChannelsPayload['Lineup'] ?? [];

        // Not an array at all
        if (! is_array($lineups)) {
            return [];
        }

        // If empty, try flat "Channel" key fallback (older Plex versions)
        if (empty($lineups)) {
            $flat = $lineupChannelsPayload['Channel'] ?? [];

            return is_array($flat) ? (array_is_list($flat) ? $flat : [$flat]) : [];
        }

        // Wrap single associative lineup object in a list
        if (! array_is_list($lineups)) {
            $lineups = [$lineups];
        }

        // Collect all Channel entries from each lineup object
        $allChannels = [];
        foreach ($lineups as $lineup) {
            if (! is_array($lineup)) {
                continue;
            }
            $channels = $lineup['Channel'] ?? [];
            if (! is_array($channels)) {
                continue;
            }
            if (! empty($channels) && ! array_is_list($channels)) {
                $channels = [$channels];
            }
            foreach ($channels as $ch) {
                if (is_array($ch)) {
                    $allChannels[] = $ch;
                }
            }
        }

        return $allChannels;
    }

    /**
     * Build the channel map payload for Plex from HDHR lineup and Plex lineup channels.
     *
     * @return array{payload: array, enabledIds: list<string>, unmatched: list<string>}
     */
    protected function buildChannelMapPayload(array $hdhrLineup, array $lineupChannelsPayload): array
    {
        // Extract channels from Plex's nested lineup response structure
        $channels = $this->extractLineupChannels($lineupChannelsPayload);

        $numberMap = [];
        foreach ($channels as $idx => $channel) {
            if (! is_array($channel)) {
                continue;
            }
            if ($idx === 0) {
                Log::debug('PlexManagementService: First Lineup channel keys', [
                    'keys' => array_keys($channel),
                ]);
            }
            // Plex lineup channels use: identifier (lineup ID), channelVcn (visible channel number)
            $number = trim((string) ($channel['channelVcn'] ?? $channel['Number'] ?? $channel['number'] ?? ''));
            $identifier = trim((string) ($channel['identifier'] ?? $channel['lineupIdentifier'] ?? $channel['Id'] ?? $channel['id'] ?? ''));
            if ($number && $identifier) {
                $numberMap[$number] = $identifier;
            }
        }

        $enabledIds = [];
        $unmatched = [];
        $seen = [];
        $matched = 0;

        foreach ($hdhrLineup as $channel) {
            if (! is_array($channel)) {
                continue;
            }
            $guideNumber = trim((string) ($channel['GuideNumber'] ?? $channel['channel_number'] ?? ''));
            if (! $guideNumber) {
                continue;
            }

            if (isset($numberMap[$guideNumber])) {
                $matchedId = $numberMap[$guideNumber];
                $matched++;
            } else {
                $matchedId = $guideNumber;
                $unmatched[] = $guideNumber;
            }

            if (isset($seen[$matchedId])) {
                continue;
            }
            $seen[$matchedId] = true;
            $enabledIds[] = (string) $matchedId;
        }

        // Warn when HDHR channel numbers don't match Plex's lineup channels.
        // This usually means Plex has stale XMLTV guide data and needs an EPG refresh.
        if (! empty($unmatched) && ! empty($numberMap)) {
            $plexSample = array_slice(array_keys($numberMap), 0, 5);
            $hdhrSample = array_slice($unmatched, 0, 5);
            Log::warning('PlexManagementService: HDHR/lineup channel number mismatch — Plex may have stale EPG guide data', [
                'integration_id' => $this->integration->id,
                'matched' => $matched,
                'unmatched' => count($unmatched),
                'plex_lineup_numbers_sample' => $plexSample,
                'hdhr_unmatched_numbers_sample' => $hdhrSample,
            ]);
        }

        $payload = ['channelsEnabled' => implode(',', $enabledIds)];
        foreach ($enabledIds as $id) {
            $payload["channelMappingByKey[{$id}]"] = $id;
            $payload["channelMapping[{$id}]"] = $id;
        }

        Log::debug('PlexManagementService: Built channel map payload', [
            'lineup_channels_from_plex' => count($numberMap),
            'hdhr_lineup_channels' => count($hdhrLineup),
            'enabled_ids' => count($enabledIds),
            'unmatched' => count($unmatched),
        ]);

        return [
            'payload' => $payload,
            'enabledIds' => $enabledIds,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * Verify the DVR sync status: channel count, EPG mapping, and overall health.
     *
     * Returns a structured report indicating whether all tuners are in sync
     * and EPG data is correctly mapped to channels.
     *
     * @return array{success: bool, data?: array{status: string, tuners: list<array{playlist: string, channels_hdhr: int, channels_plex: int, epg_mapped: int, epg_missing: int, in_sync: bool}>, summary: string}, message?: string}
     */
    public function verifyDvrSync(): array
    {
        $dvrId = $this->integration->plex_dvr_id;
        $tuners = $this->integration->plex_dvr_tuners ?? [];

        if (! $dvrId || empty($tuners)) {
            return ['success' => true, 'data' => [
                'status' => 'not_configured',
                'tuners' => [],
                'summary' => 'No DVR tuner registered yet.',
            ]];
        }

        if (! $this->verifyDvrExists($dvrId)) {
            return ['success' => true, 'data' => [
                'status' => 'error',
                'tuners' => [],
                'summary' => 'DVR no longer exists in Plex. Please re-register the tuner.',
            ]];
        }

        $lineupId = $this->getDvrLineupId();

        // Fetch Plex DVR channels (from Device[].ChannelMapping[])
        $plexChannelsResult = $this->getDvrChannels($dvrId);
        $plexChannels = $plexChannelsResult['success'] ? $plexChannelsResult['data'] : collect();
        $plexChannelNumbers = $plexChannels
            ->filter(fn (array $ch) => $ch['enabled'])
            ->pluck('number')
            ->filter()
            ->map(fn ($n) => (string) $n)
            ->values()
            ->all();

        // Fetch Plex lineup channels (for EPG mapping verification)
        $lineupChannelNumbers = [];
        if ($lineupId) {
            try {
                $lineupResponse = $this->client()->get('/livetv/epg/lineupchannels', ['lineup' => $lineupId]);
                if ($lineupResponse->successful()) {
                    $container = $lineupResponse->json('MediaContainer', []);
                    $channels = $this->extractLineupChannels($container);
                    foreach ($channels as $ch) {
                        if (! is_array($ch)) {
                            continue;
                        }
                        $num = trim((string) ($ch['channelVcn'] ?? $ch['Number'] ?? $ch['number'] ?? ''));
                        if ($num) {
                            $lineupChannelNumbers[] = $num;
                        }
                    }
                }
            } catch (Exception) {
                // Non-critical
            }
        }

        $tunerReports = [];
        $allInSync = true;

        foreach ($tuners as $tuner) {
            $playlistUuid = $tuner['playlist_uuid'] ?? null;
            $deviceKey = $tuner['device_key'] ?? null;
            if (! $playlistUuid) {
                continue;
            }

            $urls = $this->resolvePlaylistUrls($playlistUuid);
            if (! $urls || empty($urls['hdhr'])) {
                $allInSync = false;
                $tunerReports[] = [
                    'playlist' => $playlistUuid,
                    'channels_hdhr' => 0,
                    'channels_plex' => 0,
                    'epg_mapped' => 0,
                    'epg_missing' => 0,
                    'in_sync' => false,
                ];

                continue;
            }

            // Fetch HDHR lineup for this tuner
            $lineupUrl = rtrim($urls['hdhr'], '/').'/lineup.json';
            $hdhrChannelCount = 0;
            $hdhrNumbers = [];
            try {
                $lineupResponse = Http::timeout(10)->get($lineupUrl);
                if ($lineupResponse->successful()) {
                    $lineup = $lineupResponse->json();
                    if (is_array($lineup)) {
                        $hdhrChannelCount = count($lineup);
                        foreach ($lineup as $ch) {
                            $num = trim((string) ($ch['GuideNumber'] ?? $ch['channel_number'] ?? ''));
                            if ($num) {
                                $hdhrNumbers[] = $num;
                            }
                        }
                    }
                }
            } catch (Exception) {
                // Non-critical
            }

            // Check how many HDHR channels appear in Plex DVR
            $channelsInPlex = count(array_intersect($hdhrNumbers, $plexChannelNumbers));

            // Check how many channels have EPG lineup entries
            $epgMapped = count(array_intersect($hdhrNumbers, $lineupChannelNumbers));
            $epgMissing = count($hdhrNumbers) - $epgMapped;

            $inSync = $channelsInPlex === $hdhrChannelCount && $epgMissing === 0 && $hdhrChannelCount > 0;
            if (! $inSync) {
                $allInSync = false;
            }

            $tunerReports[] = [
                'playlist_uuid' => $playlistUuid,
                'device_key' => $deviceKey,
                'channels_hdhr' => $hdhrChannelCount,
                'channels_plex' => $channelsInPlex,
                'epg_mapped' => $epgMapped,
                'epg_missing' => $epgMissing,
                'in_sync' => $inSync,
            ];
        }

        $totalHdhr = array_sum(array_column($tunerReports, 'channels_hdhr'));
        $totalPlex = array_sum(array_column($tunerReports, 'channels_plex'));
        $totalEpgMapped = array_sum(array_column($tunerReports, 'epg_mapped'));
        $totalEpgMissing = array_sum(array_column($tunerReports, 'epg_missing'));

        if ($totalHdhr === 0) {
            $summary = 'No channels found in HDHR lineup. Check your playlist configuration.';
            $status = 'warning';
        } elseif ($allInSync) {
            $summary = "All {$totalHdhr} channels synchronized and EPG mapped correctly.";
            $status = 'ok';
        } else {
            $parts = [];
            if ($totalPlex < $totalHdhr) {
                $parts[] = ($totalHdhr - $totalPlex).' channel(s) not yet in Plex';
            }
            if ($totalEpgMissing > 0) {
                $parts[] = "{$totalEpgMissing} channel(s) missing EPG mapping";
            }
            $summary = implode('; ', $parts).'. Try "Force Sync Channels" to fix.';
            $status = 'warning';
        }

        return ['success' => true, 'data' => [
            'status' => $status,
            'tuners' => $tunerReports,
            'total_channels' => $totalHdhr,
            'total_in_plex' => $totalPlex,
            'total_epg_mapped' => $totalEpgMapped,
            'total_epg_missing' => $totalEpgMissing,
            'summary' => $summary,
        ]];
    }

    /**
     * Extract current channel mapping IDs from a Plex device payload.
     *
     * @return list<string>
     */
    protected function extractChannelMappingIds(array $devicePayload): array
    {
        $mappedIds = [];
        $seen = [];

        $items = $devicePayload['ChannelMapping'] ?? [];
        if (is_array($items) && ! empty($items) && ! array_is_list($items)) {
            $items = [$items];
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $enabled = trim($item['enabled'] ?? '1');
            if (in_array($enabled, ['0', 'false'])) {
                continue;
            }
            $identifier = trim($item['lineupIdentifier'] ?? $item['channelKey'] ?? '');
            if ($identifier && ! isset($seen[$identifier])) {
                $mappedIds[] = $identifier;
                $seen[$identifier] = true;
            }
        }

        return $mappedIds;
    }
}
