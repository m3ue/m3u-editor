<?php

namespace App\Http\Controllers\Api;

use App\Facades\PlaylistFacade;
use App\Http\Controllers\Controller;
use App\Http\Middleware\DispatcharrAuthMiddleware;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Services\PlaylistUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

/**
 * Dispatcharr-compatible API controller.
 *
 * Implements the subset of Dispatcharr API endpoints that emby-xtream
 * expects, using the existing PlaylistAuth credentials for authentication.
 */
class DispatcharrController extends Controller
{
    /**
     * Access token TTL: 1 hour.
     */
    private const ACCESS_TTL = 3600;

    /**
     * Refresh token TTL: 7 days.
     */
    private const REFRESH_TTL = 604800;

    /**
     * POST /api/accounts/token/
     *
     * Authenticate with PlaylistAuth credentials and return JWT-like tokens.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $result = PlaylistFacade::authenticate(
            $request->input('username'),
            $request->input('password')
        );

        if (! $result || ($result[1] ?? 'none') === 'none') {
            return response()->json(['detail' => 'No active account found with the given credentials'], 401);
        }

        [$playlist, $authMethod] = $result;

        $tokenPayload = $this->buildTokenPayload($playlist);

        return response()->json([
            'access' => DispatcharrAuthMiddleware::createToken($tokenPayload, self::ACCESS_TTL),
            'refresh' => DispatcharrAuthMiddleware::createToken(
                array_merge($tokenPayload, ['type' => 'refresh']),
                self::REFRESH_TTL
            ),
        ]);
    }

    /**
     * POST /api/accounts/token/refresh/
     *
     * Refresh an access token using a valid refresh token.
     */
    public function refresh(Request $request): JsonResponse
    {
        $request->validate(['refresh' => 'required|string']);

        $payload = DispatcharrAuthMiddleware::verifyToken($request->input('refresh'));

        if (! $payload || ($payload['type'] ?? '') !== 'refresh') {
            return response()->json(['detail' => 'Token is invalid or expired'], 401);
        }

        if (($payload['exp'] ?? 0) < time()) {
            return response()->json(['detail' => 'Token is invalid or expired'], 401);
        }

        unset($payload['type'], $payload['exp'], $payload['iat']);

        return response()->json([
            'access' => DispatcharrAuthMiddleware::createToken($payload, self::ACCESS_TTL),
            'refresh' => DispatcharrAuthMiddleware::createToken(
                array_merge($payload, ['type' => 'refresh']),
                self::REFRESH_TTL
            ),
        ]);
    }

    /**
     * GET /api/channels/profiles/
     *
     * Return playlist groups as Dispatcharr-compatible profiles.
     * Each group becomes a profile containing its channel IDs.
     */
    public function profiles(Request $request): JsonResponse
    {
        $playlist = $this->resolvePlaylist($request);
        if (! $playlist) {
            return response()->json(['detail' => 'Playlist not found'], 404);
        }

        $profiles = [];

        // Build one profile per live group with its enabled channel IDs
        $groups = $playlist->groups()
            ->whereHas('channels', fn ($q) => $q->where('enabled', true)->where('is_vod', false))
            ->orderBy('sort_order')
            ->with(['enabled_live_channels:id,group_id'])
            ->get();

        foreach ($groups as $group) {
            $profiles[] = [
                'id' => $group->id,
                'name' => $group->name,
                'channels' => $group->enabled_live_channels->pluck('id')->values()->all(),
            ];
        }

        return response()->json($profiles);
    }

    /**
     * GET /api/channels/channels/
     *
     * Return channels in Dispatcharr-compatible format with embedded streams and stream_stats.
     * Supports offset-based pagination via `offset` query parameter.
     */
    public function channels(Request $request): JsonResponse
    {
        $playlist = $this->resolvePlaylist($request);
        if (! $playlist) {
            return response()->json(['detail' => 'Playlist not found'], 404);
        }

        $limit = min((int) $request->input('limit', 2000), 10000);
        $offset = max((int) $request->input('offset', 0), 0);
        $includeStreams = $request->boolean('include_streams', false);

        $channelsQuery = $playlist->channels()
            ->where('channels.enabled', true)
            ->where('channels.is_vod', false)
            ->orderBy('channels.channel');

        $total = $channelsQuery->count();

        $channels = $channelsQuery
            ->offset($offset)
            ->limit($limit)
            ->get();

        $result = [];
        foreach ($channels as $channel) {
            $entry = [
                'id' => $channel->id,
                'uuid' => $channel->uuid,
                'name' => $channel->name_custom ?? $channel->name,
                'channel_number' => $channel->channel ?: null,
                'tvg_id' => $channel->source_id ?? $channel->stream_id ?? (string) $channel->id,
                'tvc_guide_stationid' => '',
            ];

            if ($includeStreams) {
                $streamStats = $channel->getEmbyStreamStats();

                $entry['streams'] = [
                    [
                        'id' => $channel->id,
                        'name' => $channel->name_custom ?? $channel->name,
                        'stream_id' => is_numeric($channel->source_id)
                            ? (int) $channel->source_id
                            : $channel->id,
                        'stream_stats' => ! empty($streamStats) ? $streamStats : null,
                    ],
                ];
            }

            $result[] = $entry;
        }

        return response()->json($result, 200, [
            'X-Total-Count' => $total,
        ]);
    }

