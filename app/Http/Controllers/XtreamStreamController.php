<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\M3uProxyApiController;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Episode;
use App\Models\MergedPlaylist;
use App\Models\Network;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Services\M3uProxyService;
use App\Services\PlaylistService;
use App\Services\PlaylistUrlService;
use App\Services\ProviderAuthPassthroughService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;

class XtreamStreamController extends Controller
{
    /**
     * Validate a client-requested transcoding profile (?profile=<id>|none) and stash
     * the result in request attributes for the proxy controller. Request attributes
     * are server-side only, so downstream code can trust the resolved value.
     *
     * Invalid or unauthorized selections are ignored (playback continues with the
     * default proxy behavior) rather than failing the stream — a client may hold a
     * profile the admin has since revoked.
     */
    private function applyClientStreamProfile(Request $request, $playlist, ?PlaylistAuth $playlistAuth): void
    {
        $requested = $request->input('profile');
        if ($requested === null || $requested === '') {
            return;
        }

        // Explicit direct proxy — suppress the playlist-level default profile.
        if ($requested === 'none' || $requested === '0') {
            $request->attributes->set('client_stream_profile', 'none');

            return;
        }

        if (! is_numeric($requested)) {
            return;
        }

        $profileId = (int) $requested;

        // PlaylistAuth users may only use profiles the auth allows; owner/alias
        // credentials may use any of the playlist owner's profiles.
        if ($playlistAuth instanceof PlaylistAuth && ! $playlistAuth->allowsProxyStreamProfile($profileId)) {
            return;
        }

        $request->attributes->set('client_stream_profile', $profileId);
    }

    /**
     * Authenticates a playlist using either PlaylistAuth credentials or the original method
     * (username = playlist owner's name, password = playlist UUID).
     */
    /**
     * Returns [$playlist, $streamModel, $playlistAuth] where $playlistAuth is non-null
     * only when authentication succeeded via PlaylistAuth credentials.
     */
    private function findAuthenticatedPlaylistAndStreamModel(string $username, string $password, string|int $streamId, string $streamType): array
    {
        $streamModel = null;
        $playlist = null;
        $resolvedPlaylistAuth = null;
        $passthrough = null;

        $playlistAuth = PlaylistAuth::where('username', $username)
            ->where('password', $password)
            ->where('enabled', true)
            ->first();

        if ($playlistAuth && $playlistAuth->isExpired()) {
            $playlistAuth = null;
        }

        if ($playlistAuth) {
            $playlist = $playlistAuth->getAssignedModel();

            if ($playlist) {
                $playlist->load(['user']);
                $resolvedPlaylistAuth = $playlistAuth;
            }
        }

        if (! $playlist) {
            try {
                $playlist = Playlist::with(['user'])
                    ->where('uuid', $password)
                    ->firstOrFail();

                if ($playlist->user->name !== $username) {
                    $playlist = null;
                }
            } catch (ModelNotFoundException $e) {
                $playlist = null;
            }
        }

        if (! $playlist) {
            try {
                $playlist = MergedPlaylist::with(['user'])
                    ->where('uuid', $password)
                    ->firstOrFail();

                if ($playlist->user->name !== $username) {
                    $playlist = null;
                }
            } catch (ModelNotFoundException $e) {
                $playlist = null;
            }
        }

        if (! $playlist) {
            try {
                $playlist = CustomPlaylist::with(['user'])
                    ->where('uuid', $password)
                    ->firstOrFail();

                if ($playlist->user->name !== $username) {
                    $playlist = null;
                }
            } catch (ModelNotFoundException $e) {
                $playlist = null;
            }
        }

        if (! $playlist) {
            try {
                $playlist = PlaylistAlias::with(['user'])
                    ->where('uuid', $password)
                    ->orWhere(fn ($query) => $query->where([
                        ['username', $username],
                        ['password', $password],
                    ]))
                    ->firstOrFail();

                if (! ($playlist->username === $username && $playlist->password === $password)) {
                    if ($playlist->user->name !== $username) {
                        $playlist = null;
                    }
                }
            } catch (ModelNotFoundException $e) {
                $playlist = null;
            }
        }

        if (! $playlist) {
            $passthrough = app(ProviderAuthPassthroughService::class)->authenticate(
                $username,
                $password
            );

            if ($passthrough) {
                $playlist = $passthrough['playlist'];
            }
        }

        if (! $playlist) {
            return [null, null, null, null];
        }

        $streamModel = $this->getValidatedStreamFromPlaylist(
            $playlist,
            $streamId,
            $streamType
        );

        return [
            $playlist,
            $streamModel,
            $resolvedPlaylistAuth,
            $passthrough,
        ];
    }

