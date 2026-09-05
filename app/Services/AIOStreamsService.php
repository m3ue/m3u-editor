<?php

namespace App\Services;

use App\Exceptions\MediaServerException;
use App\Interfaces\MediaServer;
use App\Models\MediaServerIntegration;
use App\Settings\GeneralSettings;
use App\Traits\DoesNotSupportLibraryCreation;
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
    use DoesNotSupportLibraryCreation;

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
        $this->integration->aiostreams_meta_id_prefixes = $this->extractMetaIdPrefixes($manifest);

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
     * AIOStreams only exposes the Stremio `meta` resource when the operator has
     * configured a metadata addon (Cinemeta, TMDB, ...) inside their instance;
     * a stream-only setup 404s every meta request with "no addon to handle meta
     * resource". `aiostreams_meta_id_prefixes` (computed from the manifest in
     * testConnection()) tells us in advance whether that's the case, so a
     * known-unsupported id skips straight to the Stremio-addon fallback instead
     * of making a guaranteed-to-fail round trip first.
     *
     * @return array{meta: array<string, mixed>}|null
     */
    public function fetchMeta(string $type, string $id): ?array
    {
        if (! $this->manifestSupportsMetaFor($id)) {
            return $this->fetchMetaFromStremioAddon($type, $id);
        }

        $this->waitForRateLimit();

        // No retry: a 404 ("no addon to handle meta resource") from a stream-only
        // AIOStreams instance is deterministic, so retrying it just adds latency.
        // The Stremio-addon fallback below is what provides resilience here.
        $response = Http::timeout(15)->get("{$this->baseUrl}/meta/{$type}/{$id}.json");

        if ($response->successful()) {
            $data = $response->json();

            // Some AIOStreams versions answer 200 with an error envelope
            // ({"success": false, ...}) rather than a real meta object.
            if (is_array($data) && ! empty($data['meta'])) {
                return $data;
            }
        }

        // The manifest flag says this should have worked (or we've never synced
        // it), so this is a genuine surprise — try the fallback as a safety net
        // rather than trusting a possibly-stale flag, but still log it.
        $fallback = $this->fetchMetaFromStremioAddon($type, $id);

        if ($fallback !== null) {
            return $fallback;
        }

        Log::warning("AIOStreams meta fetch failed for {$type}/{$id}: HTTP {$response->status()}");

        return null;
    }

    /**
     * Whether the manifest (as of the last testConnection()/sync) declares meta
     * support for this id. A `null` flag means the integration predates this
     * check or hasn't synced yet, so it defaults to "try it" rather than
     * assuming no support.
     */
    protected function manifestSupportsMetaFor(string $id): bool
    {
        $prefixes = $this->integration->aiostreams_meta_id_prefixes;

        if ($prefixes === null) {
            return true;
        }

        if (in_array('*', $prefixes, true)) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_starts_with($id, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine which id prefixes (if any) the manifest's `meta` resource
     * supports, from either resource shape Stremio addons use:
     *   - `resources: ["meta", ...]` with top-level `idPrefixes`
     *   - `resources: [{"name": "meta", "idPrefixes": [...]}, ...]`
     *
     * @param  array<string, mixed>  $manifest
     * @return array<int, string> Empty when the manifest declares no `meta`
     *                            resource at all; `["*"]` when it declares one
     *                            without restricting idPrefixes.
     */
    protected function extractMetaIdPrefixes(array $manifest): array
    {
        $resources = $manifest['resources'] ?? [];

        if (! is_array($resources)) {
            return [];
        }

        $declaresMeta = false;
        $prefixes = [];

        foreach ($resources as $resource) {
            if (is_string($resource) && $resource === 'meta') {
                $declaresMeta = true;
                $prefixes = array_merge($prefixes, $this->normalizeIdPrefixes($manifest['idPrefixes'] ?? null));
            } elseif (is_array($resource) && ($resource['name'] ?? null) === 'meta') {
                $declaresMeta = true;
                $prefixes = array_merge($prefixes, $this->normalizeIdPrefixes($resource['idPrefixes'] ?? $manifest['idPrefixes'] ?? null));
            }
        }

        if (! $declaresMeta) {
            return [];
        }

        $prefixes = array_values(array_unique($prefixes));

        return $prefixes ?: ['*'];
    }

    /**
     * @param  mixed  $idPrefixes
     * @return array<int, string>
     */
    protected function normalizeIdPrefixes($idPrefixes): array
    {
        if (! is_array($idPrefixes)) {
            return [];
        }

        return array_values(array_filter($idPrefixes, 'is_string'));
    }

    /**
     * Fetch a Stremio meta object from the canonical public meta addon for the
     * item's id scheme, mirroring Stremio's own default meta resolution:
     * "tt..." -> Cinemeta, "kitsu:..." -> Kitsu addon, "tmdb:..." -> TMDB addon.
     *
     * @return array{meta: array<string, mixed>}|null
     */
    protected function fetchMetaFromStremioAddon(string $type, string $id): ?array
    {
        $addons = config('services.stremio.meta_addons', []);

        $base = match (true) {
            str_starts_with($id, 'tt') => $addons['cinemeta'] ?? null,
            str_starts_with($id, 'kitsu:') => $addons['kitsu'] ?? null,
            str_starts_with($id, 'tmdb:') => $addons['tmdb'] ?? null,
            default => null,
        };

        if (! is_string($base) || $base === '') {
            return null;
        }

        $response = Http::timeout(15)->get(rtrim($base, '/')."/meta/{$type}/{$id}.json");

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) && ! empty($data['meta']) ? $data : null;
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
