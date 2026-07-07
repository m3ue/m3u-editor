<?php

namespace App\Services;

use App\Exceptions\MediaServerException;
use App\Interfaces\MediaServer;
use App\Models\MediaServerIntegration;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AIOStreamsService - Integration for AIOStreams Stremio addon aggregators.
 *
 * AIOStreams is on-demand only (no library sync). It exposes catalogs and
 * per-item stream lists via the Stremio addon protocol, authenticated via
 * tokens embedded in the manifest URL path.
 */
class AIOStreamsService implements MediaServer
{
    protected MediaServerIntegration $integration;

    protected string $baseUrl;

    public function __construct(MediaServerIntegration $integration)
    {
        $this->integration = $integration;
        $this->baseUrl = $integration->manifest_base_url ?? '';
    }

    public static function make(MediaServerIntegration $integration): self
    {
        return new self($integration);
    }

    /**
     * Fetch the manifest and validate the connection.
     * Also refreshes the cached catalog list on the integration.
     *
     * @return array{success: bool, message: string, name?: string, version?: string, catalogs?: int}
     *
     * @throws MediaServerException
     */
    public function testConnection(): array
    {
        if (! $this->integration->manifest_url) {
            throw new MediaServerException('No manifest URL configured.');
        }

        $response = Http::timeout(15)
            ->get($this->integration->manifest_url);

        if (! $response->successful()) {
            throw new MediaServerException("Failed to fetch manifest: HTTP {$response->status()}");
        }

        $manifest = $response->json();

        if (empty($manifest['id']) || empty($manifest['name'])) {
            throw new MediaServerException('Response does not appear to be a valid Stremio addon manifest.');
        }

        $catalogs = collect($manifest['catalogs'] ?? [])
            ->map(fn (array $catalog) => [
                'id' => $catalog['id'],
                'type' => $catalog['type'],
                'name' => $catalog['name'],
                'searchable' => collect($catalog['extra'] ?? [])
                    ->contains(fn ($e) => ($e['name'] ?? '') === 'search'),
            ])
            ->values()
            ->all();

        $this->integration->aiostreams_catalogs = $catalogs;
        $this->integration->save();

        return [
            'success' => true,
            'message' => "Connected to {$manifest['name']} v{$manifest['version']}. Found ".count($catalogs).' catalogs.',
            'name' => $manifest['name'],
            'version' => $manifest['version'],
            'catalogs' => count($catalogs),
        ];
    }

    /**
     * Return available catalogs as a "library" list.
     *
     * @return Collection<int, array{id: string, name: string, type: string, item_count: int}>
     */
    public function fetchLibraries(): Collection
    {
        $catalogs = $this->integration->aiostreams_catalogs ?? [];

        return collect($catalogs)->map(fn (array $cat) => [
            'id' => $cat['id'],
            'name' => $cat['name'],
            'type' => $cat['type'],
            'item_count' => 0,
        ]);
    }

    /**
     * Browse a catalog and return its items (metas array from Stremio protocol).
     *
     * @param  array<string, string>  $extra  Extra params like ['search' => 'batman', 'genre' => 'Action']
     * @return array{metas: array<int, array<string, mixed>>}
     *
     * @throws MediaServerException
     */
    public function fetchCatalog(string $type, string $catalogId, int $skip = 0, array $extra = []): array
    {
        $path = "catalog/{$type}/{$catalogId}";

        $extraParts = [];
        if ($skip > 0) {
            $extraParts[] = "skip={$skip}";
        }
        foreach ($extra as $key => $value) {
            $extraParts[] = "{$key}=".rawurlencode($value);
        }

        if (! empty($extraParts)) {
            $path .= '/'.implode('&', $extraParts);
        }

        $response = Http::timeout(20)->get("{$this->baseUrl}/{$path}.json");

        if (! $response->successful()) {
            throw new MediaServerException("Catalog fetch failed: HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }

    /**
     * Fetch available streams for a piece of content identified by IMDb/TMDB ID.
     *
     * @return array{streams: array<int, array<string, mixed>>}
     *
     * @throws MediaServerException
     */
    public function fetchStreams(string $type, string $id): array
    {
        $response = Http::timeout(30)->get("{$this->baseUrl}/stream/{$type}/{$id}.json");

        if (! $response->successful()) {
            throw new MediaServerException("Stream fetch failed: HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }

    /**
     * Fetch metadata for a piece of content.
     *
     * @return array{meta: array<string, mixed>}|null
     */
    public function fetchMeta(string $type, string $id): ?array
    {
        $response = Http::timeout(15)->get("{$this->baseUrl}/meta/{$type}/{$id}.json");

        if (! $response->successful()) {
            Log::warning("AIOStreams meta fetch failed for {$type}/{$id}: HTTP {$response->status()}");

            return null;
        }

        return $response->json();
    }

    // -------------------------------------------------------------------------
    // MediaServer interface methods — not applicable to AIOStreams (on-demand only)
    // -------------------------------------------------------------------------

    public function fetchMovies(): Collection
    {
        return collect();
    }

    public function fetchSeries(): Collection
    {
        return collect();
    }

    public function fetchSeriesDetails(string $seriesId): ?array
    {
        return null;
    }

    public function fetchSeasons(string $seriesId): Collection
    {
        return collect();
    }

    public function fetchEpisodes(string $seriesId, ?string $seasonId = null): Collection
    {
        return collect();
    }

    public function getStreamUrl(string $itemId, string $container = 'ts'): string
    {
        throw new MediaServerException('Direct stream URLs are not supported for AIOStreams. Use fetchStreams() instead.');
    }

    public function getDirectStreamUrl(Request $request, string $itemId, string $container = 'ts', array $transcodeOptions = []): string
    {
        throw new MediaServerException('Direct stream URLs are not supported for AIOStreams. Use fetchStreams() instead.');
    }

    public function getImageUrl(string $itemId, string $imageType = 'Primary'): string
    {
        return '';
    }

    public function getDirectImageUrl(string $itemId, string $imageType = 'Primary'): string
    {
        return '';
    }

    public function extractGenres(array $item): array
    {
        return [];
    }

    public function getContainerExtension(array $item): string
    {
        return 'mp4';
    }

    public function ticksToSeconds(?int $ticks): ?int
    {
        return null;
    }

    public function refreshLibrary(): array
    {
        return $this->testConnection();
    }
}