    /**
     * Validates if a stream (Channel or Episode) exists, is enabled, and belongs to the given authenticated playlist.
     * Returns the stream Model (Channel or Episode) if valid, otherwise null.
     */
    private function getValidatedStreamFromPlaylist(Model $playlist, string|int $streamId, string $streamType): ?Model
    {
        // Live and VOD streams are handled the same
        if ($streamType === 'live' || $streamType === 'vod' || $streamType === 'timeshift') {
            // Assuming all playlist types have a 'channels' relationship defined.
            $channel = $playlist->channels()
                ->where('channels.id', $streamId) // Qualify column name if pivot table involved
                ->where('enabled', true)
                ->first();

            // Intentionally does not log the channel's URL - for M3U-sourced channels
            // it embeds the upstream provider's plaintext credentials.
            Log::debug('getValidatedStreamFromPlaylist lookup', [
                'stream_id' => $streamId,
                'stream_type' => $streamType,
                'playlist_type' => get_class($playlist),
                'playlist_id' => $playlist->id,
                'playlist_uuid' => $playlist->uuid ?? null,
                'playlist_name' => $playlist->name ?? null,
                'channel_found' => $channel !== null,
                'channel_id' => $channel->id ?? null,
            ]);

            return $channel;
        }

        if ($streamType === 'episode') {
            $episode = Episode::with('season.series')->find($streamId);
            if (! $episode) {
                return null; // Episode or its hierarchy not found
            }
            $series = $episode->season()->first()->series ?? null;
            if (! $series) {
                return null; // Series not found
            }
            if (! $series->enabled) {
                return null; // Series is disabled
            }

            // Validate series membership in the playlist.
            // This assumes all playlist types (Playlist, MergedPlaylist, CustomPlaylist)
            // have a 'series' relationship defined that correctly links to App\Models\Series.
            $isMember = $playlist->series()
                ->where('series.id', $series->id) // Qualify column name
                ->exists();

            return $isMember ? $episode : null;
        }

        return null;
    }

    /**
     * Handle direct stream requests.
     *
     * Determine best path when `/live/`, `/movie/`, or `/series/` is not specified.
     */
    public function handleDirect(Request $request, string $username, string $password, string|int $streamId, ?string $format = null)
    {
        // Validate that streamId is numeric to prevent database errors
        if (! is_numeric($streamId)) {
            return response()->json(['error' => 'Invalid stream ID'], 400);
        }

        // If no live or VOD stream type specified, determine stream type by model
        $model = Channel::find($streamId);
        if ($model instanceof Channel) {
            if ($model->is_vod) {
                return $this->handleVod($request, $username, $password, $streamId, $format);
            } else {
                return $this->handleLive($request, $username, $password, $streamId, $format);
            }
        }
        $model = Episode::find($streamId);
        if ($model instanceof Episode) {
            return $this->handleSeries($request, $username, $password, $streamId, $format);
        }

        return response()->json(['error' => 'Stream not found'], 404);
    }

