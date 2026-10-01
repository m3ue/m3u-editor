<?php

namespace App\Http\Controllers;

use App\Enums\ImageProfile;
use App\Facades\PlaylistFacade;
use App\Models\CustomPlaylist;
use App\Models\MediaServerIntegration;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Services\AIOStreamsAuthorizationService;
use App\Services\AIOStreamsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Proxies AIOStreams Stremio addon requests on behalf of authenticated playlist users.
 * Auth tokens are stored server-side in the integration; clients only need playlist credentials.
 */
class AIOStreamsProxyController extends Controller
{
    /**
     * Proxy a catalog browse request.
     * Route: GET /{username}/{password}/aiostreams/{integration}/catalog/{type}/{catalogId}.json
     */
    public function catalog(Request $request, string $username, string $password, int $integrationId, string $type, string $catalogId): JsonResponse
    {
        $integration = $this->resolveIntegration($username, $password, $integrationId);

        if (! $integration) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $extraParts = [];
        if ($request->has('skip') && (int) $request->skip > 0) {
            $extraParts[] = 'skip='.(int) $request->skip;
        }
        if ($request->has('search') && filled($request->search)) {
            $extraParts[] = 'search='.rawurlencode($request->search);
        }
        if ($request->has('genre') && filled($request->genre)) {
            $extraParts[] = 'genre='.rawurlencode($request->genre);
        }

        $path = "catalog/{$type}/{$catalogId}";
        if (! empty($extraParts)) {
            $path .= '/'.implode('&', $extraParts);
        }

        $cacheKey = "aiostreams.catalog.{$integrationId}.{$type}.{$catalogId}.".md5(implode(',', $extraParts));

        $data = Cache::remember($cacheKey, 60, function () use ($integration, $path) {
            $response = Http::timeout(20)->get("{$integration->manifest_base_url}/{$path}.json");

            return $response->successful() ? $response->json() : null;
        });

        if ($data === null) {
            return response()->json(['error' => 'Failed to fetch catalog from AIOStreams'], 502);
        }

        return response()->json($data);
    }

    /**
     * Proxy a stream list request.
     * Route: GET /{username}/{password}/aiostreams/{integration}/stream/{type}/{id}.json
     */
    public function stream(Request $request, string $username, string $password, int $integrationId, string $type, string $id): JsonResponse
    {
        $integration = $this->resolveIntegration($username, $password, $integrationId);

        if (! $integration) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Debrid addons only resolve IMDb IDs to streams, not TMDB IDs.
        // When the catalog returns a TMDB ID (e.g. "tmdb:603"), resolve it to
        // an IMDb ID via the meta lookup before requesting streams.
        $resolvedId = $id;
        if (str_starts_with($id, 'tmdb:')) {
            // Stremio series episode ids carry ":season:episode" after the tmdb
            // id (e.g. "tmdb:1399:1:1"); the meta lookup needs the bare
            // "tmdb:1399", and the coordinates must be re-attached to the
            // resolved IMDb id so the stream request targets the right episode.
            [$tmdbId, $episodeSuffix] = $this->splitStremioId($id);

            // A tmdb -> imdb mapping never changes, so cache it to keep this
            // extra upstream call off the hot path (the stream route runs under
            // nginx's default 60s fastcgi_read_timeout). Reuses the same meta
            // resolution (with public Stremio-addon fallback) as meta().
            $imdbId = Cache::remember(
                "aiostreams.imdb.{$integrationId}.{$type}.{$tmdbId}",
                now()->addWeek(),
                fn () => AIOStreamsService::make($integration)->fetchMeta($type, $tmdbId)['meta']['imdb_id'] ?? null
            );

            if (! empty($imdbId)) {
                $resolvedId = $imdbId.$episodeSuffix;
            }
        }

        // Streams are not cached — always fetch fresh to get current availability
        $response = Http::timeout(30)->get("{$integration->manifest_base_url}/stream/{$type}/{$resolvedId}.json");

        if (! $response->successful()) {
            return response()->json(['error' => 'Failed to fetch streams from AIOStreams'], 502);
        }

        $data = $response->json();

        // Never hand a raw resolved URL (often carrying the debrid account's own
        // auth token) back to the caller — proxy every candidate the same way the
        // browse UI and synced Channels/Episodes do. There's no durable row behind
        // this call, so it goes through the short-lived cache-token "live" proxy.
        // A stream list can hold dozens of candidates, so this is batched into one
        // cache write (see generateAioStreamsLiveProxyUrls()) rather than one per
        // candidate.
        if (is_array($data['streams'] ?? null)) {
            $rawUrlsByIndex = [];

            foreach ($data['streams'] as $index => $stream) {
                if (is_string($stream['url'] ?? null) && $stream['url'] !== '') {
                    $rawUrlsByIndex[$index] = $stream['url'];
                }
            }

            $proxiedUrlsByIndex = MediaServerProxyController::generateAioStreamsLiveProxyUrls($integrationId, $rawUrlsByIndex);

            foreach ($proxiedUrlsByIndex as $index => $proxiedUrl) {
                $data['streams'][$index]['url'] = $proxiedUrl;
            }
        }

        return response()->json($data);
    }

