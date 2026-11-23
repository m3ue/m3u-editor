<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\M3uProxyApiController;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Episode;
use App\Models\MergedPlaylist;
use App\Models\Network;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Services\AIOStreamsAuthorizationService;
use App\Services\CachedContentDispatchService;
use App\Services\MediaSourcePreferenceService;
use App\Services\PlaylistCredentialResolver;
use App\Services\PlaylistService;
use App\Services\PlaylistUrlService;
use App\Services\TimeshiftBufferService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;

class XtreamStreamController extends Controller
{
    /**
     * How long (seconds) a player may cache a direct (non-proxied) catchup redirect.
     */
    private const TIMESHIFT_DIRECT_REDIRECT_MAX_AGE = 3600;

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
     * Authenticates a playlist using either PlaylistAuth credentials or the owner's default
     * login (username = playlist owner's name, password = playlist UUID or custom password).
     *
     * Returns [$playlist, $streamModel, $playlistAuth] where $playlistAuth is non-null
     * only when authentication succeeded via PlaylistAuth credentials.
     */
    private function findAuthenticatedPlaylistAndStreamModel(string $username, string $password, string|int $streamId, string $streamType): array
    {
        $streamModel = null;
        $playlist = null;
        $resolvedPlaylistAuth = null;

        // Method 1: Try to authenticate using PlaylistAuth credentials
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
                // Load necessary relationships for the playlist
                $playlist->load(['user']);
                $resolvedPlaylistAuth = $playlistAuth;
            }
        }

        // Method 2: Fall back to the owner's default login (username = playlist owner,
        // password = playlist UUID or custom password, per playlist), then an alias' own credentials
        // unless the alias has expired (matching PlaylistService::authenticate())
        if (! $playlist) {
            $playlist = app(PlaylistCredentialResolver::class)->resolveDefaultLogin($username, $password)
                ?? PlaylistAlias::with(['user'])
                    ->where('username', $username)
                    ->where('password', $password)
                    ->get()
                    ->first(fn (PlaylistAlias $alias): bool => ! $alias->isExpired());
        }

        // If no authentication method worked, return null
        if (! $playlist) {
            return [null, null, null];
        }

        // Get the stream model
        $streamModel = $this->getValidatedStreamFromPlaylist($playlist, $streamId, $streamType);