    /**
     * Live stream requests.
     *
     * @tags Xtream API Streams
     *
     * @summary Provides live stream access.
     *
     * @description Authenticates the request based on Xtream credentials provided in the path.
     * If successful and the requested channel is valid and part of an authorized playlist,
     * this endpoint redirects to the actual internal stream URL.
     * The route for this endpoint is typically `/live/{username}/{password}/{streamId}.{format}`.
     *
     * @param  Request  $request  The HTTP request
     * @param  string  $uuid  The UUID of the Xtream API (path parameter)
     * @param  string  $username  User's Xtream API username (path parameter)
     * @param  string  $password  User's Xtream API password (path parameter)
     * @param  string  $streamId  The ID of the live stream (channel ID) (path parameter)
     * @param  string  $format  The requested stream format (e.g., 'ts', 'm3u8') (path parameter)
     *
     * @response 302 scenario="Successful redirect to stream URL" description="Redirects to the internal live stream URL."
     * @response 403 scenario="Forbidden/Unauthorized" {"error": "Unauthorized or stream not found"}
     *
     * @unauthenticated
     */
    public function handleLive(Request $request, string $username, string $password, string|int $streamId, ?string $format = null)
    {
        // Validate that streamId is numeric to prevent database errors
        if (! is_numeric($streamId)) {
            return response()->json(['error' => 'Invalid stream ID'], 400);
        }

        $format = $format ?? 'ts'; // Default to 'ts' if no format provided
        [$playlist, $channel, $playlistAuth, $passthrough] = $this->findAuthenticatedPlaylistAndStreamModel(
            $username,
            $password,
            $streamId,
            'live'
        );

        // Handle network playlists - stream_id is actually a network ID
        if ($playlist instanceof Playlist && $playlist->is_network_playlist) {
            return $this->handleNetworkStream($playlist, $streamId);
        }

        if ($channel instanceof Channel) {
            if ($passthrough && $playlist instanceof Playlist) {
                $passthroughService = app(ProviderAuthPassthroughService::class);

                $streamUrl = $passthroughService->buildLiveUrl(
                    $playlist,
                    $channel,
                    $username,
                    $password,
                    $passthrough['provider_url'] ?? null
                );

                if (! $streamUrl) {
                    return response()->json([
                        'error' => 'Unable to build provider stream URL',
                    ], 502);
                }

                if (! $playlist->provider_auth_passthrough_live) {
                    return Redirect::to($streamUrl);
                }

                if (! $playlist->user->canUseProviderAuthPassthrough()) {
                    return response()->json([
                        'error' => 'Provider Authentication Passthrough is not available',
                    ], 503);
                }

                try {
                    $proxyFormat = strtolower(
                        trim((string) ($playlist->xtream_config['output'] ?? 'ts'))
                    );

                    $proxyUrl = app(M3uProxyService::class)->createDirectStreamUrl(
                        url: $streamUrl,
                        headers: $playlist->custom_headers ?? [],
                        userAgent: $playlist->user_agent ?: null,
                        format: $proxyFormat,
                        metadata: [
                            'id' => (string) $channel->id,
                            'channel_id' => (string) $channel->id,
                            'type' => 'channel',
                            'playlist_uuid' => $playlist->uuid,
                            'source_playlist_uuid' => $playlist->uuid,
                            'auth_method' => 'provider_passthrough',
                        ],
                        username: $username,
                    );

                    return Redirect::to($proxyUrl);
                } catch (\Throwable) {
                    return response()->json([
                        'error' => 'Unable to create proxy stream',
                    ], 502);
                }
            }
            // When the channel's source playlist pools provider profiles, the proxy path
            // must be taken even if enable_proxy is off on both the channel and the
            // (possibly merged/custom) playlist being streamed through - profile
            // selection and pool distribution only happen on the proxy path.
            $needsProxy = Channel::needsProxy(
                channelEnableProxy: (bool) $channel->enable_proxy,
                playlistEnableProxy: (bool) $playlist->enable_proxy,
                requestProxyFlag: $request->input('proxy') === 'true',
                sourcePlaylistProfilesEnabled: $channel->playlist instanceof Playlist && $channel->playlist->profiles_enabled,
                userCanUseProxy: $playlist->user->canUseProxy(),
            );

            if ($needsProxy) {
                // Timeshift handled in proxy controller (if needed)
                // Add username and PlaylistAuth ID to request for proxy traceability and per-auth enforcement
                $request->merge(['username' => $username]);
                if ($playlistAuth instanceof PlaylistAuth) {
                    $request->merge(['playlist_auth_id' => $playlistAuth->id]);
                }
                $this->applyClientStreamProfile($request, $playlist, $playlistAuth);

                // player=true signals an in-app player request — route to channelPlayer
                // so the in-app transcoding profile is applied instead of the playlist profile
                $method = $request->input('player') === 'true' ? 'channelPlayer' : 'channel';

                return app()->call([app(M3uProxyApiController::class), $method], [
                    'id' => $streamId,
                    'uuid' => $playlist->uuid,
                ]);
            } else {
                // Check if this is a timeshift request
                // TiviMate sends utc/lutc as UNIX epochs (UTC). We only convert TZ + format.
                $utcPresent = $request->filled('utc');

                // Xtream API sends timeshift_duration (minutes) and timeshift_date (YYYY-MM-DD:HH-MM-SS)
                $xtreamTimeshiftPresent = $request->filled('timeshift_duration') && $request->filled('timeshift_date');

                // Get the base stream URL
                $streamUrl = PlaylistUrlService::getChannelUrl($channel, $playlist);
                if ($utcPresent || $xtreamTimeshiftPresent) {
                    // Timeshift stream request
                    $streamUrl = PlaylistService::generateTimeshiftUrl($request, $streamUrl, $playlist, $channel);
                }

                // Regular live stream request, redirect to the stream URL (via MediaFlow Proxy if enabled)
                return Redirect::to($this->applyMediaFlowProxy($streamUrl));
            }
        }

        return response()->json(['error' => 'Unauthorized or stream not found'], 403);
    }

