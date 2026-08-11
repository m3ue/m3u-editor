<?php

namespace App\Services;

use App\Exceptions\MediaServerException;
use App\Interfaces\MediaServer;
use App\Models\MediaServerIntegration;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * AIOStreamsService - Integration for AIOStreams Stremio addon aggregators.
 *
 * AIOStreams is on-demand only (no library sync). It exposes catalogs and
 * per-item stream lists via the Stremio addon protocol, authenticated via
 * tokens embedded in the manifest URL path.
 */
class AIOStreamsService implements MediaServer
{
    public function createLibrary(
        string $name,
        string $collectionType,
        array $paths,
        bool $refreshLibrary = true,
        ?string $libraryId = null,
    ): array {
        return [
            'success' => false,
            'created' => false,
            'message' => 'Library creation is not supported by this media server.',
            'library' => null,
            'drift' => false,
        ];
    }

    protected MediaServerIntegration $integration;

    protected string $baseUrl;

    protected int $rateLimit;

    public function __construct(MediaServerIntegration $integration, ?GeneralSettings $settings = null)
    {
        $this->integration = $integration;
        $this->baseUrl = $integration->manifest_base_url ?? '';
        $settings = $settings ?? app(GeneralSettings::class);
        $this->rateLimit = $settings->aiostreams_rate_limit ?? 20;
    }

    public static function make(MediaServerIntegration $integration): self
    {
        return new self($integration);
    }

    /**
     * Throttle outbound calls to this integration's AIOStreams instance.
     *
     * AIOStreams does not document a safe request rate, and upstream debrid
     * services are known to restrict simultaneous connections per IP, so
     * this is deliberately conservative and configurable per integration.
     */
    protected function waitForRateLimit(): void
    {
        $key = 'aiostreams-rate-limit-'.$this->integration->id;

        if (RateLimiter::tooManyAttempts($key, $this->rateLimit)) {
            $secondsUntilAvailable = RateLimiter::availableIn($key);

            if ($secondsUntilAvailable > 0) {
                usleep($secondsUntilAvailable * 1_000_000);
            }
        }

        RateLimiter::hit($key, 60);
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

        $this->waitForRateLimit();

        $response = Http::timeout(15)
            ->retry(2, 1000)
            ->get($this->integration->manifest_url);

        if (! $response->successful()) {
            throw new MediaServerException("Failed to fetch manifest: HTTP {$response->status()}");
        }

        $manifest = $response->json();

        if (empty($manifest['id']) || empty($manifest['name'])) {
            throw new MediaServerException('Response does not appear to be a valid Stremio addon manifest.');
        }

        $catalogs = collect($manifest['catalogs'] ?? [])
            ->filter(fn ($catalog) => is_array($catalog) && ! empty($catalog['id']) && ! empty($catalog['type']) && ! empty($catalog['name']))
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
        $this->integration->aiostreams_logo = $manifest['logo'] ?? null;

        // Prune any selected catalog IDs that no longer exist in the manifest.
        if (! $this->integration->aiostreams_enable_all_catalogs) {
            $validIds = collect($catalogs)->pluck('id')->flip()->all();
            $pruned = collect($this->integration->aiostreams_selected_catalog_ids ?? [])
                ->filter(fn (string $id) => isset($validIds[$id]))
                ->values()
                ->all();
            $this->integration->aiostreams_selected_catalog_ids = $pruned;
        }

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

        $this->waitForRateLimit();

        $response = Http::timeout(20)->retry(2, 1000)->get("{$this->baseUrl}/{$path}.json");

        if (! $response->successful()) {
            throw new MediaServerException("Catalog fetch failed: HTTP {$response->status()}");
        }

        return $response->json() ?? [];
    }

    /**
     * Fetch available streams for a piece of content identified by IMDb/TMDB ID.
     *
     * AIOStreams' structured quality/format fields aren't publicly documented,
     * so AioStreamsQualityParser primarily parses the human-readable name/title/
     * description strings on each returned stream object instead.
     *
     * @return array{streams: array<int, array<string, mixed>>}
     *
     * @throws MediaServerException
     */
    public function fetchStreams(string $type, string $id): array
    {
        $this->waitForRateLimit();

        // Deliberately no Http::retry() here: fetchStreams() failures are
        // already surfaced to user-facing retry actions (e.g. AioStreamsBrowse's
        // "retry" button, ResolveAioStreamsChannel's own delayed re-attempts),
        // so retrying silently inside the service would double up on that and
        // change the existing failure-surfacing behavior other callers rely on.
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
        $this->waitForRateLimit();

        $response = Http::timeout(15)->retry(2, 1000)->get("{$this->baseUrl}/meta/{$type}/{$id}.json");

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

    public function getSubtitleUrl(string $itemId, int $seekSeconds = 0, ?string $preferredLanguage = null): ?array
    {
        return null;
    }

    public function getAvailableTracks(string $itemId): array
    {
        return ['audio' => [], 'subtitle' => []];
    }

    public function getStreamByteSize(string $itemId): ?array
    {
        return null;
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
