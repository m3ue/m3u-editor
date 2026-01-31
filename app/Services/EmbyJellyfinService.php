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

/**
 * EmbyJellyfinService - The "Brain" for Emby/Jellyfin integration
 *
 * Handles all communication with Emby/Jellyfin media servers.
 * Both platforms share the same API structure and authentication.
 */
class EmbyJellyfinService implements MediaServer
{
    protected MediaServerIntegration $integration;

    protected string $baseUrl;

    protected string $apiKey;

    /**
     * Create a new EmbyJellyfinService instance.
     */
    public function __construct(MediaServerIntegration $integration)
    {
        $this->integration = $integration;
        $this->baseUrl = $integration->base_url;
        $this->apiKey = $integration->api_key;
    }

    /**
     * Static factory method for convenience.
     */
    public static function make(MediaServerIntegration $integration): self
    {
        return new self($integration);
    }

    /**
     * Get a configured HTTP client for the media server.
     */
    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout(30)
            ->retry(2, 1000)
            ->withHeaders([
                'X-Emby-Token' => $this->apiKey,
                'Accept' => 'application/json',
            ]);
    }

    /**
     * Test connection to the media server.
     *
     * @return array{success: bool, message: string, server_name?: string, version?: string}
     */
    public function testConnection(): array
    {
        try {
            $response = $this->client()->get('/System/Info/Public');

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'message' => 'Connection successful',
                    'server_name' => $data['ServerName'] ?? 'Unknown',
                    'version' => $data['Version'] ?? 'Unknown',
                ];
            }

            return [
                'success' => false,
                'message' => 'Server returned status: '.$response->status(),
            ];
        } catch (Exception $e) {
            Log::warning('MediaServerService: Connection test failed', [
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
            $response = $this->client()->get('/Library/VirtualFolders');

            if ($response->successful()) {
                $data = $response->json();

                return collect($data ?? [])
                    ->filter(function ($library) {
                        // Only include movies and tvshows libraries
                        $collectionType = $library['CollectionType'] ?? '';

                        return in_array($collectionType, ['movies', 'tvshows']);
                    })
                    ->map(function ($library) {
                        $collectionType = $library['CollectionType'] ?? 'unknown';

                        return [
                            'id' => $library['ItemId'] ?? $library['Id'] ?? '',
                            'name' => $library['Name'] ?? 'Unknown Library',
                            'type' => $collectionType,
                            'item_count' => $library['ChildCount'] ?? 0,
                            'path' => is_array($library['Locations'] ?? null)
                                ? implode(', ', $library['Locations'])
                                : ($library['Path'] ?? ''),
                        ];
                    })
                    ->values();
            }

            Log::warning('EmbyJellyfinService: Failed to fetch libraries', [
                'integration_id' => $this->integration->id,
                'status' => $response->status(),
            ]);

            return collect();
        } catch (Exception $e) {
            Log::error('EmbyJellyfinService: Error fetching libraries', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch all movies from the media server.
     * If specific libraries are selected, only fetches from those libraries.
     *
     * @return Collection<int, array>
     */
    public function fetchMovies(): Collection
    {
        try {
            $params = [
                'IncludeItemTypes' => 'Movie',
                'Recursive' => 'true',
                'Fields' => 'Genres,Path,MediaSources,Overview,CommunityRating,OfficialRating,ProductionYear,RunTimeTicks,People,OriginalTitle,PremiereDate,ProductionLocations',
                'EnableImages' => 'true',
                'ImageTypeLimit' => 1,
            ];

            // Filter by selected libraries if specified
            $selectedLibraryIds = $this->integration->getSelectedLibraryIdsForType('movies');
            if (! empty($selectedLibraryIds)) {
                // For multiple libraries, we need to fetch from each and merge
                $allMovies = collect();
                foreach ($selectedLibraryIds as $libraryId) {
                    $params['ParentId'] = $libraryId;
                    $response = $this->client()->get('/Items', $params);

                    if ($response->successful()) {
                        $data = $response->json();
                        $allMovies = $allMovies->concat(collect($data['Items'] ?? []));
                    }
                }

                return $allMovies;
            }

            // No library filter - fetch all movies
            $response = $this->client()->get('/Items', $params);

            if ($response->successful()) {
                $data = $response->json();

                return collect($data['Items'] ?? []);
            }

            Log::warning('EmbyJellyfinService: Failed to fetch movies', [
                'integration_id' => $this->integration->id,
                'status' => $response->status(),
            ]);

            return collect();
        } catch (Exception $e) {
            Log::error('EmbyJellyfinService: Error fetching movies', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch all series from the media server.
     * If specific libraries are selected, only fetches from those libraries.
     *
     * @return Collection<int, array>
     */
    public function fetchSeries(): Collection
    {
        try {
            $params = [
                'IncludeItemTypes' => 'Series',
                'Recursive' => 'true',
                'Fields' => 'Genres,Overview,CommunityRating,OfficialRating,ProductionYear',
                'EnableImages' => 'true',
                'ImageTypeLimit' => 1,
            ];

            // Filter by selected libraries if specified
            $selectedLibraryIds = $this->integration->getSelectedLibraryIdsForType('tvshows');
            if (! empty($selectedLibraryIds)) {
                // For multiple libraries, we need to fetch from each and merge
                $allSeries = collect();
                foreach ($selectedLibraryIds as $libraryId) {
                    $params['ParentId'] = $libraryId;
                    $response = $this->client()->get('/Items', $params);

                    if ($response->successful()) {
                        $data = $response->json();
                        $allSeries = $allSeries->concat(collect($data['Items'] ?? []));
                    }
                }

                return $allSeries;
            }

            // No library filter - fetch all series
            $response = $this->client()->get('/Items', $params);

            if ($response->successful()) {
                $data = $response->json();

                return collect($data['Items'] ?? []);
            }

            Log::warning('EmbyJellyfinService: Failed to fetch series', [
                'integration_id' => $this->integration->id,
                'status' => $response->status(),
            ]);

            return collect();
        } catch (Exception $e) {
            Log::error('EmbyJellyfinService: Error fetching series', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch detailed metadata for a single series (includes cast, directors, etc.).
     *
     * @param  string  $seriesId  The media server's series ID
     */
    public function fetchSeriesDetails(string $seriesId): ?array
    {
        try {
            $response = $this->client()->get("/Users/{$this->getUserId()}/Items/{$seriesId}", [
                'Fields' => 'Genres,Overview,CommunityRating,OfficialRating,ProductionYear,People,ProviderIds,ExternalUrls',
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (Exception $e) {
            Log::error('MediaServerService: Error fetching series details', [
                'integration_id' => $this->integration->id,
                'series_id' => $seriesId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the user ID for API calls that require it.
     */
    protected function getUserId(): string
    {
        // Try to get from integration config, or use a default admin user lookup
        if (! empty($this->integration->user_id_emby)) {
            return $this->integration->user_id_emby;
        }

        // Fallback: fetch users and use the first admin
        try {
            $response = $this->client()->get('/Users');
            if ($response->successful()) {
                $users = $response->json();
                foreach ($users as $user) {
                    if ($user['Policy']['IsAdministrator'] ?? false) {
                        return $user['Id'];
                    }
                }

                // Return first user if no admin found
                return $users[0]['Id'] ?? '';
            }
        } catch (Exception $e) {
            Log::warning('MediaServerService: Could not fetch users', ['error' => $e->getMessage()]);
        }

        return '';
    }

    /**
     * Fetch all seasons for a series.
     *
     * @param  string  $seriesId  The media server's series ID
     * @return Collection<int, array>
     */
    public function fetchSeasons(string $seriesId): Collection
    {
        try {
            $response = $this->client()->get("/Shows/{$seriesId}/Seasons", [
                'Fields' => 'Overview',
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return collect($data['Items'] ?? []);
            }

            return collect();
        } catch (Exception $e) {
            Log::error('MediaServerService: Error fetching seasons', [
                'integration_id' => $this->integration->id,
                'series_id' => $seriesId,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch all episodes for a series (or optionally a specific season).
     *
     * @param  string  $seriesId  The media server's series ID
     * @param  string|null  $seasonId  Optional season ID to filter by
     * @return Collection<int, array>
     */
    public function fetchEpisodes(string $seriesId, ?string $seasonId = null): Collection
    {
        try {
            $params = [
                'Fields' => 'Path,MediaSources,Overview,RunTimeTicks',
            ];

            if ($seasonId) {
                $params['SeasonId'] = $seasonId;
            }

            $response = $this->client()->get("/Shows/{$seriesId}/Episodes", $params);

            if ($response->successful()) {
                $data = $response->json();

                return collect($data['Items'] ?? []);
            }

            return collect();
        } catch (Exception $e) {
            Log::error('MediaServerService: Error fetching episodes', [
                'integration_id' => $this->integration->id,
                'series_id' => $seriesId,
                'season_id' => $seasonId,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Get the proxy stream URL for an item (hides API key from clients).
     *
     * @param  string  $itemId  The media server's item ID
     * @param  string  $container  The container format (e.g., 'mp4', 'mkv', 'ts')
     */
    public function getStreamUrl(string $itemId, string $container = 'ts'): string
    {
        // Use proxy URL to hide API key from external clients
        return MediaServerProxyController::generateStreamProxyUrl(
            $this->integration->id,
            $itemId,
            $container
        );
    }

    /**
     * Get the direct stream URL for an item (internal use only - contains API key).
     *
     * @param  string  $itemId  The media server's item ID
     * @param  string  $container  The container format (e.g., 'mp4', 'mkv', 'ts')
     */
    public function getDirectStreamUrl(Request $request, string $itemId, string $container = 'ts', array $transcodeOptions = []): string
    {
        $streamUrl = "{$this->baseUrl}/Videos/{$itemId}/stream.{$container}";

        // Base parameters
        $params = [
            'static' => 'true',
            'api_key' => $this->apiKey,
        ];

        // Forward relevant parameters from the incoming request
        $forwardParams = ['StartTimeTicks', 'AudioStreamIndex', 'SubtitleStreamIndex'];
        foreach ($forwardParams as $param) {
            if ($request->has($param)) {
                $params[$param] = $request->input($param);
            }
        }

        // Include transcode options (VideoBitrate, AudioBitrate, MaxWidth, MaxHeight) if requested
        if (! empty($transcodeOptions)) {
            if (isset($transcodeOptions['video_bitrate'])) {
                $params['VideoBitrate'] = (string) $transcodeOptions['video_bitrate'];
            }
            if (isset($transcodeOptions['audio_bitrate'])) {
                $params['AudioBitrate'] = (string) $transcodeOptions['audio_bitrate'];
            }
            if (isset($transcodeOptions['max_width'])) {
                $params['MaxWidth'] = (int) $transcodeOptions['max_width'];
            }
            if (isset($transcodeOptions['max_height'])) {
                $params['MaxHeight'] = (int) $transcodeOptions['max_height'];
            }

            // Optional codec/preset hints
            if (! empty($transcodeOptions['video_codec'])) {
                $params['VideoCodec'] = $transcodeOptions['video_codec'];
            }
            if (! empty($transcodeOptions['audio_codec'])) {
                $params['AudioCodec'] = $transcodeOptions['audio_codec'];
            }
            if (! empty($transcodeOptions['preset'])) {
                $params['EncoderPreset'] = $transcodeOptions['preset'];
            }
        }

        // Return the full URL with query parameters
        return $streamUrl.'?'.http_build_query($params);
    }

    /**
     * Get the proxy image URL for an item (hides API key from clients).
     *
     * @param  string  $itemId  The media server's item ID
     * @param  string  $imageType  Image type: 'Primary', 'Backdrop', 'Logo', etc.
     */
    public function getImageUrl(string $itemId, string $imageType = 'Primary'): string
    {
        // Use proxy URL to hide API key from external clients
        return MediaServerProxyController::generateImageProxyUrl(
            $this->integration->id,
            $itemId,
            $imageType
        );
    }

    /**
     * Get the direct image URL for an item (internal use only - contains API key).
     *
     * @param  string  $itemId  The media server's item ID
     * @param  string  $imageType  Image type: 'Primary', 'Backdrop', 'Logo', etc.
     */
    public function getDirectImageUrl(string $itemId, string $imageType = 'Primary'): string
    {
        return "{$this->baseUrl}/Items/{$itemId}/Images/{$imageType}?api_key={$this->apiKey}";
    }

    /**
     * Extract genres from an item, respecting the genre_handling setting.
     *
     * @param  array  $item  The item data from the API
     * @return array List of genre names
     */
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

    /**
     * Get the container extension from media sources.
     *
     * @param  array  $item  The item data from the API
     */
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

    /**
     * Convert runtime ticks to seconds.
     *
     * @param  int|null  $ticks  Runtime in ticks (100-nanosecond intervals)
     * @return int|null Runtime in seconds
     */
    public function ticksToSeconds(?int $ticks): ?int
    {
        if ($ticks === null) {
            return null;
        }

        // 10,000,000 ticks = 1 second
        return (int) ($ticks / 10000000);
    }
}