    /**
     * GET /proxy/ts/stream/{uuid}
     *
     * Stream proxy endpoint. Looks up the channel by its stable uuid,
     * resolves the playlist from the authenticated bearer token, then redirects
     * to the actual stream URL or proxies through m3u-proxy.
     */
    public function proxyStream(Request $request, string $uuid): RedirectResponse|JsonResponse
    {
        $channel = Channel::where('uuid', $uuid)->first();
        if (! $channel || ! $channel->enabled) {
            return response()->json(['error' => 'Channel not found'], 404);
        }

        // Try bearer token first (authenticated API client)
        $bearer = $request->bearerToken();
        $playlist = null;

        if ($bearer) {
            $payload = DispatcharrAuthMiddleware::verifyToken($bearer);
            if ($payload && ($payload['exp'] ?? 0) >= time()) {
                $playlist = $this->resolvePlaylistFromPayload($payload);
            }
        }

        // Fallback: find any playlist that owns this channel
        if (! $playlist) {
            $playlist = $channel->playlist;
        }

        if (! $playlist) {
            return response()->json(['error' => 'Playlist not found'], 404);
        }

        if ($playlist->enable_proxy) {
            return app()->call('App\Http\Controllers\Api\M3uProxyApiController@channel', [
                'id' => $channel->id,
                'uuid' => $playlist->uuid,
            ]);
        }

        $streamUrl = PlaylistUrlService::getChannelUrl($channel, $playlist);

        return Redirect::to($streamUrl);
    }

    /**
     * GET /api/vod/movies/{streamId}/
     *
     * Return VOD movie detail (UUID) for a given Xtream stream ID.
     */
    public function vodMovieDetail(Request $request, int $streamId): JsonResponse
    {
        $playlist = $this->resolvePlaylist($request);
        if (! $playlist) {
            return response()->json(['detail' => 'Playlist not found'], 404);
        }

        $channel = $playlist->channels()
            ->where('channels.is_vod', true)
            ->where('channels.enabled', true)
            ->where(function ($q) use ($streamId) {
                $q->where('channels.source_id', (string) $streamId)
                    ->orWhere('channels.id', $streamId);
            })
            ->first();

        if (! $channel) {
            return response()->json(['detail' => 'Movie not found'], 404);
        }

        return response()->json([
            'id' => $channel->id,
            'uuid' => $channel->uuid,
        ]);
    }

    /**
     * GET /api/vod/movies/{streamId}/providers/
     *
     * Return provider streams for a VOD movie.
     */
    public function vodMovieProviders(Request $request, int $streamId): JsonResponse
    {
        $playlist = $this->resolvePlaylist($request);
        if (! $playlist) {
            return response()->json(['detail' => 'Playlist not found'], 404);
        }

        $channel = $playlist->channels()
            ->where('channels.is_vod', true)
            ->where('channels.enabled', true)
            ->where(function ($q) use ($streamId) {
                $q->where('channels.source_id', (string) $streamId)
                    ->orWhere('channels.id', $streamId);
            })
            ->first();

        if (! $channel) {
            return response()->json([], 200);
        }

        return response()->json([
            [
                'id' => $channel->id,
                'stream_id' => is_numeric($channel->source_id)
                    ? (int) $channel->source_id
                    : $channel->id,
                'name' => $channel->name_custom ?? $channel->name,
            ],
        ]);
    }

    /**
     * Resolve a playlist from JWT token payload.
     */
    private function resolvePlaylistFromPayload(array $payload): Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null
    {
        $type = $payload['playlist_type'] ?? '';
        $id = $payload['playlist_id'] ?? 0;

        return match ($type) {
            Playlist::class => Playlist::find($id),
            CustomPlaylist::class => CustomPlaylist::find($id),
            MergedPlaylist::class => MergedPlaylist::find($id),
            PlaylistAlias::class => PlaylistAlias::find($id),
            default => null,
        };
    }

    /**
     * Resolve the playlist model from the authenticated token payload.
     */
    private function resolvePlaylist(Request $request): Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null
    {
        $payload = $request->attributes->get('dispatcharr_payload');
        if (! $payload) {
            return null;
        }

        return $this->resolvePlaylistFromPayload($payload);
    }

    /**
     * Build the token payload from an authenticated playlist.
     *
     * @return array{playlist_id: int, playlist_type: string, user_id: int}
     */
    private function buildTokenPayload(Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias $playlist): array
    {
        return [
            'playlist_id' => $playlist->id,
            'playlist_type' => get_class($playlist),
            'user_id' => $playlist->user_id,
        ];
    }
}
