<?php

namespace App\Services;

use App\Http\Controllers\MediaServerProxyController;
use App\Interfaces\MediaServer;
use App\Models\MediaServerIntegration;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlexService implements MediaServer
{
    protected MediaServerIntegration $integration;

    protected string $baseUrl;

    protected string $apiKey;

    public function __construct(MediaServerIntegration $integration)
    {
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
            ->retry(2, 1000)
            ->withHeaders([
                'X-Plex-Token' => $this->apiKey,
                'Accept' => 'application/json',
            ]);
    }

    public function testConnection(): array
    {
        try {
            $response = $this->client()->get('/');

            if ($response->successful()) {
                $data = $response->json();
                $serverName = $data['MediaContainer']['friendlyName'] ?? 'Unknown';
                $version = $data['MediaContainer']['version'] ?? 'Unknown';

                return [
                    'success' => true,
                    'message' => 'Connection successful',
                    'server_name' => $serverName,
                    'version' => $version,
                ];
            }

            return [
                'success' => false,
                'message' => 'Server returned status: '.$response->status(),
            ];
        } catch (Exception $e) {
            Log::warning('PlexService: Connection test failed', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Fetch available libraries from the media server.
     * Returns only movies and TV shows libraries.
     *
     * @return Collection<int, array{id: string, name: string, type: string, item_count: int}>
     */
    public function fetchLibraries(): Collection
    {
        try {
            $response = $this->client()->get('/library/sections');

            if ($response->successful()) {
                $data = $response->json();

                return collect($data['MediaContainer']['Directory'] ?? [])
                    ->filter(function ($library) {
                        // Only include movies and shows libraries
                        $type = $library['type'] ?? '';

                        return in_array($type, ['movie', 'show']);
                    })
                    ->map(function ($library) {
                        $plexType = $library['type'] ?? 'unknown';
                        // Normalize type to match Emby/Jellyfin convention
                        $normalizedType = $plexType === 'movie' ? 'movies' : 'tvshows';

                        return [
                            'id' => $library['key'] ?? '',
                            'name' => $library['title'] ?? 'Unknown Library',
                            'type' => $normalizedType,
                            'item_count' => 0, // Plex doesn't include count in sections endpoint
                            'path' => isset($library['Location'][0]['path'])
                                ? $library['Location'][0]['path']
                                : '',
                        ];
                    })
                    ->values();
            }

            Log::warning('PlexService: Failed to fetch libraries', [
                'integration_id' => $this->integration->id,
                'status' => $response->status(),
            ]);

            return collect();
        } catch (Exception $e) {
            Log::error('PlexService: Error fetching libraries', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    public function fetchMovies(): Collection
    {
        $libraries = $this->fetchPlexLibraries('movie');
        $movies = collect();

        foreach ($libraries as $library) {
            try {
                $response = $this->client()->get("/library/sections/{$library['key']}/all");

                if ($response->successful()) {
                    $data = $response->json();
                    $items = collect($data['MediaContainer']['Metadata'] ?? [])
                        ->map(fn ($item) => $this->normalizeItem($item));
                    $movies = $movies->concat($items);
                }
            } catch (Exception $e) {
                Log::error('PlexService: Error fetching movies from library', [
                    'integration_id' => $this->integration->id,
                    'library_id' => $library['key'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $movies;
    }

    public function fetchSeries(): Collection
    {
        $libraries = $this->fetchPlexLibraries('show');
        $series = collect();

        foreach ($libraries as $library) {
            try {
                $response = $this->client()->get("/library/sections/{$library['key']}/all", [
                    'includeGuids' => 1,
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $items = collect($data['MediaContainer']['Metadata'] ?? [])
                        ->map(fn ($item) => $this->normalizeItem($item));
                    $series = $series->concat($items);
                }
            } catch (Exception $e) {
                Log::error('PlexService: Error fetching series from library', [
                    'integration_id' => $this->integration->id,
                    'library_id' => $library['key'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $series;
    }

    /**
     * Fetch all Plex libraries of a specific type.
     * If specific libraries are selected, only returns those libraries.
     *
     * @param  string  $type  'movie' or 'show'
     * @return Collection<int, array>
     */
    protected function fetchPlexLibraries(string $type): Collection
    {
        try {
            $response = $this->client()->get('/library/sections');

            if ($response->successful()) {
                $data = $response->json();
                $libraries = collect($data['MediaContainer']['Directory'] ?? []);

                // Filter by type
                $libraries = $libraries->where('type', $type);

                // Check if specific libraries are selected
                // Map Plex type to normalized type for lookup
                $normalizedType = $type === 'movie' ? 'movies' : 'tvshows';
                $selectedLibraryIds = $this->integration->getSelectedLibraryIdsForType($normalizedType);

                if (! empty($selectedLibraryIds)) {
                    // Filter to only include selected libraries
                    $libraries = $libraries->filter(function ($library) use ($selectedLibraryIds) {
                        return in_array($library['key'], $selectedLibraryIds);
                    });
                }

                return $libraries;
            }

            return collect();
        } catch (Exception $e) {
            Log::error('PlexService: Error fetching libraries', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Normalize a Plex API item to a common format.
     */
    protected function normalizeItem(array $item): array
    {
        // Extract people by type (actors, directors)
        $people = $item['Role'] ?? [];
        $directors = array_filter($item['Director'] ?? [], fn ($d) => isset($d['tag']));

        // Extract external IDs from Guid array (e.g., "imdb://tt1234567", "tmdb://12345", "tvdb://12345")
        $externalIds = $this->extractExternalIds($item['Guid'] ?? []);

        // Extract bitrate from first media source
        $bitrate = null;
        if (! empty($item['Media'][0]['bitrate'])) {
            $bitrate = (int) $item['Media'][0]['bitrate'];
        }

        // Plex uses different rating fields:
        // - 'rating' is typically the critic score (often empty for TV shows)
        // - 'audienceRating' is the audience/user score (more commonly available)
        // Prefer audienceRating, fall back to rating
        $communityRating = $item['audienceRating'] ?? $item['rating'] ?? null;

        return [
            'Id' => $item['ratingKey'],
            'Name' => $item['title'],
            'OriginalTitle' => $item['originalTitle'] ?? null,
            'Type' => ucfirst($item['type']),
            'ProductionYear' => $item['year'] ?? null,
            'PremiereDate' => $item['originallyAvailableAt'] ?? null,
            'Path' => $item['Media'][0]['Part'][0]['file'] ?? null,
            'CommunityRating' => $communityRating,
            'OfficialRating' => $item['contentRating'] ?? null,
            'Overview' => $item['summary'] ?? null,
            'RunTimeTicks' => isset($item['duration']) ? ($item['duration'] * 10000) : null,
            'IndexNumber' => $item['index'] ?? null,
            'ParentIndexNumber' => $item['parentIndex'] ?? null,
            'Genres' => array_map(fn ($g) => $g['tag'], $item['Genre'] ?? []),
            'People' => array_map(fn ($p) => [
                'Name' => $p['tag'] ?? $p['name'] ?? null,
                'Type' => 'Actor',
                'Role' => $p['role'] ?? null,
            ], $people),
            'Directors' => array_map(fn ($d) => $d['tag'], $directors),
            'ImageTags' => [
                'Primary' => $item['thumb'] ?? null,
                'Backdrop' => $item['art'] ?? null,
            ],
            'BackdropImageTags' => ! empty($item['art']) ? [$item['art']] : [],
            'MediaSources' => array_map(fn ($media) => [
                'Container' => $media['container'] ?? null,
                'Bitrate' => $media['bitrate'] ?? null,
            ], $item['Media'] ?? []),
            'Bitrate' => $bitrate,
            'ProviderIds' => $externalIds,
        ];
    }

    /**
     * Extract external IDs (TMDB, TVDB, IMDB) from Plex Guid array.
     */
    protected function extractExternalIds(array $guids): array
    {
        $ids = [
            'Tmdb' => null,
            'Tvdb' => null,
            'Imdb' => null,
        ];

        foreach ($guids as $guid) {
            $id = $guid['id'] ?? null;
            if (! $id) {
                continue;
            }

            if (str_starts_with($id, 'tmdb://')) {
                $ids['Tmdb'] = str_replace('tmdb://', '', $id);
            } elseif (str_starts_with($id, 'tvdb://')) {
                $ids['Tvdb'] = str_replace('tvdb://', '', $id);
            } elseif (str_starts_with($id, 'imdb://')) {
                $ids['Imdb'] = str_replace('imdb://', '', $id);
            }
        }

        return $ids;
    }

    /**
     * Fetch detailed metadata for a single series (includes cast, directors, etc.).
     */
    public function fetchSeriesDetails(string $seriesId): ?array
    {
        try {
            $response = $this->client()->get("/library/metadata/{$seriesId}", [
                'includeGuids' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $item = $data['MediaContainer']['Metadata'][0] ?? null;

                return $item ? $this->normalizeItem($item) : null;
            }

            return null;
        } catch (Exception $e) {
            Log::error('PlexService: Error fetching series details', [
                'integration_id' => $this->integration->id,
                'series_id' => $seriesId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function fetchSeasons(string $seriesId): Collection
    {
        try {
            $response = $this->client()->get("/library/metadata/{$seriesId}/children", [
                'includeGuids' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return collect($data['MediaContainer']['Metadata'] ?? [])
                    ->map(fn ($item) => $this->normalizeItem($item));
            }

            return collect();
        } catch (Exception $e) {
            Log::error('PlexService: Error fetching seasons', [
                'integration_id' => $this->integration->id,
                'series_id' => $seriesId,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    public function fetchEpisodes(string $seriesId, ?string $seasonId = null): Collection
    {
        try {
            $endpoint = $seasonId
                ? "/library/metadata/{$seasonId}/children"
                : "/library/metadata/{$seriesId}/allLeaves";

            $response = $this->client()->get($endpoint, [
                'includeGuids' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return collect($data['MediaContainer']['Metadata'] ?? [])
                    ->map(fn ($item) => $this->normalizeItem($item));
            }

            return collect();
        } catch (Exception $e) {
            Log::error('PlexService: Error fetching episodes', [
                'integration_id' => $this->integration->id,
                'series_id' => $seriesId,
                'season_id' => $seasonId,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    public function getStreamUrl(string $itemId, string $container = 'ts'): string
    {
        // Use proxy URL to hide API key from external clients
        return MediaServerProxyController::generateStreamProxyUrl(
            $this->integration->id,
            $itemId,
            $container
        );
    }

    public function getDirectStreamUrl(Request $request, string $itemId, string $container = 'ts', array $transcodeOptions = []): string
    {
        try {
            $response = $this->client()->get("/library/metadata/{$itemId}");

            if ($response->successful()) {
                $data = $response->json();
                $metadata = $data['MediaContainer']['Metadata'][0] ?? null;

                if ($metadata && isset($metadata['Media'][0]['Part'][0]['key'])) {
                    $partKey = $metadata['Media'][0]['Part'][0]['key'];

                    // Base URL for the stream
                    $streamUrl = "{$this->baseUrl}{$partKey}";

                    // Start with the API key
                    $params = ['X-Plex-Token' => $this->apiKey];

                    // NOTE: Do NOT add 'offset' parameter to direct file URLs.
                    // Plex's direct file endpoint (/library/parts/...) does not support offset.
                    // Seeking for direct files should be handled by FFmpeg's -ss flag or HTTP range requests.
                    // The 'offset' parameter only works with Plex's transcode endpoints.

                    // Forward audio and subtitle stream indexes if provided
                    if ($request->has('AudioStreamIndex')) {
                        $params['audioStreamID'] = $request->input('AudioStreamIndex');
                    }
                    if ($request->has('SubtitleStreamIndex')) {
                        $params['subtitleStreamID'] = $request->input('SubtitleStreamIndex');
                    }

                    // If transcode options are provided use Plex's transcode endpoint
                    if (! empty($transcodeOptions)) {
                        $videoBitrate = $transcodeOptions['video_bitrate'] ?? null;
                        $audioBitrate = $transcodeOptions['audio_bitrate'] ?? null;
                        $maxWidth = $transcodeOptions['max_width'] ?? null;
                        $maxHeight = $transcodeOptions['max_height'] ?? null;
                        $resolution = $maxWidth && $maxHeight
                            ? "{$maxWidth}x{$maxHeight}"
                            : null;

                        $transcodeParams = array_filter([
                            'url' => $streamUrl,
                            'X-Plex-Token' => $this->apiKey,
                            'videoBitrate' => $videoBitrate,
                            'audioBitrate' => $audioBitrate,
                            'videoResolution' => $resolution,
                        ]);

                        // Preferred flow: ask Plex's universal decision endpoint for the correct
                        // start URL (it will provide session, protocol, and other required params).
                        try {
                            $decisionEndpoint = $this->baseUrl.'/video/:/transcode/universal/decision';
                            $decisionParams = [
                                'path' => "/library/metadata/{$itemId}",
                                'mediaIndex' => 0,
                                'partIndex' => 0,
                                'protocol' => 'hls',
                                'directPlay' => 0,
                                'directStream' => 1,
                                'fastSeek' => 1,
                                'location' => 'lan',
                                'hasMDE' => 1,
                            ];

                            // Include seek position if provided via StartTimeTicks
                            if ($request->has('StartTimeTicks')) {
                                $ticks = (int) $request->input('StartTimeTicks');
                                $offsetSeconds = $this->ticksToSeconds($ticks);
                                if ($offsetSeconds !== null && $offsetSeconds > 0) {
                                    // Plex uses 'offset' in seconds for transcode seeking
                                    $decisionParams['offset'] = $offsetSeconds;
                                }
                            }

                            Log::debug('Calling Plex decision endpoint', [
                                'endpoint' => $decisionEndpoint,
                                'params' => $decisionParams,
                                'headers' => ['X-Plex-Product' => 'm3u-proxy', 'X-Plex-Client-Identifier' => 'm3u-proxy'],
                            ]);

                            $decisionResp = Http::timeout(15)
                                ->withHeaders([
                                    'X-Plex-Token' => $this->apiKey,
                                    'X-Plex-Product' => 'Plex Web',
                                    'X-Plex-Client-Identifier' => 'm3u-proxy',
                                    'X-Plex-Platform' => 'Chrome',
                                    'X-Plex-Device' => 'OSX',
                                    'Accept-Language' => 'en',
                                ])
                                ->withoutRedirecting()
                                ->get($decisionEndpoint, $decisionParams);

                            // Expect a redirect (Location header) pointing to the actual start.* URL
                            if (in_array($decisionResp->status(), [301, 302, 303, 307, 308], true)) {
                                $loc = $decisionResp->header('Location');
                                if ($loc) {
                                    // Make absolute if necessary
                                    if (str_starts_with($loc, '/')) {
                                        $startUrl = rtrim($this->baseUrl, '/').$loc;
                                    } else {
                                        $startUrl = $loc;
                                    }

                                    Log::info('Plex decision returned start URL', [
                                        'item_id' => $itemId,
                                        'start_url' => $startUrl,
                                    ]);

                                    return $startUrl;
                                }
                            }

                            // If decision returned XML/200 but no redirect, construct start URL directly
                            // IMPORTANT: Do NOT make GET requests to start endpoints - that consumes the
                            // Plex session and causes HTTP 400 when FFmpeg tries to access the URL later.
                            if ($decisionResp->successful() && ! empty($decisionResp->body())) {
                                $sessionId = bin2hex(random_bytes(8));

                                // Build start URL directly - prefer HLS (start.m3u8) as it's more compatible with FFmpeg
                                $startEndpoint = $this->baseUrl.'/video/:/transcode/universal/start.m3u8';

                                $endpointParams = array_merge($decisionParams, [
                                    'hasMDE' => 1,
                                    'location' => 'lan',
                                    'fastSeek' => 1,
                                    'protocol' => 'hls',
                                    'session' => $sessionId,
                                    'X-Plex-Token' => $this->apiKey,
                                    'X-Plex-Client-Identifier' => 'm3u-proxy',
                                    'X-Plex-Client-Profile-Extra' => 'append-transcode-target-codec(type=videoProfile&context=streaming&videoCodec=h264&audioCodec=aac&protocol=hls)',
                                ]);

                                $query = http_build_query($endpointParams);
                                $startUrl = $startEndpoint.'?'.$query;

                                Log::info('Plex transcode URL constructed (without pre-fetch)', [
                                    'item_id' => $itemId,
                                    'session_id' => $sessionId,
                                    'start_url' => $startUrl,
                                ]);

                                return $startUrl;
                            }

                            // Decision endpoint failed or returned empty
                            Log::warning('Plex decision endpoint did not return usable response', [
                                'endpoint' => $decisionEndpoint,
                                'status' => $decisionResp->status(),
                                'body_snippet' => substr($decisionResp->body(), 0, 500),
                            ]);

                            return '';
                        } catch (Exception $e) {
                            Log::error('Error calling Plex decision endpoint', [
                                'exception' => $e->getMessage(),
                                'item_id' => $itemId,
                            ]);

                            return '';
                        }
                    }

                    // Return the full URL with query parameters for direct streaming
                    return $streamUrl.'?'.http_build_query($params);
                }
            }

            Log::warning('PlexService: Could not retrieve part key for streaming', [
                'integration_id' => $this->integration->id,
                'item_id' => $itemId,
            ]);

            return '';
        } catch (Exception $e) {
            Log::error('PlexService: Error getting direct stream URL', [
                'integration_id' => $this->integration->id,
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    public function getImageUrl(string $itemId, string $imageType = 'Primary'): string
    {
        // Use proxy URL to hide API key from external clients
        return MediaServerProxyController::generateImageProxyUrl(
            $this->integration->id,
            $itemId,
            $imageType
        );
    }

    public function getDirectImageUrl(string $itemId, string $imageType = 'Primary'): string
    {
        $thumb = $imageType === 'Primary' ? 'thumb' : 'art';

        return "{$this->baseUrl}/library/metadata/{$itemId}/{$thumb}?X-Plex-Token={$this->apiKey}";
    }

    public function extractGenres(array $item): array
    {
        $genres = $item['Genres'] ?? [];

        if (empty($genres)) {
            return ['Uncategorized'];
        }

        if ($this->integration->genre_handling === 'primary') {
            return [reset($genres)];
        }

        return $genres;
    }

    public function getContainerExtension(array $item): string
    {
        $mediaSources = $item['MediaSources'] ?? [];

        if (! empty($mediaSources)) {
            $container = $mediaSources[0]['Container'] ?? null;
            if ($container) {
                return strtolower($container);
            }
        }

        // Default fallback
        return 'ts';
    }

    public function ticksToSeconds(?int $ticks): ?int
    {
        if ($ticks === null) {
            return null;
        }

        // 10,000,000 ticks = 1 second
        return (int) ($ticks / 10000000);
    }
}