    /**
     * Proxy a meta request.
     * Route: GET /{username}/{password}/aiostreams/{integration}/meta/{type}/{id}.json
     */
    public function meta(Request $request, string $username, string $password, int $integrationId, string $type, string $id): JsonResponse
    {
        $playlist = null;
        $integration = $this->resolveIntegration($username, $password, $integrationId, $playlist);

        if (! $integration) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $cacheKey = "aiostreams.meta.{$integrationId}.{$type}.{$id}";

        // Delegates to AIOStreamsService::fetchMeta(), which falls back to the
        // public Stremio meta addons (Cinemeta / Kitsu / TMDB) when the operator's
        // AIOStreams instance has no metadata addon configured and 404s the
        // request, then (when enabled) enriches the meta object with TMDB
        // cast_list / clearlogo / season metadata. Keeps this proxy path and the
        // admin/guest browse UI on one implementation.
        $data = Cache::remember($cacheKey, 300, function () use ($integration, $type, $id) {
            return AIOStreamsService::make($integration)->fetchMeta($type, $id);
        });

        if ($data === null) {
            return response()->json(['error' => 'Meta not found'], 404);
        }

        // Route any TMDB-sourced images (cast photos, transparent title logo)
        // through the logo proxy so clients never hit image.tmdb.org directly -
        // mirrors XtreamApiController's get_vod_info / get_series_info handling.
        if ($playlist && $playlist->enable_logo_proxy && is_array($data['meta'] ?? null)) {
            $data['meta'] = $this->proxyMetaImages($data['meta']);
        }

        return response()->json($data);
    }

    /**
     * Wrap the TMDB-enriched image URLs on a meta object in the logo proxy.
     * Only the keys the enrichment step adds are touched - poster/background
     * from the upstream Stremio addon are left as-is (parity with how the
     * pre-enrichment passthrough behaved).
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function proxyMetaImages(array $meta): array
    {
        if (is_string($meta['clearlogo'] ?? null)) {
            $meta['clearlogo'] = XtreamApiController::proxyImageUrl($meta['clearlogo'], ImageProfile::TitleLogo);
        }

        if (is_array($meta['cast_list'] ?? null)) {
            $meta['cast_list'] = array_map(function ($member) {
                if (is_array($member) && isset($member['photo'])) {
                    $member['photo'] = XtreamApiController::proxyImageUrl($member['photo'], ImageProfile::Photo);
                }

                return $member;
            }, $meta['cast_list']);
        }

        return $meta;
    }

    /**
     * Split a Stremio content id into its base id and any trailing
     * ":season:episode" coordinates.
     *
     *   "tmdb:1399:1:1" => ["tmdb:1399", ":1:1"]
     *   "tmdb:603"      => ["tmdb:603", ""]
     *
     * @return array{0: string, 1: string}
     */
    private function splitStremioId(string $id): array
    {
        $parts = explode(':', $id);
        $baseId = implode(':', array_slice($parts, 0, 2));
        $suffix = count($parts) > 2 ? ':'.implode(':', array_slice($parts, 2)) : '';

        return [$baseId, $suffix];
    }

    /**
     * Authenticate the request and resolve the AIOStreams integration for the given
     * credentials - only the integration actually assigned to the caller's effective
     * playlist is ever returned, never an arbitrary integration ID owned by the same
     * user (see #1384). Mirrors the authorization Xtream's feature advertisement uses.
     *
     * @param  Playlist|MergedPlaylist|CustomPlaylist|null  $playlist
     *                                                                 Out-param set to the caller's effective playlist when authentication
     *                                                                 succeeds, so callers can read playlist-level settings (e.g.
     *                                                                 enable_logo_proxy) without a second authenticate() round trip.
     */
    private function resolveIntegration(string $username, string $password, int $integrationId, &$playlist = null): ?MediaServerIntegration
    {
        $auth = PlaylistFacade::authenticate($username, $password);

        if (! $auth || $auth[0] === null || $auth[1] === 'none') {
            return null;
        }

        [$playlist, $authMethod] = $auth;

        $playlistAuth = $authMethod === 'playlist_auth'
            ? PlaylistAuth::where('username', $username)
                ->where('password', $password)
                ->where('enabled', true)
                ->first()
            : null;

        if ($playlistAuth && $playlistAuth->isExpired()) {
            return null;
        }

        $authService = app(AIOStreamsAuthorizationService::class);

        if (! $authService->isAuthorizedForIntegration($playlist, $authMethod, $playlistAuth, $integrationId)) {
            return null;
        }

        return MediaServerIntegration::where('id', $integrationId)
            ->where('type', 'aiostreams')
            ->where('enabled', true)
            ->whereNotNull('manifest_url')
            ->first();
    }
}
