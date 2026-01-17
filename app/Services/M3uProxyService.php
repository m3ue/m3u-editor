<?php

namespace App\Services;

use App\Facades\PlaylistFacade;
use App\Facades\ProxyFacade;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Episode;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\StreamProfile;
use App\Settings\GeneralSettings;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class M3uProxyService
{
    protected string $apiBaseUrl;

    protected ?string $apiPublicUrl;

    protected ?string $apiToken;

    protected bool $autoResolve;

    protected bool $usingFailoverResolver;

    protected bool $stopOldestOnLimit;

    protected ?string $failoverResolverUrl;

    public function __construct()
    {
        $this->apiBaseUrl = rtrim(config('proxy.m3u_proxy_host'), '/');
        if ($port = config('proxy.m3u_proxy_port')) {
            $this->apiBaseUrl .= ':'.$port;
        }

        $this->apiPublicUrl = config('proxy.m3u_proxy_public_url') ? rtrim(config('proxy.m3u_proxy_public_url'), '/') : null;
        $this->apiToken = config('proxy.m3u_proxy_token');

        // Default to not stopping oldest on limit
        $this->stopOldestOnLimit = false;

        // Configure URL resolver settings
        $this->autoResolve = false;
        $this->usingFailoverResolver = false;
        $this->failoverResolverUrl = null;

        // Get failover resolver URL (`M3U_PROXY_FAILOVER_RESOLVER_URL` env var), if set
        $configFailoverResolver = config('proxy.resolver_url');

        // Load settings values
        try {
            // Load settings from GeneralSettings
            $settings = app(GeneralSettings::class);
            $this->stopOldestOnLimit = (bool) ($settings->proxy_stop_oldest_on_limit ?? false);
            $this->autoResolve = (bool) ($settings->m3u_proxy_public_url_auto_resolve ?? false);
            $this->usingFailoverResolver = (bool) ($settings->enable_failover_resolver ?? false);
            $this->failoverResolverUrl = rtrim($settings->failover_resolver_url ?? '', '/');
        } catch (Exception $e) {
        }

        // If config value is set, override settings values for failover resolver configuration
        if (! empty($configFailoverResolver)) {
            $this->usingFailoverResolver = true;
            $this->failoverResolverUrl = rtrim($configFailoverResolver, '/');
        }
    }

    /**
     * Get the current proxy mode: 'embedded' or 'external'
     */
    public function mode(): string
    {
        return config('proxy.external_proxy_enabled') ? 'external' : 'embedded';
    }

    /**
     * Check if failover resolver URL should be used
     */
    public function usingResolver(): bool
    {
        return $this->usingFailoverResolver && ! empty($this->failoverResolverUrl);
    }

    /**
     * Test the resolver URL by asking the proxy to verify it can reach the editor.
     * Returns an array with 'success' boolean and 'message' string.
     *
     * @param  string|null  $url  Optional URL to test instead of the configured failover resolver
     */
    public function testResolver($url = null): array
    {
        if (empty($this->apiBaseUrl)) {
            return [
                'success' => false,
                'message' => 'M3U Proxy base URL is not configured',
            ];
        }

        if (empty(! $url || $this->failoverResolverUrl)) {
            return [
                'success' => false,
                'message' => 'Failover resolver URL is not configured',
            ];
        }

        try {
            // Call the proxy's test-url endpoint to verify it can reach the editor
            $endpoint = $this->apiBaseUrl.'/test-connection';
            $response = Http::timeout(15)->acceptJson()
                ->withHeaders($this->apiToken ? [
                    'X-API-Token' => $this->apiToken,
                ] : [])
                ->post($endpoint, [
                    'url' => ($url ?? $this->failoverResolverUrl).'/up', // Use the Laravel health check endpoint
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => $data['success'] ?? false,
                    'message' => $data['message'] ?? 'Unknown response from proxy',
                    'url_tested' => $data['url_tested'] ?? $this->failoverResolverUrl,
                ];
            }

            return [
                'success' => false,
                'message' => 'Proxy returned status '.$response->status(),
            ];
        } catch (Exception $e) {
            Log::warning('Failed to test resolver URL: '.$e->getMessage());

            return [
                'success' => false,
                'message' => 'Unable to connect to proxy: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get active streams count for a specific playlist using metadata filtering
     */
    public static function getPlaylistActiveStreamsCount($playlist): int
    {
        $service = new self;

        if (empty($service->apiBaseUrl)) {
            return 0;
        }

        try {
            $endpoint = $service->apiBaseUrl.'/streams/by-metadata';
            $response = Http::timeout(3)->acceptJson()
                ->withHeaders($service->apiToken ? [
                    'X-API-Token' => $service->apiToken,
                ] : [])
                ->get($endpoint, [
                    'field' => 'playlist_uuid',
                    'value' => $playlist->uuid,
                    'active_only' => true,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return $data['total_clients'] ?? 0; // Return total client count across all streams
            }

            Log::warning('Failed to fetch playlist streams from m3u-proxy: HTTP '.$response->status());

            return 0;
        } catch (Exception $e) {
            Log::warning('Failed to fetch playlist streams from m3u-proxy: '.$e->getMessage());

            return 0;
        }
    }

    /**
     * Get active streams for a specific playlist using metadata filtering
     */
    public static function getPlaylistActiveStreams($playlist): array
    {
        $service = new self;

        if (empty($service->apiBaseUrl)) {
            return [];
        }

        try {
            $endpoint = $service->apiBaseUrl.'/streams/by-metadata';
            $response = Http::timeout(3)->acceptJson()
                ->withHeaders($service->apiToken ? [
                    'X-API-Token' => $service->apiToken,
                ] : [])
                ->get($endpoint, [
                    'field' => 'playlist_uuid',
                    'value' => $playlist->uuid,
                    'active_only' => true,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return $data['matching_streams'] ?? [];
            }

            Log::warning('Failed to fetch playlist streams from m3u-proxy: HTTP '.$response->status());

            return [];
        } catch (Exception $e) {
            Log::warning('Failed to fetch playlist streams from m3u-proxy: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Check if a specific channel is active using metadata filtering
     */
    public static function isChannelActive(Channel $channel): bool
    {
        $service = new self;

        if (empty($service->apiBaseUrl)) {
            return false;
        }

        try {
            $endpoint = $service->apiBaseUrl.'/streams/by-metadata';
            $response = Http::timeout(2)->acceptJson()
                ->withHeaders($service->apiToken ? [
                    'X-API-Token' => $service->apiToken,
                ] : [])
                ->get($endpoint, [
                    'field' => 'type',
                    'value' => 'channel',
                    'active_only' => true,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                // Check if any matching stream has this channel ID
                foreach ($data['matching_streams'] ?? [] as $stream) {
                    if (
                        isset($stream['metadata']['id']) &&
                        $stream['metadata']['id'] == $channel->id &&
                        $stream['client_count'] > 0
                    ) {
                        return true;
                    }
                }
            }

            return false;
        } catch (Exception $e) {
            Log::warning('Failed to check channel active status: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Get active streams count by any metadata field/value combination
     */
    public static function getActiveStreamsCountByMetadata(string $field, string $value): int
    {
        $service = new self;

        if (empty($service->apiBaseUrl)) {
            return 0;
        }

        try {
            $endpoint = $service->apiBaseUrl.'/streams/by-metadata';
            $response = Http::timeout(3)->acceptJson()
                ->withHeaders($service->apiToken ? [
                    'X-API-Token' => $service->apiToken,
                ] : [])
                ->get($endpoint, [
                    'field' => $field,
                    'value' => $value,
                    'active_only' => true,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                return $data['total_clients'] ?? 0;
            }

            return 0;
        } catch (Exception $e) {
            Log::warning("Failed to get active streams count for {$field}={$value}: ".$e->getMessage());

            return 0;
        }
    }

    /**
     * Get cached active streams count with smart invalidation
     */
    public static function getCachedActiveStreamsCountByMetadata(string $field, string $value, int $cacheTtlSeconds = 2): int
    {
        $cacheKey = "m3u_proxy_active_count:{$field}:{$value}";

        // Try to get from cache first
        $cachedCount = Cache::get($cacheKey);
        if ($cachedCount !== null) {
            return $cachedCount;
        }

        // Fetch fresh count
        $count = self::getActiveStreamsCountByMetadata($field, $value);

        // Cache for specified TTL
        Cache::put($cacheKey, $count, now()->addSeconds($cacheTtlSeconds));

        return $count;
    }

    /**
     * Get cached playlist active streams count
     */
    public static function getCachedPlaylistActiveStreamsCount($playlist, int $cacheTtlSeconds = 2): int
    {
        return self::getCachedActiveStreamsCountByMetadata('playlist_uuid', $playlist->uuid, $cacheTtlSeconds);
    }

    /**
     * Invalidate cache for specific metadata field/value
     */
    public static function invalidateMetadataCache(string $field, string $value): void
    {
        $cacheKey = "m3u_proxy_active_count:{$field}:{$value}";
        Cache::forget($cacheKey);
    }

    /**
     * Invalidate cache when we know a playlist's stream status changed
     */
    public static function invalidatePlaylistCache($playlist): void
    {
        self::invalidateMetadataCache('playlist_uuid', $playlist->uuid);
    }

    /**
     * Stop all streams matching a specific metadata field/value.
     *
     * This is useful for connection limit management - when switching channels
     * on a limited connection playlist, stop the old stream first.
     *
     * @param  string  $field  Metadata field to filter by (e.g., 'playlist_uuid', 'type')
     * @param  string  $value  Value to match
     * @param  int|null  $excludeChannelId  Optional channel ID to exclude (keep this stream)
     * @return array Result with deleted_count and success status
     */
    public static function stopStreamsByMetadata(string $field, string $value, ?int $excludeChannelId = null): array
    {
        $service = new self;

        if (empty($service->apiBaseUrl)) {
            return [
                'success' => false,
                'message' => 'M3U Proxy base URL is not configured',
                'deleted_count' => 0,
            ];
        }

        try {
            $endpoint = $service->apiBaseUrl.'/streams/by-metadata';
            $params = [
                'field' => $field,
                'value' => $value,
            ];

            if ($excludeChannelId !== null) {
                $params['exclude_channel_id'] = (string) $excludeChannelId;
            }

            $response = Http::timeout(5)->acceptJson()
                ->withHeaders($service->apiToken ? [
                    'X-API-Token' => $service->apiToken,
                ] : [])
                ->delete($endpoint, $params);

            if ($response->successful()) {
                $data = $response->json();

                // Invalidate cache since we just stopped streams
                self::invalidateMetadataCache($field, $value);

                Log::info('Successfully stopped streams by metadata', [
                    'field' => $field,
                    'value' => $value,
                    'exclude_channel_id' => $excludeChannelId,
                    'deleted_count' => $data['deleted_count'] ?? 0,
                ]);

                return [
                    'success' => true,
                    'message' => $data['message'] ?? 'Streams stopped successfully',
                    'deleted_count' => $data['deleted_count'] ?? 0,
                    'deleted_streams' => $data['deleted_streams'] ?? [],
                ];
            }

            Log::warning('Failed to stop streams by metadata: HTTP '.$response->status());

            return [
                'success' => false,
                'message' => 'HTTP error: '.$response->status(),
                'deleted_count' => 0,
            ];
        } catch (Exception $e) {
            Log::warning("Failed to stop streams by metadata ({$field}={$value}): ".$e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'deleted_count' => 0,
            ];
        }
    }

    /**
     * Stop all streams for a specific playlist, optionally excluding a channel ID.
     *
     * This is used when switching channels on a connection-limited playlist
     * to free up the connection before starting a new stream.
     *
     * @param  string  $playlistUuid  The playlist UUID
     * @param  int|null  $excludeChannelId  Optional channel ID to exclude (keep this stream)
     * @return array Result with deleted_count and success status
     */
    public static function stopPlaylistStreams(string $playlistUuid, ?int $excludeChannelId = null): array
    {
        return self::stopStreamsByMetadata('playlist_uuid', $playlistUuid, $excludeChannelId);
    }

    /**
     * Stop the OLDEST stream for a specific playlist.
     *
     * This implements a "latest wins" behavior - when a playlist reaches its
     * connection limit, stop the oldest stream to make room for the new one.
     *
     * Only deletes ONE stream (the oldest), unlike stopPlaylistStreams which deletes all.
     *
     * @param  string  $playlistUuid  The playlist UUID
     * @param  int|null  $excludeChannelId  Optional channel ID to exclude (keep this stream)
     * @return array Result with deleted_count and success status
     */
    public static function stopOldestPlaylistStream(string $playlistUuid, ?int $excludeChannelId = null): array
    {
        $service = new self;

        if (empty($service->apiBaseUrl)) {
            return [
                'success' => false,
                'message' => 'M3U Proxy base URL is not configured',
                'deleted_count' => 0,
            ];
        }

        try {
            // Build query parameters for DELETE request
            $params = [
                'field' => 'playlist_uuid',
                'value' => $playlistUuid,
            ];

            if ($excludeChannelId !== null) {
                $params['exclude_channel_id'] = (string) $excludeChannelId;
            }

            // Laravel's Http::delete() doesn't support query params as second argument
            // We need to append them to the URL
            $endpoint = $service->apiBaseUrl.'/streams/oldest-by-metadata?'.http_build_query($params);

            $response = Http::timeout(5)->acceptJson()
                ->withHeaders($service->apiToken ? [
                    'X-API-Token' => $service->apiToken,
                ] : [])
                ->delete($endpoint);

            if ($response->successful()) {
                $data = $response->json();

                // Invalidate cache since we stopped a stream
                if ($data['deleted_count'] > 0) {
                    self::invalidateMetadataCache('playlist_uuid', $playlistUuid);
                }

                Log::info('Successfully stopped oldest stream for playlist', [
                    'playlist_uuid' => $playlistUuid,
                    'exclude_channel_id' => $excludeChannelId,
                    'deleted_stream' => $data['deleted_stream'] ?? null,
                    'stream_age_seconds' => $data['stream_age_seconds'] ?? null,
                ]);

                return [
                    'success' => true,
                    'message' => $data['message'] ?? 'Oldest stream stopped successfully',
                    'deleted_count' => $data['deleted_count'] ?? 0,
                    'deleted_stream' => $data['deleted_stream'] ?? null,
                    'stream_age_seconds' => $data['stream_age_seconds'] ?? null,
                ];
            }

            Log::warning('Failed to stop oldest stream: HTTP '.$response->status());

            return [
                'success' => false,
                'message' => 'HTTP error: '.$response->status(),
                'deleted_count' => 0,
            ];
        } catch (Exception $e) {
            Log::warning("Failed to stop oldest stream for playlist ({$playlistUuid}): ".$e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'deleted_count' => 0,
            ];
        }
    }

    /**
     * Check if an episode is currently active (being streamed) via m3u-proxy.
     */
    public static function isEpisodeActive(Episode $episode): bool
    {
        $allStreams = (new self)->fetchActiveStreams();
        if (! $allStreams['success']) {
            return false;
        }

        foreach ($allStreams['streams'] as $stream) {
            if (
                isset($stream['metadata']['type'], $stream['metadata']['id']) &&
                $stream['metadata']['type'] === 'episode' &&
                $stream['metadata']['id'] == $episode->id
            ) {
                return $stream['client_count'] > 0;
            }
        }

        return false;
    }

    /**
     * Request or build a channel stream URL from the external m3u-proxy server.
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias  $playlist
     * @param  Channel  $channel
     * @param  Request|null  $request  Optional request for additional parameters (e.g. timeshift)
     * @param  StreamProfile|null  $profile  Optional stream profile to apply
     *
     * @throws Exception when base URL missing or API returns an error
     */
    public function getChannelUrl($playlist, $channel, ?Request $request = null, ?StreamProfile $profile = null, ?string $username = null): string
    {
        if (empty($this->apiBaseUrl)) {
            throw new Exception('M3U Proxy base URL is not configured');
        }

        // Get channel ID
        $id = $channel->id;

        // IMPORTANT: Check for existing pooled stream BEFORE capacity check
        // If a pooled stream exists, we can reuse it without consuming additional capacity
        // NOTE: We need to select the provider profile FIRST to check for pooled streams with the same provider
        $existingStreamId = null;
        $selectedProfile = null;

        if ($profile) {
            // Select provider profile if profiles are enabled
            if ($playlist instanceof Playlist && $playlist->profiles_enabled) {
                $selectedProfile = ProfileService::selectProfile($playlist);

                if (! $selectedProfile) {
                    Log::warning('No profiles with capacity available (pooled stream check)', [
                        'playlist_id' => $playlist->id,
                        'channel_id' => $id,
                    ]);
                    abort(503, 'All provider profiles have reached their maximum stream limit. Please try again later.');
                }
            }

            $existingStreamId = $this->findExistingPooledStream($id, $playlist->uuid, $profile->id, $selectedProfile?->id);

            if ($existingStreamId) {
                Log::info('Reusing existing pooled transcoded stream (bypassing capacity check)', [
                    'stream_id' => $existingStreamId,
                    'channel_id' => $id,
                    'playlist_uuid' => $playlist->uuid,
                    'profile_id' => $profile->id,
                    'provider_profile_id' => $selectedProfile?->id,
                ]);

                return $this->buildTranscodeStreamUrl($existingStreamId, $profile->format ?? 'ts', $username);
            }
        }

        // Check if primary playlist has stream limits and if it's at capacity
        // Only check capacity if we're about to create a NEW stream (no existing pooled stream found)
        $primaryUrl = null;
        if ($playlist->available_streams !== 0) {
            $activeStreams = self::getActiveStreamsCountByMetadata('playlist_uuid', $playlist->uuid);

            // Keep track of original playlist in case we need to check failovers
            $originalUuid = $playlist->uuid;

            if ($activeStreams >= $playlist->available_streams) {
                // Check if "stop oldest on limit" is enabled in settings
                if ($this->stopOldestOnLimit) {
                    // Stop the oldest stream to make room for the new one (latest wins)
                    $stopResult = self::stopOldestPlaylistStream($playlist->uuid, $id);

                    if ($stopResult['deleted_count'] > 0) {
                        Log::info('Stopped oldest stream to free capacity for new channel request', [
                            'channel_id' => $id,
                            'playlist_uuid' => $playlist->uuid,
                            'stopped_stream' => $stopResult['deleted_stream'] ?? null,
                            'stream_age_seconds' => $stopResult['stream_age_seconds'] ?? null,
                        ]);

                        // Short delay to allow proxy to clean up
                        usleep(100000); // 100ms
                        $activeStreams = self::getActiveStreamsCountByMetadata('playlist_uuid', $playlist->uuid);
                    }
                }

                // If still at capacity (either setting disabled or stop failed), check failovers
                if ($activeStreams >= $playlist->available_streams) {
                    // Primary playlist is at capacity, check failovers
                    $failoverChannels = $channel->failoverChannels()
                        ->select([
                            'channels.id',
                            'channels.url',
                            'channels.url_custom',
                            'channels.playlist_id',
                            'channels.custom_playlist_id',
                        ])->get();

                    foreach ($failoverChannels as $failoverChannel) {
                        $failoverPlaylist = $failoverChannel->getEffectivePlaylist();

                        // Check if failover playlist has limits and capacity
                        if ($failoverPlaylist->available_streams === 0) {
                            // No limits on this failover playlist, use it
                            $playlist = $failoverPlaylist;
                            $primaryUrl = PlaylistUrlService::getChannelUrl($failoverChannel, $playlist);
                            break;
                        } else {
                            // Check if failover playlist has capacity
                            $failoverActiveStreams = self::getActiveStreamsCountByMetadata('playlist_uuid', $failoverPlaylist->uuid);

                            if ($failoverActiveStreams < $failoverPlaylist->available_streams) {
                                // Found available failover playlist
                                $playlist = $failoverPlaylist;
                                $primaryUrl = PlaylistUrlService::getChannelUrl($failoverChannel, $playlist);
                                break;
                            }
                        }
                    }

                    // If we still have the original playlist, all are at capacity
                    if ($playlist->uuid === $originalUuid) {
                        Log::info('Channel stream request denied - all playlists at capacity', [
                            'channel_id' => $id,
                            'primary_playlist' => $playlist->uuid,
                            'primary_limit' => $playlist->available_streams,
                            'primary_active' => $activeStreams,
                        ]);

                        abort(503, 'All playlists have reached their maximum stream limit. Please try again later.');
                    }
                }
            }
        }

        // Provider Profile selection for Xtream playlists with profiles enabled
        // Note: If we already selected a profile during pooled stream check, skip this
        if (! $selectedProfile && $playlist instanceof Playlist && $playlist->profiles_enabled) {
            $selectedProfile = ProfileService::selectProfile($playlist);

            if (! $selectedProfile) {
                Log::warning('No profiles with capacity available', [
                    'playlist_id' => $playlist->id,
                    'channel_id' => $id,
                ]);
                abort(503, 'All provider profiles have reached their maximum stream limit. Please try again later.');
            }

            Log::debug('Selected profile for streaming', [
                'profile_id' => $selectedProfile->id,
                'profile_name' => $selectedProfile->name,
                'playlist_id' => $playlist->id,
                'channel_id' => $id,
            ]);
        }

        // If we didn't already get a primary URL from failover logic, get it now
        if ($primaryUrl === null) {
            // Use the selected profile as context if available
            $urlContext = $selectedProfile ?? $playlist;
            $primaryUrl = PlaylistUrlService::getChannelUrl($channel, $urlContext);
        }
        if (empty($primaryUrl)) {
            throw new Exception('Channel primary URL is empty');
        }

        // Check if timeshift parameters are provided
        if ($request && ($request->filled('timeshift_duration') || $request->filled('timeshift_date') || $request->filled('utc'))) {
            $primaryUrl = PlaylistService::generateTimeshiftUrl($request, $primaryUrl, $playlist);
        }

        $userAgent = $playlist->user_agent;

        // Get any custom headers for the current playlist
        $headers = $playlist->custom_headers ?? [];

        // See if channel has any failovers
        // Return bool if using resolver, else array of failover URLs (legacy mode)
        $failovers = $this->usingResolver()
            ? $channel->failoverChannels()->count() > 0
            : $channel->failoverChannels()
                ->select(['channels.id', 'channels.url', 'channels.url_custom', 'channels.playlist_id', 'channels.custom_playlist_id'])->get()
                ->map(function ($ch) use ($playlist) {
                    return PlaylistUrlService::getChannelUrl($ch, $playlist);
                })
                ->filter()
                ->values()
                ->toArray();

        // Use appropriate endpoint based on whether transcoding profile is provided
        if ($profile) {
            // Note: We already checked for existing pooled stream at the top of this method
            // (before capacity check) to avoid blocking reuse of existing streams.
            // If we reach here, no existing stream was found, so create a new one.

            $metadata = [
                'id' => $id,
                'type' => 'channel',
                'playlist_uuid' => $playlist->uuid,
                'profile_id' => $profile->id,
            ];

            // Add provider profile ID if using profiles
            if ($selectedProfile) {
                $metadata['provider_profile_id'] = $selectedProfile->id;
            }

            $streamId = $this->createTranscodedStream($primaryUrl, $profile, $failovers, $userAgent, $headers, $metadata);

            // Track connection for provider profile
            if ($selectedProfile) {
                ProfileService::incrementConnections($selectedProfile, $streamId);
            }

            // Return transcoded stream URL
            return $this->buildTranscodeStreamUrl($streamId, $profile->format ?? 'ts', $username);
        } else {
            // Use direct streaming endpoint
            $metadata = [
                'id' => $id,
                'type' => 'channel',
                'playlist_uuid' => $playlist->uuid,
                'strict_live_ts' => $playlist->strict_live_ts,
            ];

            // Add provider profile ID if using profiles
            if ($selectedProfile) {
                $metadata['provider_profile_id'] = $selectedProfile->id;
            }

            $streamId = $this->createStream($primaryUrl, $failovers, $userAgent, $headers, $metadata);

            // Track connection for provider profile
            if ($selectedProfile) {
                ProfileService::incrementConnections($selectedProfile, $streamId);
            }

            // Get the format from the URL
            $format = pathinfo($primaryUrl, PATHINFO_EXTENSION);
            $format = $format === 'm3u8' ? 'hls' : $format;

            // Return the direct proxy URL using the stream ID
            return $this->buildProxyUrl($streamId, $format, $username);
        }
    }

    /**
     * Request or build an episode stream URL from the external m3u-proxy server.
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias  $playlist
     * @param  Episode  $episode
     * @param  StreamProfile|null  $profile  Optional stream profile to apply
     * @param  string|null  $username  Optional Xtream username for client tracking
     *
     * @throws Exception when base URL missing or API returns an error
     */
    public function getEpisodeUrl($playlist, $episode, ?StreamProfile $profile = null, ?string $username = null): string
    {
        if (empty($this->apiBaseUrl)) {
            throw new Exception('M3U Proxy base URL is not configured');
        }

        // Get episode ID
        $id = $episode->id;

        // Check if playlist has stream limits and if it's at capacity
        if ($playlist->available_streams !== 0) {
            $activeStreams = self::getCachedActiveStreamsCountByMetadata('playlist_uuid', $playlist->uuid, 1);

            if ($activeStreams >= $playlist->available_streams) {
                // Check if "stop oldest on limit" is enabled in settings
                if ($this->stopOldestOnLimit) {
                    // Stop the oldest stream to make room for the new one (latest wins)
                    $stopResult = self::stopOldestPlaylistStream($playlist->uuid, $id);

                    if ($stopResult['deleted_count'] > 0) {
                        Log::info('Stopped oldest stream to free capacity for new episode request', [
                            'episode_id' => $id,
                            'playlist_uuid' => $playlist->uuid,
                            'stopped_stream' => $stopResult['deleted_stream'] ?? null,
                            'stream_age_seconds' => $stopResult['stream_age_seconds'] ?? null,
                        ]);

                        // Short delay to allow proxy to clean up
                        usleep(100000); // 100ms
                        $activeStreams = self::getCachedActiveStreamsCountByMetadata('playlist_uuid', $playlist->uuid, 0);
                    }
                }

                // If still at capacity (either setting disabled or stop failed), deny the request
                if ($activeStreams >= $playlist->available_streams) {
                    Log::info('Episode stream request denied - playlist at capacity', [
                        'episode_id' => $id,
                        'playlist' => $playlist->uuid,
                        'limit' => $playlist->available_streams,
                        'active' => $activeStreams,
                    ]);

                    abort(503, 'Playlist has reached its maximum stream limit. Please try again later.');
                }
            }
        }

        $url = PlaylistUrlService::getEpisodeUrl($episode, $playlist);
        if (empty($url)) {
            throw new Exception('Episode URL is empty');
        }

        $userAgent = $playlist->user_agent;

        // Get any custom headers for the current playlist
        $headers = $playlist->custom_headers ?? [];

        // Provider Profile selection for Xtream playlists with profiles enabled
        $selectedProfile = null;
        if ($playlist instanceof Playlist && $playlist->profiles_enabled) {
            $selectedProfile = ProfileService::selectProfile($playlist);

            if (! $selectedProfile) {
                Log::warning('No profiles with capacity available for episode', [
                    'playlist_id' => $playlist->id,
                    'episode_id' => $id,
                ]);
                abort(503, 'All provider profiles have reached their maximum stream limit. Please try again later.');
            }

            Log::debug('Selected profile for episode streaming', [
                'profile_id' => $selectedProfile->id,
                'profile_name' => $selectedProfile->name,
                'playlist_id' => $playlist->id,
                'episode_id' => $id,
            ]);

            // Transform URL using selected profile
            $url = $selectedProfile->transformEpisodeUrl($episode);
        }

        // Use appropriate endpoint based on whether transcoding profile is provided
        if ($profile) {
            // First, check if there's already an active pooled transcoded stream for this episode
            // This allows multiple clients to share the same transcoded stream without consuming
            // additional provider connections
            $existingStreamId = $this->findExistingPooledStream($id, $playlist->uuid, $profile->id, $selectedProfile?->id);

            if ($existingStreamId) {
                Log::info('Reusing existing pooled transcoded stream', [
                    'stream_id' => $existingStreamId,
                    'episode_id' => $id,
                    'playlist_uuid' => $playlist->uuid,
                    'profile_id' => $profile->id,
                    'provider_profile_id' => $selectedProfile?->id,
                ]);

                return $this->buildTranscodeStreamUrl($existingStreamId, $profile->format ?? 'ts', $username);
            }

            // No existing pooled stream found, create a new transcoded stream
            $metadata = [
                'id' => $id,
                'type' => 'episode',
                'playlist_uuid' => $playlist->uuid,
                'profile_id' => $profile->id,
            ];

            // Add provider profile ID if using profiles
            if ($selectedProfile) {
                $metadata['provider_profile_id'] = $selectedProfile->id;
            }

            $streamId = $this->createTranscodedStream($url, $profile, false, $userAgent, $headers, $metadata);

            Log::debug('Created transcoded episode stream with provider profile', [
                'stream_id' => $streamId,
                'episode_id' => $id,
                'stream_profile_id' => $profile->id,
                'provider_profile_id' => $selectedProfile?->id,
            ]);

            // Track connection for provider profile
            if ($selectedProfile) {
                ProfileService::incrementConnections($selectedProfile, $streamId);
            }

            // Return transcoded stream URL
            return $this->buildTranscodeStreamUrl($streamId, $profile->format ?? 'ts', $username);
        } else {
            // Use direct streaming endpoint
            $metadata = [
                'id' => $id,
                'type' => 'episode',
                'playlist_uuid' => $playlist->uuid,
                'strict_live_ts' => $playlist->strict_live_ts,
            ];

            // Add provider profile ID if using profiles
            if ($selectedProfile) {
                $metadata['provider_profile_id'] = $selectedProfile->id;
            }

            $streamId = $this->createStream($url, false, $userAgent, $headers, $metadata);

            Log::debug('Created direct episode stream with provider profile', [
                'stream_id' => $streamId,
                'episode_id' => $id,
                'provider_profile_id' => $selectedProfile?->id,
            ]);

            // Track connection for provider profile
            if ($selectedProfile) {
                ProfileService::incrementConnections($selectedProfile, $streamId);
            }

            // Get the format from the URL
            $format = pathinfo($url, PATHINFO_EXTENSION);
            $format = $format === 'm3u8' ? 'hls' : $format;

            // Return the direct proxy URL using the stream ID
            return $this->buildProxyUrl($streamId, $format, $username);
        }
    }

    /**
     * Trigger a failover for a specific stream on the external proxy.
     * Returns true on success.
     */
    public function triggerFailover(string $streamId): bool
    {
        if (empty($this->apiBaseUrl)) {
            Log::warning('M3U Proxy base URL not configured');

            return false;
        }

        try {
            $endpoint = $this->apiBaseUrl.'/streams/'.$streamId.'/failover';
            $response = Http::timeout(10)->acceptJson()
                ->withHeaders($this->apiToken ? [
                    'X-API-Token' => $this->apiToken,
                ] : [])
                ->post($endpoint);

            if ($response->successful()) {
                Log::info("Failover triggered successfully for stream {$streamId}");

                return true;
            }

            Log::warning("Failed to trigger failover for stream {$streamId}: ".$response->body());

            return false;
        } catch (Exception $e) {
            Log::error("Error triggering failover for stream {$streamId}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Delete/stop a stream on the external proxy (used by the Filament UI).
     * Returns true on success.
     */
    public function stopStream(string $streamId): bool
    {
        if (empty($this->apiBaseUrl)) {
            Log::warning('M3U Proxy base URL not configured');

            return false;
        }

        try {
            $endpoint = $this->apiBaseUrl.'/streams/'.$streamId;
            $response = Http::timeout(10)->acceptJson()
                ->withHeaders($this->apiToken ? [
                    'X-API-Token' => $this->apiToken,
                ] : [])
                ->delete($endpoint);

            if ($response->successful()) {
                Log::info("Stream {$streamId} stopped successfully");

                return true;
            }

            Log::warning("Failed to stop stream {$streamId}: ".$response->body());

            return false;
        } catch (Exception $e) {
            Log::error("Error stopping stream {$streamId}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Fetch active streams from external proxy server API.
     * Returns array with 'success', 'streams', and optional 'error' keys.
     */
    public function fetchActiveStreams(): array
    {
        if (empty($this->apiBaseUrl)) {
            return [
                'success' => false,
                'error' => 'M3U Proxy base URL is not configured',
                'streams' => [],
            ];
        }

        try {
            $endpoint = $this->apiBaseUrl.'/streams';
            $response = Http::timeout(5)->acceptJson()
                ->withHeaders($this->apiToken ? [
                    'X-API-Token' => $this->apiToken,
                ] : [])
                ->get($endpoint);
            if ($response->successful()) {
                $data = $response->json() ?: [];

                // Need to filter out streams not owned by this user
                $playlistUuids = auth()->user()->getAllPlaylistUuids();
                $streams = array_filter($data['streams'] ?? [], function ($stream) use ($playlistUuids) {
                    return isset($stream['metadata']['playlist_uuid']) && in_array($stream['metadata']['playlist_uuid'], $playlistUuids);
                });

                return [
                    'success' => true,
                    'streams' => $streams ?? [],
                    'total' => count($streams) ?? 0,
                ];
            }

            Log::warning('Failed to fetch active streams from m3u-proxy: HTTP '.$response->status());

            return [
                'success' => false,
                'error' => 'M3U Proxy returned status '.$response->status(),
                'streams' => [],
            ];
        } catch (Exception $e) {
            Log::warning('Failed to fetch active streams from m3u-proxy: '.$e->getMessage());

            return [
                'success' => false,
                'error' => 'Unable to connect to m3u-proxy: '.$e->getMessage(),
                'streams' => [],
            ];
        }
    }

    /**
     * Fetch active clients from external proxy server API.
     * Returns array with 'success', 'clients', and optional 'error' keys.
     */
    public function fetchActiveClients(): array
    {
        if (empty($this->apiBaseUrl)) {
            return [
                'success' => false,
                'error' => 'M3U Proxy base URL is not configured',
                'clients' => [],
            ];
        }

        try {
            $endpoint = $this->apiBaseUrl.'/clients';
            $response = Http::timeout(5)->acceptJson()
                ->withHeaders($this->apiToken ? [
                    'X-API-Token' => $this->apiToken,
                ] : [])
                ->get($endpoint);
            if ($response->successful()) {
                $data = $response->json() ?: [];

                return [
                    'success' => true,
                    'clients' => $data['clients'] ?? [],
                    'total_clients' => $data['total_clients'] ?? 0,
                ];
            }

            Log::warning('Failed to fetch active clients from m3u-proxy: HTTP '.$response->status());

            return [
                'success' => false,
                'error' => 'M3U Proxy returned status '.$response->status(),
                'clients' => [],
            ];
        } catch (Exception $e) {
            Log::warning('Failed to fetch active clients from m3u-proxy: '.$e->getMessage());

            return [
                'success' => false,
                'error' => 'Unable to connect to m3u-proxy: '.$e->getMessage(),
                'clients' => [],
            ];
        }
    }

    /**
     * Create or update a stream on the m3u-proxy API.
     * Returns the stream ID.
     *
     * @param  string  $url  Primary stream URL
     * @param  bool|array  $failovers  Whether to enable failover URLs, or array of failover URLs
     * @param  string|null  $userAgent  Custom user agent
     * @param  array|null  $headers  Custom headers to send with the stream request
     * @param  array|null  $metadata  Additional metadata (e.g. ['id' => 123, 'type' => 'channel'])
     * @return string Stream ID
     *
     * @throws Exception when API request fails
     */
    protected function createStream(
        string $url,
        bool|array $failovers = false,
        ?string $userAgent = null,
        ?array $headers = [],
        ?array $metadata = [],
    ): string {
        try {
            $endpoint = $this->apiBaseUrl.'/streams';

            // Build the payload for direct streaming
            $payload = [
                'url' => $url,
                'metadata' => $metadata,
            ];

            // Handle strict_live_ts flag if set in metadata
            if ($metadata['strict_live_ts'] ?? false) {
                $payload['strict_live_ts'] = true;
                unset($metadata['strict_live_ts']);
            }

            // If using failovers, provide the callback URL for smart failover handling, or list of URLs
            if ($failovers) {
                if (is_array($failovers)) {
                    $payload['failover_urls'] = $failovers;
                } else {
                    // Include the failover resolver URL for smart failover handling
                    $payload['failover_resolver_url'] = $this->getFailoverResolverUrl();
                }
            }

            // Add user agent if provided
            if (! empty($userAgent)) {
                $payload['user_agent'] = $userAgent;
            }

            // Add custom headers if provided
            if (! empty($headers)) {
                // Need to return as key => value pairs, where `header` is key and `value` is value
                foreach ($headers as $h) {
                    if (is_array($h) && isset($h['header'])) {
                        $key = $h['header'];
                        $val = $h['value'] ?? null;
                        $normalized[$key] = $val;
                    }
                }
                if (! empty($normalized)) {
                    $payload['headers'] = $normalized;
                }
            }

            $response = Http::timeout(10)
                ->acceptJson()
                ->withHeaders($this->apiToken ? [
                    'X-API-Token' => $this->apiToken,
                ] : [])->post($endpoint, $payload);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['stream_id'])) {
                    Log::info('m3u-proxy stream created/updated successfully', [
                        'stream_id' => $data['stream_id'],
                        'url' => $url,
                    ]);

                    return $data['stream_id'];
                }

                throw new Exception('Stream ID not found in API response');
            }

            throw new Exception('Failed to create stream: '.$response->body());
        } catch (Exception $e) {
            Log::error('Error creating/updating stream on m3u-proxy', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);
            throw $e;
        }
    }

    /**
     * Create a transcoded stream via the m3u-proxy transcoding API
     *
     * @param  string  $url  The stream URL to transcode
     * @param  StreamProfile  $profile  The transcoding profile to use
     * @param  bool|array  $failovers  Whether to enable failover URLs, or array of failover URLs
     * @param  string|null  $userAgent  Optional user agent
     * @param  array|null  $headers  Custom headers to send with the stream request
     * @param  array|null  $metadata  Stream metadata
     * @return string The transcoded stream ID
     *
     * @throws Exception when API returns an error
     */
    protected function createTranscodedStream(
        string $url,
        StreamProfile $profile,
        bool|array $failovers = false,
        ?string $userAgent = null,
        ?array $headers = [],
        ?array $metadata = [],
    ): string {
        try {
            $endpoint = $this->apiBaseUrl.'/transcode';

            // Build the payload for transcoding
            $payload = [
                'url' => $url,
                'profile' => $profile->getProfileIdentifier(),  // Custom args template or predefined profile name
                'metadata' => $metadata,
            ];

            // If using failovers, provide the callback URL for smart failover handling, or list of URLs
            if ($failovers) {
                if (is_array($failovers)) {
                    $payload['failover_urls'] = $failovers;
                } else {
                    // Include the failover resolver URL for smart failover handling
                    $payload['failover_resolver_url'] = $this->getFailoverResolverUrl();
                }
            }

            // Add user agent if provided
            if (! empty($userAgent)) {
                $payload['user_agent'] = $userAgent;
            }

            // Add custom headers if provided
            if (! empty($headers)) {
                // Need to return as key => value pairs, where `header` is key and `value` is value
                foreach ($headers as $h) {
                    if (is_array($h) && isset($h['header'])) {
                        $key = $h['header'];
                        $val = $h['value'] ?? null;
                        $normalized[$key] = $val;
                    }
                }
                if (! empty($normalized)) {
                    $payload['headers'] = $normalized;
                }
            }

            // Always add profile variables for FFmpeg template substitution
            // Even custom FFmpeg templates may contain placeholders that need substitution
            $profileVars = $profile->getTemplateVariables();
            if (! empty($profileVars)) {
                $payload['profile_variables'] = $profileVars;
            }

            $response = Http::timeout(10)->acceptJson()
                ->withHeaders(array_filter([
                    'X-API-Token' => $this->apiToken,
                    'Content-Type' => 'application/json',
                ]))
                ->post($endpoint, $payload);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['stream_id'])) {
                    Log::info('Created transcoded stream on m3u-proxy', [
                        'stream_id' => $data['stream_id'],
                        'format' => $profile->format,
                        'payload' => $payload,
                    ]);

                    return $data['stream_id'];
                }

                throw new Exception('Stream ID not found in transcoding API response');
            }

            throw new Exception('Failed to create transcoded stream: '.$response->body());
        } catch (Exception $e) {
            Log::error('Error creating transcoded stream on m3u-proxy', [
                'error' => $e->getMessage(),
                'profile' => $profile->getProfileIdentifier(),
                'url' => $url,
            ]);
            throw $e;
        }
    }

    /**
     * Build the transcoded stream URL for a given stream ID
     *
     * @param  string  $streamId  The stream ID returned from transcoding API
     * @param  string  $format  The desired format (default 'ts' for MPEG-TS)
     * @param  string|null  $username  Optional Xtream username for client tracking
     * @return string The stream URL
     */
    protected function buildTranscodeStreamUrl(string $streamId, $format = 'ts', ?string $username = null): string
    {
        // Transcode route is the same logic as direct now
        return $this->buildProxyUrl($streamId, $format, $username);
    }

    /**
     * Build the proxy URL for a given stream ID.
     * Uses the configured proxy format (HLS or direct stream).
     *
     * @return string The full proxy URL
     */
    protected function buildProxyUrl(string $streamId, $format = 'hls', ?string $username = null): string
    {
        $baseUrl = $this->getPublicUrl();
        if ($format === 'hls' || $format === 'm3u8') {
            // HLS format: /hls/{stream_id}/playlist.m3u8
            $url = $baseUrl.'/hls/'.$streamId.'/playlist.m3u8';
        } else {
            // Direct stream format: /stream/{stream_id}
            $url = $baseUrl.'/stream/'.$streamId;
        }

        // Append trace parameters if provided
        return $this->appendProxyTraceParams($url, $username);
    }

    /**
     * Append traceability parameters to a proxy URL.
     * Adds username as query parameter for client tracking.
     *
     * @param  string  $url  The base proxy URL
     * @param  string|null  $username  Optional Xtream username for client tracking
     * @return string URL with appended trace parameters
     */
    protected function appendProxyTraceParams(string $url, ?string $username = null): string
    {
        if (! $username) {
            return $url;
        }

        $separator = parse_url($url, PHP_URL_QUERY) ? '&' : '?';

        return $url.$separator.http_build_query(['username' => $username]);
    }

    /**
     * Get the base URL for the m3u-proxy API.
     */
    public function getApiBaseUrl(): string
    {
        return $this->apiBaseUrl;
    }

    public function getApiToken(): ?string
    {
        return $this->apiToken;
    }

    /**
     * Resolve the public-facing URL for the m3u-proxy service.
     *
     * Resolution order:
     * 1. If auto-resolve enabled and we have an HTTP request, compute from request host + root path
     * 2. Explicit config/provided 'm3u_proxy_public_url'
     * 3. Fall back to the APP_URL + /m3u-proxy (built-in reverse proxy route)
     *
     * This method is intentionally run-time (not only at construction) so URLs can be
     * resolved per-request when desired.
     */
    public function getPublicUrl(): string
    {
        // 1) request-time resolution (if explicitly enabled and we are in a HTTP context)
        // Allow the admin setting (GeneralSettings) to control request-time resolution
        if ($this->autoResolve && ! app()->runningInConsole()) {
            try {
                $req = request();
                if ($req) {
                    $host = $req->getSchemeAndHttpHost();

                    // Append root path + /m3u-proxy, which is an NGINX route that
                    // proxies to the m3u-proxy service.
                    return rtrim($host, '/').'/m3u-proxy';
                }
            } catch (\Exception $e) {
                // ignore and fall back
            }
        }

        // 2) explicit config
        if (! empty($this->apiPublicUrl)) {
            return $this->apiPublicUrl;
        }

        // 3) Smart fallback: Use APP_URL + /m3u-proxy if available (works with reverse proxy)
        // This allows the proxy to work without requiring explicit PUBLIC_URL configuration.
        // Works automatically in Docker containers with NGINX reverse proxy.
        return ProxyFacade::getBaseUrl().'/m3u-proxy';
    }

    /**
     * Find an existing pooled transcoded stream for the given channel.
     * This allows multiple clients to connect to the same transcoded stream without
     * consuming additional provider connections.
     *
     * @param  int  $channelId  Channel ID
     * @param  string  $playlistUuid  Playlist UUID
     * @return string|null Stream ID if found, null otherwise
     */
    /**
     * Find an existing pooled transcoded stream for the given channel.
     * This allows multiple clients to connect to the same transcoded stream without
     * consuming additional provider connections.
     *
     * @param  int  $channelId  Channel ID
     * @param  string  $playlistUuid  Playlist UUID
     * @param  int|null  $profileId  StreamProfile ID (transcoding profile)
     * @param  int|null  $providerProfileId  PlaylistProfile ID (provider profile)
     * @return string|null Stream ID if found, null otherwise
     */
    protected function findExistingPooledStream(int $channelId, string $playlistUuid, ?int $profileId = null, ?int $providerProfileId = null): ?string
    {
        try {
            // Query m3u-proxy for streams by metadata
            $endpoint = $this->apiBaseUrl.'/streams/by-metadata';
            $response = Http::timeout(5)->acceptJson()
                ->withHeaders(array_filter([
                    'X-API-Token' => $this->apiToken,
                ]))
                ->get($endpoint, [
                    'field' => 'id',
                    'value' => (string) $channelId,
                    'active_only' => true,  // Only return active streams
                ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $matchingStreams = $data['matching_streams'] ?? [];

            // Find a stream for this channel+playlist+profile that's transcoding
            foreach ($matchingStreams as $stream) {
                $metadata = $stream['metadata'] ?? [];

                // Check if this stream matches our criteria:
                // 1. Same channel ID
                // 2. Same playlist UUID
                // 3. Is a transcoded stream (has transcoding metadata)
                // 4. Same StreamProfile ID (transcoding profile, if specified)
                // 5. Same PlaylistProfile ID (provider profile, if specified)
                if (
                    ($metadata['id'] ?? null) == $channelId &&
                    ($metadata['playlist_uuid'] ?? null) === $playlistUuid &&
                    ($metadata['transcoding'] ?? null) === 'true' &&
                    ($profileId === null || ($metadata['profile_id'] ?? null) == $profileId) &&
                    ($providerProfileId === null || ($metadata['provider_profile_id'] ?? null) == $providerProfileId)
                ) {
                    Log::info('Found existing pooled transcoded stream', [
                        'stream_id' => $stream['stream_id'],
                        'channel_id' => $channelId,
                        'playlist_uuid' => $playlistUuid,
                        'profile_id' => $profileId,
                        'provider_profile_id' => $providerProfileId,
                        'client_count' => $stream['client_count'],
                    ]);

                    return $stream['stream_id'];
                }
            }

            return null;
        } catch (Exception $e) {
            Log::warning('Error finding existing pooled stream: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Get m3u-proxy server information including configuration and capabilities
     *
     * @return array Array with 'success', 'info', and optional 'error' keys
     */
    public function getProxyInfo(): array
    {
        if (empty($this->apiBaseUrl)) {
            return [
                'success' => false,
                'error' => 'M3U Proxy base URL is not configured',
                'info' => [],
            ];
        }

        try {
            $endpoint = $this->apiBaseUrl.'/info';
            $response = Http::timeout(5)->acceptJson()
                ->withHeaders($this->apiToken ? [
                    'X-API-Token' => $this->apiToken,
                ] : [])
                ->get($endpoint);

            if ($response->successful()) {
                $data = $response->json() ?: [];

                return [
                    'success' => true,
                    'info' => $data,
                ];
            }

            Log::warning('Failed to fetch proxy info from m3u-proxy: HTTP '.$response->status());

            return [
                'success' => false,
                'error' => 'M3U Proxy returned status '.$response->status(),
                'info' => [],
            ];
        } catch (Exception $e) {
            Log::warning('Failed to fetch proxy info from m3u-proxy: '.$e->getMessage());

            return [
                'success' => false,
                'error' => 'Unable to connect to m3u-proxy: '.$e->getMessage(),
                'info' => [],
            ];
        }
    }

    /**
     * Validate and resolve failover URLs for smart failover handling.
     * This is called by m3u-proxy during failover to get a viable failover URL.
     *
     * Uses the same capacity checking logic as getChannelUrl to determine which
     * failover channels have available capacity.
     *
     * @param  int  $channelId  The original channel ID from stream metadata
     * @param  string  $playlistUuid  The original playlist UUID from stream metadata
     * @param  string  $currentUrl  The current URL being used
     * @param  int  $index  The failover index being requested
     * @return array Array with 'next_url' (single best option) and optional 'error' keys
     *
     * The response contains:
     * - next_url: The best failover URL to use (or null if none viable)
     * - error: Optional error message if validation fails
     *
     * This is a lightweight, low-overhead check that uses the same logic as getChannelUrl
     * to prevent wasted connection attempts to playlists that are already at capacity.
     */
    public function resolveFailoverUrl(int $channelId, string $playlistUuid, string $currentUrl, int $index): array
    {
        try {
            // Get the original channel to access its failover relationships
            $channel = Channel::findOrFail($channelId);
            $nextUrl = null;
            // Resolve the original stream context by UUID (Playlist / MergedPlaylist / CustomPlaylist / PlaylistAlias)
            $contextPlaylist = ! empty($playlistUuid) ? PlaylistFacade::resolvePlaylistByUuid($playlistUuid) : null;

            // Get all failover channels with their relationships
            $failoverChannels = $channel->failoverChannels()
                ->select([
                    'channels.id',
                    'channels.url',
                    'channels.url_custom',
                    'channels.playlist_id',
                    'channels.custom_playlist_id',
                ])->get();

            // Find the first valid failover URL that has capacity
            foreach ($failoverChannels as $idx => $failoverChannel) {
                $failoverPlaylist = $failoverChannel->getEffectivePlaylist();
                if (! $failoverPlaylist) {
                    continue;
                }

                // Before proceeding, see if the failover index is less than the desired index
                if ($idx < $index) {
                    // If the index is higher than the current loop, chances are it has already been attempted, continue to the next...
                    Log::debug('Channel already attempted, skipping', [
                        'channel' => $failoverPlaylist->title_custom ?? $failoverPlaylist->title,
                        'index' => $idx,
                        'requested_index' => $index,
                    ]);

                    continue;
                }

                // Get the url
                $url = PlaylistUrlService::getChannelUrl($failoverChannel, $contextPlaylist ?? $failoverPlaylist);

                // Check if the url is the current URL (skip it)
                if ($url === $currentUrl) {
                    Log::debug('Failover URL matches current URL, skipping', [
                        'url' => substr($url, 0, 100),
                        'playlist_uuid' => $failoverPlaylist->uuid,
                    ]);

                    continue;
                }

                // Check if playlist has capacity limits
                if ($failoverPlaylist->available_streams === 0) {
                    // No limits on this playlist, it's viable
                    $nextUrl = $url;

                    // Break on first url, no need to continue checking Playlist limits
                    break;
                }

                // Check if playlist is at capacity
                $activeStreams = self::getActiveStreamsCountByMetadata('playlist_uuid', $failoverPlaylist->uuid);
                if ($activeStreams < $failoverPlaylist->available_streams) {
                    // Still has capacity, it's viable!
                    $nextUrl = $url;

                    break;
                } else {
                    // At capacity, skip this URL
                    Log::debug('Failover URL playlist at capacity, skipping', [
                        'url' => substr($url, 0, 100),
                        'playlist_uuid' => $failoverPlaylist->uuid,
                        'active' => $activeStreams,
                        'limit' => $failoverPlaylist->available_streams,
                    ]);
                }
            }

            // Return the first viable URL as the best option, plus the full list
            return [
                'next_url' => $nextUrl,
            ];
        } catch (Exception $e) {
            Log::warning('Error resolving failover url: '.$e->getMessage(), [
                'channel_id' => $channelId,
                'playlist_uuid' => $playlistUuid,
            ]);

            // Return all URLs as fallback if something goes wrong
            return [
                'next_url' => $currentUrl,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get the failover resolver URL for smart failover handling.
     * This URL is passed to m3u-proxy so it can call back to validate failover channels
     * before attempting to stream from them.
     *
     * The m3u-proxy will POST to this endpoint with failover metadata to check if
     * a failover is viable (i.e., the target playlist isn't at capacity).
     *
     * @return string|null The failover resolver endpoint URL, or null if not configured
     */
    public function getFailoverResolverUrl(): ?string
    {
        // Build the failover resolver path
        if (! empty($this->failoverResolverUrl)) {
            // Use the configured failover resolver URL
            return "$this->failoverResolverUrl/api/m3u-proxy/failover-resolver";
        }

        // If here, return null
        return null;
    }
}
