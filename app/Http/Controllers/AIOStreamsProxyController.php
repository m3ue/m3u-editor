<?php

namespace App\Http\Controllers;

use App\Models\CustomPlaylist;
use App\Models\MediaServerIntegration;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

        return response()->json($response->json());
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

        $data = Cache::remember($cacheKey, 300, function () use ($integration, $type, $id) {
            $response = Http::timeout(15)->get("{$integration->manifest_base_url}/meta/{$type}/{$id}.json");

            return $response->successful() ? $response->json() : null;
        });

        if ($data === null) {
            return response()->json(['error' => 'Meta not found'], 404);
        }

        return response()->json($data);
    }

    /**
     * Authenticate the request and resolve an enabled AIOStreams integration for the given credentials.
     */
    private function resolveIntegration(string $username, string $password, int $integrationId): ?MediaServerIntegration
    {
        $userId = $this->resolveUserId($username, $password);

        if (! $userId) {
            return null;
        }

        return MediaServerIntegration::where('id', $integrationId)
            ->where('user_id', $userId)
            ->where('type', 'aiostreams')
            ->where('enabled', true)
            ->whereNotNull('manifest_url')
            ->first();
    }

    /**
     * Resolve a user ID from playlist credentials (username + password).
     * Mirrors the auth logic in XtreamStreamController.
     */
    private function resolveUserId(string $username, string $password): ?int
    {
        // Method 1: PlaylistAuth credentials
        $playlistAuth = PlaylistAuth::where('username', $username)
            ->where('password', $password)
            ->where('enabled', true)
            ->first();

        if ($playlistAuth && ! $playlistAuth->isExpired()) {
            $model = $playlistAuth->getAssignedModel();
            if ($model) {
                return $model->user_id;
            }
        }

        // Method 2: Playlist UUID as password
        $models = [Playlist::class, MergedPlaylist::class, CustomPlaylist::class, PlaylistAlias::class];

        foreach ($models as $modelClass) {
            try {
                $record = $modelClass::with('user')->where('uuid', $password)->firstOrFail();
                if ($record->user->name === $username) {
                    return $record->user_id;
                }
            } catch (ModelNotFoundException) {
                continue;
            }
        }

        // PlaylistAlias with direct username/password match
        try {
            $alias = PlaylistAlias::with('user')
                ->where('username', $username)
                ->where('password', $password)
                ->firstOrFail();

            return $alias->user_id;
        } catch (ModelNotFoundException) {
            return null;
        }
    }
}