    /**
     * VOD stream requests.
     */
    public function handleVod(Request $request, string $username, string $password, string $streamId, ?string $format = null)
    {
        if (! is_numeric($streamId)) {
            return response()->json(['error' => 'Invalid stream ID'], 400);
        }

        $format = $format ?? 'ts';

        [$playlist, $channel, $playlistAuth, $passthrough] = $this->findAuthenticatedPlaylistAndStreamModel(
            $username,
            $password,
            $streamId,
            'vod'
        );

        if ($channel instanceof Channel) {
            if ($passthrough && $playlist instanceof Playlist) {
                $passthroughService = app(ProviderAuthPassthroughService::class);

                $streamUrl = $passthroughService->buildVodUrl(
                    $playlist,
                    $channel,
                    $username,
                    $password,
                    $passthrough['provider_url'] ?? null
                );

                if (! $streamUrl) {
                    return response()->json([
                        'error' => 'Unable to build provider stream URL',
                    ], 502);
                }

                if (! $playlist->provider_auth_passthrough_vod) {
                    return Redirect::to($streamUrl);
                }

                if (! $playlist->user->canUseProviderAuthPassthrough()) {
                    return response()->json([
                        'error' => 'Proxy is not available for this playlist',
                    ], 503);
                }

                try {
                    $proxyUrl = app(M3uProxyService::class)->createDirectStreamUrl(
                        url: $streamUrl,
                        headers: $playlist->custom_headers ?? [],
                        userAgent: $playlist->user_agent ?: null,
                        format: 'raw',
                        metadata: [
                            'id' => (string) $channel->id,
                            'channel_id' => (string) $channel->id,
                            'type' => 'vod',
                            'playlist_uuid' => $playlist->uuid,
                            'source_playlist_uuid' => $playlist->uuid,
                            'auth_method' => 'provider_passthrough',
                        ],
                        username: $username,
                    );

                    return Redirect::to($proxyUrl);
                } catch (\Throwable) {
                    return response()->json([
                        'error' => 'Unable to create proxy stream',
                    ], 502);
                }
            }

            $needsProxy = Channel::needsProxy(
                channelEnableProxy: (bool) $channel->enable_proxy,
                playlistEnableProxy: (bool) $playlist->enable_proxy,
                requestProxyFlag: $request->input('proxy') === 'true',
                sourcePlaylistProfilesEnabled: $channel->playlist instanceof Playlist && $channel->playlist->profiles_enabled,
                userCanUseProxy: $playlist->user->canUseProxy(),
            );

            if ($needsProxy) {
                $request->merge(['username' => $username]);

                if ($playlistAuth instanceof PlaylistAuth) {
                    $request->merge(['playlist_auth_id' => $playlistAuth->id]);
                }

                $this->applyClientStreamProfile(
                    $request,
                    $playlist,
                    $playlistAuth
                );

                $method = $request->input('player') === 'true'
                    ? 'channelPlayer'
                    : 'channel';

                return app()->call(
                    [app(M3uProxyApiController::class), $method],
                    [
                        'id' => $streamId,
                        'uuid' => $playlist->uuid,
                    ]
                );
            }

            return Redirect::to(
                $this->applyMediaFlowProxy(
                    PlaylistUrlService::getChannelUrl(
                        $channel,
                        $playlist
                    )
                )
            );
        }

        return response()->json([
            'error' => 'Unauthorized or stream not found',
        ], 403);
    }

