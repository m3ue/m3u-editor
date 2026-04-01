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
     */
    public function channels(Request $request): JsonResponse
    {
        $playlist = $this->resolvePlaylist($request);
        if (! $playlist) {
            return response()->json(['detail' => 'Playlist not found'], 404);
        }

        $limit = (int) $request->input('limit', 2000);
        $includeStreams = $request->boolean('include_streams', false);

        $channelsQuery = $playlist->channels()
            ->where('channels.enabled', true)
            ->where('channels.is_vod', false)
            ->orderBy('channels.channel')
            ->limit(min($limit, 10000));

        $channels = $channelsQuery->get();

        $payload = $request->attributes->get('dispatcharr_payload');

        $result = [];
        foreach ($channels as $channel) {
            $entry = [
                'id' => $channel->id,
                'uuid' => DispatcharrAuthMiddleware::createStreamToken(
                    $channel->id,
                    $payload['playlist_id'],
                    $payload['playlist_type']
                ),
                'name' => $channel->name_custom ?? $channel->name,
                'channel_number' => $channel->channel ?: null,
                'tvg_id' => $channel->source_id ?? $channel->stream_id ?? (string) $channel->id,
                'tvc_guide_stationid' => $channel->source_id ?? '',
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

        return response()->json($result);
    }

    /**
     * GET /proxy/ts/stream/{token}
     *
     * Stream proxy endpoint. Decodes the signed stream token to resolve
     * the channel and playlist, then redirects to the actual stream URL
     * or proxies through m3u-proxy.
     */
    public function proxyStream(Request $request, string $token): RedirectResponse|JsonResponse
    {
        $streamPayload = DispatcharrAuthMiddleware::verifyStreamToken($token);
        if (! $streamPayload) {
            return response()->json(['error' => 'Invalid stream token'], 403);
        }

        $channel = Channel::find($streamPayload['c']);
        if (! $channel || ! $channel->enabled) {
            return response()->json(['error' => 'Channel not found'], 404);
        }

        $playlist = $this->resolvePlaylistFromStreamPayload($streamPayload);
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
     * Resolve a playlist from stream token payload.
     */
    private function resolvePlaylistFromStreamPayload(array $payload): Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null
    {
        return match ($payload['t']) {
            Playlist::class => Playlist::find($payload['p']),
            CustomPlaylist::class => CustomPlaylist::find($payload['p']),
            MergedPlaylist::class => MergedPlaylist::find($payload['p']),
            PlaylistAlias::class => PlaylistAlias::find($payload['p']),
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
