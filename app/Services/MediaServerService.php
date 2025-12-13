<?php

namespace App\Services;

use App\Models\MediaServerIntegration;
use Exception;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MediaServerService - The "Brain" for Emby/Jellyfin integration
 *
 * Handles all communication with Emby/Jellyfin media servers.
 * Both platforms share the same API structure and authentication.
 */
class MediaServerService
{
    protected MediaServerIntegration $integration;
    protected string $baseUrl;
    protected string $apiKey;

    /**
     * Create a new MediaServerService instance.
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
                'message' => 'Server returned status: ' . $response->status(),
            ];
        } catch (Exception $e) {
            Log::warning('MediaServerService: Connection test failed', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch all movies from the media server.
     *
     * @return Collection<int, array>
     */
    public function fetchMovies(): Collection
    {
        try {
            $response = $this->client()->get('/Items', [
                'IncludeItemTypes' => 'Movie',
                'Recursive' => 'true',
                'Fields' => 'Genres,Path,MediaSources,Overview,CommunityRating,OfficialRating,ProductionYear,RunTimeTicks,People,OriginalTitle,PremiereDate,ProductionLocations',
                'EnableImages' => 'true',
                'ImageTypeLimit' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return collect($data['Items'] ?? []);
            }

            Log::warning('MediaServerService: Failed to fetch movies', [
                'integration_id' => $this->integration->id,
                'status' => $response->status(),
            ]);

            return collect();
        } catch (Exception $e) {
            Log::error('MediaServerService: Error fetching movies', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch all series from the media server.
     *
     * @return Collection<int, array>
     */
    public function fetchSeries(): Collection
    {
        try {
            $response = $this->client()->get('/Items', [
                'IncludeItemTypes' => 'Series',
                'Recursive' => 'true',
                'Fields' => 'Genres,Overview,CommunityRating,OfficialRating,ProductionYear',
                'EnableImages' => 'true',
                'ImageTypeLimit' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return collect($data['Items'] ?? []);
            }

            Log::warning('MediaServerService: Failed to fetch series', [
                'integration_id' => $this->integration->id,
                'status' => $response->status(),
            ]);

            return collect();
        } catch (Exception $e) {
            Log::error('MediaServerService: Error fetching series', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch all seasons for a series.
     *
     * @param string $seriesId The media server's series ID
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
     * @param string $seriesId The media server's series ID
     * @param string|null $seasonId Optional season ID to filter by
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
     * Get the direct play/stream URL for an item.
     *
     * @param string $itemId The media server's item ID
     * @param string $container The container format (e.g., 'mp4', 'mkv', 'ts')
     * @return string
     */
    public function getStreamUrl(string $itemId, string $container = 'ts'): string
    {
        // Direct stream URL - no transcoding, pass-through
        return "{$this->baseUrl}/Videos/{$itemId}/stream.{$container}?static=true&api_key={$this->apiKey}";
    }

    /**
     * Get the primary image URL for an item.
     *
     * @param string $itemId The media server's item ID
     * @param string $imageType Image type: 'Primary', 'Backdrop', 'Logo', etc.
     * @return string
     */
    public function getImageUrl(string $itemId, string $imageType = 'Primary'): string
    {
        return "{$this->baseUrl}/Items/{$itemId}/Images/{$imageType}?api_key={$this->apiKey}";
    }

    /**
     * Extract genres from an item, respecting the genre_handling setting.
     *
     * @param array $item The item data from the API
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
     * @param array $item The item data from the API
     * @return string
     */
    public function getContainerExtension(array $item): string
    {
        $mediaSources = $item['MediaSources'] ?? [];

        if (!empty($mediaSources)) {
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
     * @param int|null $ticks Runtime in ticks (100-nanosecond intervals)
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