    /**
     * Series episode stream requests.
     */
    public function handleSeries(Request $request, string $username, string $password, string|int $streamId, ?string $format = null)
    {
        if (! is_numeric($streamId)) {
            return response()->json(['error' => 'Invalid stream ID'], 400);
        }

        $format = $format ?? 'mp4';

        [$playlist, $episode, $playlistAuth, $passthrough] = $this->findAuthenticatedPlaylistAndStreamModel(
            $username,
            $password,
            $streamId,
            'episode'
        );

        if ($episode instanceof Episode) {
            if ($passthrough && $playlist instanceof Playlist) {
                $passthroughService = app(ProviderAuthPassthroughService::class);

                $streamUrl = $passthroughService->buildSeriesUrl(
                    $playlist,
                    $episode,
                    $username,
                    $password,
                    $passthrough['provider_url'] ?? null
                );

                if (! $streamUrl) {
                    return response()->json([
                        'error' => 'Unable to build provider stream URL',
                    ], 502);
                }

                if (! $playlist->provider_auth_passthrough_series) {
                    return Redirect::to($streamUrl);
                }

                if (! $playlist->user->canUseProviderAuthPassthrough()) {
                    return response()->json([
                        'error' => 'Proxy is not available for this playlist',
                    ], 503);
                }

                try {
                    $proxyUrl = app(M3uProxyService::class)->createDirectStreamUrl(
                        url: $streamUrl,
                        headers: $playlist->custom_headers ?? [],
                        userAgent: $playlist->user_agent ?: null,
                        format: 'raw',
                        metadata: [
                            'id' => (string) $episode->id,
                            'episode_id' => (string) $episode->id,
                            'type' => 'episode',
                            'playlist_uuid' => $playlist->uuid,
                            'source_playlist_uuid' => $playlist->uuid,
                            'auth_method' => 'provider_passthrough',
                        ],
                        username: $username,
                    );

                    return Redirect::to($proxyUrl);
                } catch (\Throwable) {
                    return response()->json([
                        'error' => 'Unable to create proxy stream',
                    ], 502);
                }
            }

            if (
                ($playlist->enable_proxy || $request->input('proxy') === 'true')
                && $playlist->user->canUseProxy()
            ) {
                $request->merge(['username' => $username]);

                if ($playlistAuth instanceof PlaylistAuth) {
                    $request->merge(['playlist_auth_id' => $playlistAuth->id]);
                }

                $this->applyClientStreamProfile(
                    $request,
                    $playlist,
                    $playlistAuth
                );

                $method = $request->input('player') === 'true'
                    ? 'episodePlayer'
                    : 'episode';

                return app()->call(
                    [app(M3uProxyApiController::class), $method],
                    [
                        'id' => $streamId,
                        'uuid' => $playlist->uuid,
                    ]
                );
            }

            return Redirect::to(
                $this->applyMediaFlowProxy(
                    PlaylistUrlService::getEpisodeUrl(
                        $episode,
                        $playlist
                    )
                )
            );
        }

        return response()->json([
            'error' => 'Unauthorized or stream not found',
        ], 403);
    }