        return [$playlist, $streamModel, $resolvedPlaylistAuth];
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
        [$playlist, $channel, $playlistAuth] = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'live');

        // Handle network playlists. The stream_id in a network playlist's HDHR/Xtream
        // URLs is the projected Channel's own primary key (see
        // NetworkChannelSyncService::upsertChannel()), not the Network's primary key -
        // those are independently allocated and can collide. Resolve the Network via
        // the projected Channel's network_id instead of treating stream_id as a Network id.
        if ($playlist instanceof Playlist && $playlist->is_network_playlist) {
            if (! $channel instanceof Channel || $channel->network_id === null) {
                return response()->json(['error' => 'Network not found or not enabled'], 404);
            }

            return $this->handleNetworkStream($playlist, $channel->network_id);
        }

        if ($channel instanceof Channel) {
            // Shared rolling timeshift buffer (?timeshift=1): explicit opt-in
            // from clients with timeshift mode on.
            //  - Warm buffer → redirect to seekable HLS window immediately.
            //  - Cold → fall through to plain live (no wait); the proxy path
            //    kicks the buffer off against the same pooled stream.
            $wantsTimeshift = $request->input('timeshift') === '1' && config('timeshift.enabled', true);
            // A .m3u8 request must be answered with real HLS on every platform:
            // iOS/AVPlayer and web/hls.js cannot play the raw MPEG-TS plain-live
            // fallback that a cold buffer used to serve at this URL. Only .ts
            // requests keep the instant raw path.
            $wantsHls = in_array(strtolower((string) $format), ['m3u8', 'hls'], true);
            // Android/desktop opt into plain-live-first: still kick the buffer
            // off (timeshift=1), but keep serving the smooth raw live stream
            // until the viewer rewinds. Riding the live edge of a 2 s-segment
            // HLS window re-buffers at every segment boundary.
            $forcePlain = $request->input('timeshift_plain') === '1';
            // Only for timeshift-opted requests: a plain live open (timeshift
            // disabled, or a client without the feature) logs nothing.
            if ($request->has('timeshift') || $request->has('timeshift_plain') || $request->has('timeshift_hls')) {
                Log::info('timeshift live request', [
                    'stream_id' => $streamId,
                    'format' => $format,
                    'query_keys' => array_keys($request->query->all()),
                    'timeshift' => $request->input('timeshift'),
                    'timeshift_hls' => $request->input('timeshift_hls'),
                    'timeshift_plain' => $request->input('timeshift_plain'),
                    'player' => $request->input('player'),
                    'profile' => $request->input('profile'),
                    'channel_profile_id' => $channel->stream_profile_id,
                    'playlist_profile_id' => $playlist?->stream_profile_id,
                    'channel_id' => $channel->id,
                    'playlist_uuid' => $playlist?->uuid,
                    'wants_timeshift' => $wantsTimeshift,
                    'force_plain' => $forcePlain,
                ]);
            }
            if ($wantsTimeshift && ! $forcePlain) {
                $playbackUrl = app(TimeshiftBufferService::class)
                    ->resolvePlaybackUrl($channel, $playlist->uuid);
                if ($playbackUrl !== null) {
                    return Redirect::to($playbackUrl)
                        ->setPrivate()
                        ->setMaxAge(10);
                }
                // Cold / not ready → plain live below; kickOff runs after the
                // pooled stream URL is known (proxy branch).
            }

            // Catchup (utc/lutc) requests are served from a failover with catchup when this
            // channel has none, the same as the /timeshift/ route.
            $isTimeshiftRequest = PlaylistService::isTimeshiftRequest($request);
            $streamChannel = $channel;
            if ($isTimeshiftRequest) {
                $request->attributes->set('requested_channel_id', $channel->id);
                $streamChannel = $this->resolveCatchupChannel($channel);
            }

            // When the channel's source playlist pools provider profiles, the proxy path
            // must be taken even if enable_proxy is off on both the channel and the
            // (possibly merged/custom) playlist being streamed through - profile
            // selection and pool distribution only happen on the proxy path.
            // Timeshift does NOT force the proxy path: OTA/direct channels that
            // normally bypass the proxy must keep doing so (forcing proxy broke
            // them with 503 JSON → ExoPlayer "Source error"). kickOff only
            // attaches to an existing proxy stream, so those channels simply
            // get plain live with no rewind buffer.
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
                if ($wantsTimeshift) {
                    $request->attributes->set('timeshift_kickoff', [
                        'channel_id' => $channel->id,
                        'playlist_uuid' => $playlist->uuid,
                    ]);
                }

                // player=true signals an in-app player request — route to channelPlayer
                // so the in-app transcoding profile is applied instead of the playlist profile
                $method = $request->input('player') === 'true' ? 'channelPlayer' : 'channel';

                $proxyResponse = app()->call([app(M3uProxyApiController::class), $method], [
                    'id' => $streamChannel->id,
                    'uuid' => $playlist->uuid,
                ]);

                if (
                    $wantsTimeshift
                    && $wantsHls
                    && config('timeshift.hls_first_cold', true)
                    && $request->input('timeshift_hls') === '1'
                    && $proxyResponse instanceof RedirectResponse
                ) {
                    $bufferService = app(TimeshiftBufferService::class);
                    $bufferId = TimeshiftBufferService::bufferIdFor($channel);
                    $deadline = microtime(true) + 4.0;

                    do {
                        if ($bufferService->isReady($bufferId)) {
                            return Redirect::to(
                                $bufferService->signedPlaylistUrl($bufferId)
                            )->setPrivate()->setMaxAge(10);
                        }
                        usleep(100000);
                    } while (microtime(true) < $deadline);

                    // A cold/failed buffer must never hand a .m3u8 request a
                    // raw TS proxy stream: ExoPlayer reports "Input does not
                    // start with #EXTM3U" and renders black before retrying.
                    // The signed playlist controller serves a valid empty live
                    // playlist while the first segments are still warming.
                    Log::info('timeshift hls cold fallback', [
                        'buffer_id' => $bufferId,
                        'channel_id' => $channel->id,
                        'stream_id' => $streamId,
                        'wait_seconds' => 4,
                    ]);

                    return Redirect::to(
                        $bufferService->signedPlaylistUrl($bufferId)
                    )->setPrivate()->setMaxAge(10);
                }

                return $proxyResponse;
            } else {
                // Get the base stream URL
                $streamUrl = PlaylistUrlService::getChannelUrl($streamChannel, $playlist);
                if ($isTimeshiftRequest) {
                    // Timeshift stream request: TiviMate/M3U utc+lutc (UNIX epochs), or Xtream
                    // timeshift_duration (minutes) + timeshift_date (YYYY-MM-DD:HH-MM-SS)
                    return $this->timeshiftRedirect(PlaylistService::generateTimeshiftUrl($request, $streamUrl, $playlist, $streamChannel));
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
        if (str_starts_with($streamId, 'aio-')) {
            return $this->handleAioStreamsPlayback($request, $username, $password, $streamId);
        }

        // Validate that streamId is numeric to prevent database errors
        if (! is_numeric($streamId)) {
            return response()->json(['error' => 'Invalid stream ID'], 400);
        }

        $format = $format ?? 'ts'; // Default to 'ts' if no format provided
        [$playlist, $channel, $playlistAuth] = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'vod');
        if ($channel instanceof Channel) {
            // Serve a locally cached copy when one is available (see resolveCacheHit()).
            if ($cacheRedirect = $this->resolveCacheHit($channel, $playlist, $username, $password, $format)) {
                return $cacheRedirect;
            }

            // See handleLive(): pooled-provider playlists must use the proxy path so
            // profile selection and pool distribution are applied.
            $needsProxy = Channel::needsProxy(
                channelEnableProxy: (bool) $channel->enable_proxy,
                playlistEnableProxy: (bool) $playlist->enable_proxy,
                requestProxyFlag: $request->input('proxy') === 'true',
                sourcePlaylistProfilesEnabled: $channel->playlist instanceof Playlist && $channel->playlist->profiles_enabled,
                userCanUseProxy: $playlist->user->canUseProxy(),
            );

            if ($needsProxy) {
                // Add username and PlaylistAuth ID to request for proxy traceability and per-auth enforcement
                $request->merge(['username' => $username]);
                if ($playlistAuth instanceof PlaylistAuth) {
                    $request->merge(['playlist_auth_id' => $playlistAuth->id]);
                }
                $this->applyClientStreamProfile($request, $playlist, $playlistAuth);

                // player=true signals an in-app player request — apply in-app transcoding profile
                $method = $request->input('player') === 'true' ? 'channelPlayer' : 'channel';

                return app()->call([app(M3uProxyApiController::class), $method], [
                    'id' => $streamId,
                    'uuid' => $playlist->uuid,
                ]);
            } else {
                // Media-server source preference: stream the matched media
                // item with its own playlist context instead of the
                // provider's (needsProxy above still reflects the request
                // context; proxy requests forward the provider id to
                // M3uProxyApiController, which does the swap itself).
                $media = app(MediaSourcePreferenceService::class)->resolveChannel($channel);
                if ($media !== null) {
                    $channel = $media;
                    $playlist = $media->getEffectivePlaylist();
                }

                return Redirect::to($this->applyMediaFlowProxy(PlaylistUrlService::getChannelUrl($channel, $playlist)));
            }
        }

        return response()->json(['error' => 'Unauthorized or stream not found'], 403);
    }

    /**
     * Series episode stream requests.
     */
    public function handleSeries(Request $request, string $username, string $password, string|int $streamId, ?string $format = null)
    {
        if (is_string($streamId) && str_starts_with($streamId, 'aio-')) {
            return $this->handleAioStreamsPlayback($request, $username, $password, $streamId);
        }

        // Validate that streamId is numeric to prevent database errors
        if (! is_numeric($streamId)) {
            return response()->json(['error' => 'Invalid stream ID'], 400);
        }

        $format = $format ?? 'mp4'; // Default to 'mp4' if no format provided
        [$playlist, $episode, $playlistAuth] = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'episode');
        if ($episode instanceof Episode) {
            // Serve a locally cached copy when one is available (see resolveCacheHit()).
            if ($cacheRedirect = $this->resolveCacheHit($episode, $playlist, $username, $password, $format)) {
                return $cacheRedirect;
            }

            if (($playlist->enable_proxy || $request->input('proxy') === 'true') && $playlist->user->canUseProxy()) {
                // Add username and PlaylistAuth ID to request for proxy traceability and per-auth enforcement
                $request->merge(['username' => $username]);
                if ($playlistAuth instanceof PlaylistAuth) {
                    $request->merge(['playlist_auth_id' => $playlistAuth->id]);
                }
                $this->applyClientStreamProfile($request, $playlist, $playlistAuth);

                // player=true signals an in-app player request — apply in-app transcoding profile
                $method = $request->input('player') === 'true' ? 'episodePlayer' : 'episode';

                return app()->call([app(M3uProxyApiController::class), $method], [
                    'id' => $streamId,
                    'uuid' => $playlist->uuid,
                ]);
            } else {
                // Media-server source preference (see handleVod()).
                $media = app(MediaSourcePreferenceService::class)->resolveEpisode($episode);
                if ($media !== null) {
                    $episode = $media;
                    $playlist = $media->playlist;
                }

                return Redirect::to($this->applyMediaFlowProxy(PlaylistUrlService::getEpisodeUrl($episode, $playlist)));
            }
        }

        return response()->json(['error' => 'Unauthorized or stream not found'], 403);
    }

    /**
     * Play an AIOStreams stream candidate handed out by player_api.php's
     * get_aiostreams_streams action (see
     * MediaServerProxyController::generateAioStreamsXtreamPlaybackUrls()). Every
     * request, Range requests included, re-checks that the credentials may still
     * use the integration the token belongs to, so disabling a viewer or
     * unassigning the integration stops playback at once.
     */
    private function handleAioStreamsPlayback(Request $request, string $username, string $password, string $streamId)
    {
        $parsed = MediaServerProxyController::parseAioStreamsXtreamStreamId($streamId);
        if (! $parsed) {
            return response()->json(['error' => 'Invalid stream ID'], 400);
        }

        [$integrationId, $token] = $parsed;

        $integration = app(AIOStreamsAuthorizationService::class)
            ->authorizedIntegrationForCredentials($username, $password, $integrationId);
        if (! $integration) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return app(MediaServerProxyController::class)->streamAioStreamsLiveToken($request, $integration->id, $token);
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
        // Validate that streamId is numeric to prevent database errors
        if (! is_numeric($streamId)) {
            return response()->json(['error' => 'Invalid stream ID'], 400);
        }

        $format = $format ?? 'ts'; // Default to 'ts' if no format provided

        // Timeshift is only available for live channels
        [$playlist, $channel, $playlistAuth] = $this->findAuthenticatedPlaylistAndStreamModel($username, $password, $streamId, 'timeshift');

        if (! ($channel instanceof Channel)) {
            return response()->json(['error' => 'Unauthorized or stream not found'], 403);
        }

        // Recorded before any catchup failover swap below: the TV app stops its stream by the
        // channel it asked for (see M3uProxyService::getChannelUrl's requested_channel_id).
        $request->attributes->set('requested_channel_id', $channel->id);

        $timeshiftChannel = $this->resolveCatchupChannel($channel);

        // Convert Unix timestamp to Xtream format if the player sends a numeric date string.
        // The result is reinterpreted downstream using config('app.timezone'), so it must be
        // formatted in that same timezone to preserve the absolute instant the client sent.
        if (ctype_digit($date)) {
            $date = Carbon::createFromTimestamp((int) $date, config('app.timezone', 'UTC'))->format('Y-m-d:H-i-s');
        }

        // Parse the date parameter and add timeshift parameters to the request
        // Expected downstream format: YYYY-MM-DD:HH-MM-SS
        // Also add username for proxy traceability
        $mergeData = [
            'timeshift_duration' => $duration,
            'timeshift_date' => $date,
            'username' => $username,
        ];
        if ($playlistAuth instanceof PlaylistAuth) {
            $mergeData['playlist_auth_id'] = $playlistAuth->id;
        }
        $request->merge($mergeData);

        if (($playlist->enable_proxy || $request->input('proxy') === 'true') && $playlist->user->canUseProxy()) {
            $this->applyClientStreamProfile($request, $playlist, $playlistAuth);

            return app()->call([app(M3uProxyApiController::class), 'channel'], [
                'id' => $timeshiftChannel->id,
                'uuid' => $playlist->uuid,
            ]);
        } else {
            $streamUrl = PlaylistUrlService::getChannelUrl($timeshiftChannel, $playlist);

            return $this->timeshiftRedirect(PlaylistService::generateTimeshiftUrl($request, $streamUrl, $playlist, $timeshiftChannel));
        }
    }

    /**
     * The channel to serve catchup from: its linked catchup channel when one is set and usable
     * (#1661), else the requested one, or when it doesn't support catchup, its first failover
     * that does. This allows an HD primary (no catchup) to fall back to a lower-res channel for
     * timeshift without changing its live playback.
     */
    private function resolveCatchupChannel(Channel $channel): Channel
    {
        $linkedChannel = $channel->linkedCatchupChannel();
        if ($linkedChannel) {
            return $linkedChannel;
        }

        if ($channel->catchup && $channel->catchup != 0) {
            return $channel;
        }

        return $channel->failoverChannels()
            ->whereNotNull('catchup')
            ->where('catchup', '!=', '0')
            ->where('catchup', '!=', '')
            ->first() ?? $channel;
    }

    /**
     * Redirect a direct (non-proxied) catchup request to the provider. FFmpeg-based players
     * (mpv) restart every seek's Range requests from the original URL unless the redirect is
     * cacheable, so this lets them go straight to the provider for the rest of the session.
     * The provider URL for a programme window doesn't change, so a long max-age is safe.
     */
    private function timeshiftRedirect(string $streamUrl): RedirectResponse
    {
        return Redirect::to($this->applyMediaFlowProxy($streamUrl))
            ->setPrivate()
            ->setMaxAge(self::TIMESHIFT_DIRECT_REDIRECT_MAX_AGE);
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
     * Cache-hit gate. Redirects to the cached-content stream route when a
     * playable cached file exists for this item (its own, or one shared by
     * another of the same user's playlists), otherwise returns null so the
     * normal live/proxy path runs.
     *
     * Skipped entirely while `enable_cache` is off, and for Custom/Merged
     * playlists and aliases (only plain Playlists own cached files). A row
     * whose file is missing on disk falls back to live instead of sending
     * the client to a 404.
     */
    private function resolveCacheHit(Channel|Episode $item, Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias $playlist, string $username, string $password, string $routeFormat): ?RedirectResponse
    {
        if (! app(CachedContentDispatchService::class)->isEnabled()) {
            return null;
        }

        if (! $playlist instanceof Playlist) {
            return null;
        }

        $cached = CachedContentFile::findServableFor($item);

        if ($cached === null || ! $cached->isPlayable()) {
            return null;
        }

        // Local media wins over a cached copy: when a reachable media-server
        // match exists, fall through so the normal path swaps to it. If the
        // server is down the cached copy still plays.
        if ($item->playlist?->prefer_media_server_sources
            && app(MediaSourcePreferenceService::class)->resolveForStreaming($item) !== $item
        ) {
            return null;
        }

        return Redirect::to(PlaylistUrlService::credentialRoute('cached-content.stream', [
            'username' => $username,
            'password' => $password,
            'uuid' => $cached->uuid,
            'format' => $routeFormat,
        ]));
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
