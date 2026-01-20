<?php

namespace App\Services;

use App\Http\Controllers\MediaServerProxyController;
use App\Interfaces\MediaServer;
use App\Models\MediaServerIntegration;
use Exception;
use Illuminate\Http\Client\PendingRequest;
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
                $response = $this->client()->get("/library/sections/{$library['key']}/all");

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

                return $libraries->where('type', $type);
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
        return [
            'Id' => $item['ratingKey'],
            'Name' => $item['title'],
            'Type' => ucfirst($item['type']),
            'ProductionYear' => $item['year'] ?? null,
            'Path' => $item['Media'][0]['Part'][0]['file'] ?? null,
            'CommunityRating' => $item['rating'] ?? null,
            'OfficialRating' => $item['contentRating'] ?? null,
            'Overview' => $item['summary'] ?? null,
            'RunTimeTicks' => isset($item['duration']) ? ($item['duration'] * 10000) : null,
            'Genres' => array_map(fn ($g) => $g['tag'], $item['Genre'] ?? []),
            'ImageTags' => [
                'Primary' => $item['thumb'] ?? null,
                'Backdrop' => $item['art'] ?? null,
            ],
            'MediaSources' => array_map(fn ($media) => [
                'Container' => $media['container'] ?? null,
            ], $item['Media'] ?? []),
        ];
    }

    public function fetchSeasons(string $seriesId): Collection
    {
        try {
            $response = $this->client()->get("/library/metadata/{$seriesId}/children");

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

            $response = $this->client()->get($endpoint);

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

    public function getDirectStreamUrl(string $itemId, string $container = 'ts'): string
    {
        try {
            $response = $this->client()->get("/library/metadata/{$itemId}");

            if ($response->successful()) {
                $data = $response->json();
                $metadata = $data['MediaContainer']['Metadata'][0] ?? null;

                if ($metadata && isset($metadata['Media'][0]['Part'][0]['key'])) {
                    $partKey = $metadata['Media'][0]['Part'][0]['key'];

                    // The container argument is ignored as the key contains the direct path to the file
                    return "{$this->baseUrl}{$partKey}?X-Plex-Token={$this->apiKey}";
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
