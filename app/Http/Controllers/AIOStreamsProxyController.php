<?php

namespace App\Http\Controllers;

use App\Facades\PlaylistFacade;
use App\Models\MediaServerIntegration;
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

        // Streams are not cached — always fetch fresh to get current availability
        $response = Http::timeout(30)->get("{$integration->manifest_base_url}/stream/{$type}/{$id}.json");

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
        $integration = $this->resolveIntegration($username, $password, $integrationId);

        if (! $integration) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $cacheKey = "aiostreams.meta.{$integrationId}.{$type}.{$id}";

        // Delegates to AIOStreamsService::fetchMeta(), which falls back to the
        // public Stremio meta addons (Cinemeta / Kitsu / TMDB) when the operator's
        // AIOStreams instance has no metadata addon configured and 404s the
        // request. Keeps this proxy path and the admin/guest browse UI on one
        // implementation.
        $data = Cache::remember($cacheKey, 300, function () use ($integration, $type, $id) {
            return AIOStreamsService::make($integration)->fetchMeta($type, $id);
        });

        if ($data === null) {
            return response()->json(['error' => 'Meta not found'], 404);
        }

        return response()->json($data);
    }

    /**
     * Authenticate the request and resolve the AIOStreams integration for the given
     * credentials - only the integration actually assigned to the caller's effective
     * playlist is ever returned, never an arbitrary integration ID owned by the same
     * user (see #1384). Mirrors the authorization Xtream's feature advertisement uses.
     */
    private function resolveIntegration(string $username, string $password, int $integrationId): ?MediaServerIntegration
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