    /**
     * Timeshift stream requests.
     *
     * @tags Xtream API Streams
     *
     * @summary Provides timeshift streaming access for live channels.
     *
     * @description Handles Xtream API timeshift requests. Authenticates the request based on
     * Xtream credentials provided in the path. If successful and the requested channel is valid
     * and part of an authorized playlist, this endpoint provides timeshift access to replay
     * content from a specific date and time.
     *
     * The route for this endpoint is typically `/timeshift/{username}/{password}/{duration}/{date}/{streamId}.{format}`.
     *
     * @param  Request  $request  The HTTP request
     * @param  string  $username  User's Xtream API username (path parameter)
     * @param  string  $password  User's Xtream API password (path parameter)
     * @param  int  $duration  Duration of timeshift in minutes (path parameter)
     * @param  string  $date  Date and time in format YYYY-MM-DD:HH-MM-SS (path parameter)
     * @param  int  $streamId  The ID of the live stream (channel ID) (path parameter)
     * @param  string  $format  The requested stream format (e.g., 'ts', 'm3u8') (path parameter)
     *
     * @response 302 scenario="Successful redirect to timeshift stream URL" description="Redirects to the internal timeshift stream URL."
     * @response 403 scenario="Forbidden/Unauthorized" {"error": "Unauthorized or stream not found"}
     *
     * @unauthenticated
     */
    public function handleTimeshift(Request $request, string $username, string $password, int $duration, string $date, string|int $streamId, ?string $format = null)
    {
        if (! is_numeric($streamId)) {
            return response()->json(['error' => 'Invalid stream ID'], 400);
        }

        $format = $format ?? 'ts';

        [$playlist, $channel, $playlistAuth, $passthrough] = $this->findAuthenticatedPlaylistAndStreamModel(
            $username,
            $password,
            $streamId,
            'timeshift'
        );

        if (! ($channel instanceof Channel)) {
            return response()->json([
                'error' => 'Unauthorized or stream not found',
            ], 403);
        }

        $timeshiftChannel = $channel;

        if (! $channel->catchup || $channel->catchup == 0) {
            $failoverWithCatchup = $channel->failoverChannels()
                ->whereNotNull('catchup')
                ->where('catchup', '!=', '0')
                ->where('catchup', '!=', '')
                ->first();

            if ($failoverWithCatchup) {
                $timeshiftChannel = $failoverWithCatchup;
            }
        }

        if ($passthrough && $playlist instanceof Playlist) {
            $passthroughService = app(ProviderAuthPassthroughService::class);

            $streamUrl = $passthroughService->buildTimeshiftUrl(
                $playlist,
                $timeshiftChannel,
                $username,
                $password,
                $duration,
                $date,
                $passthrough['provider_url'] ?? null
            );

            if (! $streamUrl) {
                return response()->json([
                    'error' => 'Unable to build provider timeshift URL',
                ], 502);
            }

            if (! $playlist->provider_auth_passthrough_live) {
                return Redirect::to($streamUrl);
            }

            if (! $playlist->user->canUseProviderAuthPassthrough()) {
                return response()->json([
                    'error' => 'Proxy is not available for this playlist',
                ], 503);
            }

            try {
                $proxyUrl = app(M3uProxyService::class)->createDirectStreamUrl(
                    url: $streamUrl,
                    headers: $playlist->custom_headers ?? [],
                    userAgent: $playlist->user_agent ?: null,
                    format: 'raw',
                    metadata: [
                        'id' => (string) $timeshiftChannel->id,
                        'channel_id' => (string) $timeshiftChannel->id,
                        'type' => 'timeshift',
                        'playlist_uuid' => $playlist->uuid,
                        'source_playlist_uuid' => $playlist->uuid,
                        'auth_method' => 'provider_passthrough',
                    ],
                    username: $username,
                );

                return Redirect::to($proxyUrl);
            } catch (\Throwable) {
                return response()->json([
                    'error' => 'Unable to create proxy stream',
                ], 502);
            }
        }

        if (ctype_digit($date)) {
            $date = Carbon::createFromTimestamp((int) $date)
                ->format('Y-m-d:H-i-s');
        }

        $mergeData = [
            'timeshift_duration' => $duration,
            'timeshift_date' => $date,
            'username' => $username,
        ];

        if ($playlistAuth instanceof PlaylistAuth) {
            $mergeData['playlist_auth_id'] = $playlistAuth->id;
        }

        $request->merge($mergeData);

        if (
            ($playlist->enable_proxy || $request->input('proxy') === 'true')
            && $playlist->user->canUseProxy()
        ) {
            $this->applyClientStreamProfile(
                $request,
                $playlist,
                $playlistAuth
            );

            return app()->call(
                [app(M3uProxyApiController::class), 'channel'],
                [
                    'id' => $timeshiftChannel->id,
                    'uuid' => $playlist->uuid,
                ]
            );
        }

        $streamUrl = PlaylistUrlService::getChannelUrl(
            $timeshiftChannel,
            $playlist
        );

        $streamUrl = PlaylistService::generateTimeshiftUrl(
            $request,
            $streamUrl,
            $playlist,
            $timeshiftChannel
        );

        return Redirect::to(
            $this->applyMediaFlowProxy($streamUrl)
        );
    }

    /**
     * If MediaFlow Proxy stream URL rewriting is enabled, wrap the given stream URL
     * through the appropriate MediaFlow Proxy endpoint. Otherwise returns the URL unchanged.
     */
    private function applyMediaFlowProxy(string $streamUrl): string
    {
        $service = app(PlaylistService::class);
        if ($service->mediaFlowProxyEnabled() && ($service->getMediaFlowSettings()['mediaflow_proxy_rewrite_stream_urls'] ?? false)) {
            return $service->buildMediaFlowStreamUrl($streamUrl);
        }

        return $streamUrl;
    }

    /**
     * Handle network stream requests.
     * Redirects to the network's HLS playlist.
     */
    private function handleNetworkStream(Playlist $playlist, string|int $networkId)
    {
        $network = $playlist->networks()
            ->where('id', $networkId)
            ->where('enabled', true)
            ->first();

        if (! $network) {
            return response()->json(['error' => 'Network not found or not enabled'], 404);
        }

        // Check if network is broadcasting
        if (! $network->broadcast_enabled) {
            return response()->json(['error' => 'Network broadcast not enabled'], 503);
        }

        // Redirect to the network's HLS playlist
        return Redirect::to($network->stream_url);
    }
}
