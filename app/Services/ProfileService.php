<?php

namespace App\Services;

use App\Models\Playlist;
use App\Models\PlaylistProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ProfileService
{
    /**
     * Redis key prefix for connection tracking.
     */
    protected const REDIS_PREFIX = 'playlist_profile:';

    /**
     * Cache TTL for connection counts (seconds).
     */
    protected const CONNECTION_CACHE_TTL = 60;

    /**
     * TTL for stream tracking keys (seconds).
     * Set to 24 hours - stale keys will auto-expire.
     */
    protected const STREAM_TRACKING_TTL = 86400;

    /**
     * TTL for pending channel stream reservations (seconds).
     * Short TTL ensures stale pending keys self-expire if stream creation fails.
     */
    protected const CHANNEL_STREAM_PENDING_TTL = 30;

    /**
     * TTL for client-to-profile affinity keys (seconds).
     * Matches STREAM_TRACKING_TTL (24 hours), refreshed on each use.
     */
    protected const CLIENT_AFFINITY_TTL = 86400;

    /**
     * Max wait time for the profile selection lock (seconds).
     */
    protected const PROFILE_LOCK_TIMEOUT = 2;

    /**
     * Build a client identifier from IP and optional username.
     *
     * Returns "{ip}:{username}" when both are available, "{ip}" when
     * username is null, or null when IP is null.
     */
    public static function buildClientIdentifier(?string $clientIp, ?string $username): ?string
    {
        if ($clientIp === null) {
            return null;
        }

        return $username !== null ? "{$clientIp}:{$username}" : $clientIp;
    }

    /**
     * Get the Redis key for client-to-profile affinity.
     */
    public static function getClientAffinityKey(string $clientIdentifier, int $playlistId): string
    {
        return "client_affinity:{$clientIdentifier}:{$playlistId}";
    }

    /**
     * Look up the affinity profile ID for a client+playlist pair.
     */
    public static function getClientAffinity(string $clientIdentifier, int $playlistId): ?int
    {
        $key = static::getClientAffinityKey($clientIdentifier, $playlistId);

        try {
            $value = Redis::get($key);

            if ($value !== null) {
                // Refresh TTL on read so active clients stay sticky
                Redis::expire($key, static::CLIENT_AFFINITY_TTL);

                return (int) $value;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to read client affinity', [
                'key' => $key,
                'exception' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Store client-to-profile affinity in Redis.
     */
    public static function storeClientAffinity(string $clientIdentifier, int $playlistId, int $profileId): void
    {
        $key = static::getClientAffinityKey($clientIdentifier, $playlistId);

        try {
            Redis::setex($key, static::CLIENT_AFFINITY_TTL, $profileId);
        } catch (\Exception $e) {
            Log::warning('Failed to store client affinity', [
                'key' => $key,
                'profile_id' => $profileId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Select the best available profile for streaming.
     *
     * Iterates through enabled profiles in priority order and returns
     * the first one with available capacity. When a client identifier
     * is provided, prefers the profile the client was previously assigned to.
     */
    public static function selectProfile(Playlist $playlist, ?int $excludeProfileId = null, bool $forceSelect = false, ?string $clientIdentifier = null): ?PlaylistProfile
    {
        if (! $playlist->profiles_enabled) {
            return null;
        }

        $query = $playlist->enabledProfiles();

        if ($excludeProfileId) {
            $query->where('id', '!=', $excludeProfileId);
        }

        $profiles = $query->get();

        Log::debug('Selecting profile for streaming', [
            'playlist_id' => $playlist->id,
            'total_profiles' => $profiles->count(),
            'exclude_profile_id' => $excludeProfileId,
            'force_select' => $forceSelect,
            'client_identifier' => $clientIdentifier,
        ]);

        // Check client affinity — prefer the profile the client used before
        if ($clientIdentifier !== null) {
            $affinityProfileId = static::getClientAffinity($clientIdentifier, $playlist->id);

            if ($affinityProfileId !== null) {
                $affinityProfile = $profiles->firstWhere('id', $affinityProfileId);

                if ($affinityProfile && ($forceSelect || static::hasCapacity($affinityProfile))) {
                    Log::debug('Returning affinity profile for client', [
                        'client_identifier' => $clientIdentifier,
                        'profile_id' => $affinityProfile->id,
                        'profile_name' => $affinityProfile->name,
                    ]);

                    return $affinityProfile;
                }
            }
        }

        foreach ($profiles as $profile) {
            $activeConnections = static::getConnectionCount($profile);
            $maxConnections = $profile->effective_max_streams;
            $hasCapacity = static::hasCapacity($profile);

            Log::debug('Checking profile capacity', [
                'profile_id' => $profile->id,
                'profile_name' => $profile->name,
                'priority' => $profile->priority,
                'active_connections' => $activeConnections,
                'max_connections' => $maxConnections,
                'has_capacity' => $hasCapacity,
                'enabled' => $profile->enabled,
            ]);

            if ($hasCapacity) {
                Log::info('Selected profile for streaming', [
                    'profile_id' => $profile->id,
                    'profile_name' => $profile->name,
                    'active_connections' => $activeConnections,
                    'max_connections' => $maxConnections,
                ]);

                return $profile;
            }
        }

        // When force-select is enabled (bypass provider limits), pick the least-loaded
        // profile even though all are at capacity. This allows streams to start when
        // available_streams hasn't been reached but provider limits have.
        if ($forceSelect && $profiles->isNotEmpty()) {
            $best = $profiles->sortBy(fn ($p) => static::getConnectionCount($p))->first();

            Log::info('Force-selected profile (bypass provider limits)', [
                'profile_id' => $best->id,
                'profile_name' => $best->name,
                'active_connections' => static::getConnectionCount($best),
                'max_connections' => $best->effective_max_streams,
                'playlist_id' => $playlist->id,
            ]);

            return $best;
        }

        Log::warning('No profiles with capacity available for playlist', [
            'playlist_id' => $playlist->id,
            'total_profiles' => $profiles->count(),
        ]);

        return null;
    }

    /**
     * Atomically select a profile and reserve a connection slot.
     *
     * Uses a per-playlist lock to prevent two concurrent requests from both
     * selecting the same profile when only one slot remains (TOCTOU race).
     *
     * The connection count is immediately incremented using a reservation ID.
     * After the real stream is created, call `finalizeReservation()` to update
     * the stream mapping from reservation ID to real stream ID.
     *
     * When `$channelId` and `$channelPlaylistUuid` are provided, the method also
     * performs channel reuse detection inside the lock: if the channel already has
     * an active stream or pending reservation, no slot is allocated and [null, null]
     * is returned. The caller should then check `isChannelStreamActive()` to
     * distinguish this from genuine no-capacity and retry `findExistingPooledStream`.
     *
     * Returns [PlaylistProfile, string $reservationId] on success, or [null, null].
     *
     * @return array{0: PlaylistProfile|null, 1: string|null}
     */
    public static function selectAndReserveProfile(
        Playlist $playlist,
        ?int $excludeProfileId = null,
        ?int $channelId = null,
        ?string $channelPlaylistUuid = null,
        bool $forceSelect = false,
        ?string $clientIdentifier = null,
    ): array {
        if (! $playlist->profiles_enabled) {
            return [null, null];
        }

        $lockKey = "profile_select_lock:playlist:{$playlist->id}";
        $reservationId = 'reservation:'.bin2hex(random_bytes(8));

        // Acquire a short-lived atomic lock scoped to this playlist.
        // block() waits up to PROFILE_LOCK_TIMEOUT seconds for the lock.
        $lock = Cache::lock($lockKey, static::PROFILE_LOCK_TIMEOUT);

        try {
            // Wait for the lock with a timeout
            if (! $lock->block(static::PROFILE_LOCK_TIMEOUT)) {
                Log::warning('Failed to acquire profile selection lock', [
                    'playlist_id' => $playlist->id,
                ]);

                return [null, null];
            }

            // Inside the lock: detect if this channel is already being served.
            // Prevents two simultaneous requests for the same channel from both
            // allocating a slot during the window before the first stream is visible
            // in m3u-proxy (i.e. before findExistingPooledStream would find it).
            if ($channelId !== null && $channelPlaylistUuid !== null) {
                $channelStreamKey = static::getChannelStreamKey($channelId, $channelPlaylistUuid);

                if (Redis::exists($channelStreamKey)) {
                    Log::debug('Channel reuse detected inside lock — skipping profile allocation', [
                        'channel_id' => $channelId,
                        'playlist_uuid' => $channelPlaylistUuid,
                        'playlist_id' => $playlist->id,
                    ]);

                    // Return [null, null]. Caller should check isChannelStreamActive()
                    // to distinguish this from genuine no-capacity.
                    return [null, null];
                }
            }

            // Inside the lock: select + increment atomically
            $profile = static::selectProfile($playlist, $excludeProfileId, $forceSelect, $clientIdentifier);

            if ($profile) {
                // Reserve the slot immediately so the next concurrent request
                // sees the updated count and picks a different profile (or waits).
                static::incrementConnections($profile, $reservationId);

                // Mark this channel as having a pending reservation (short TTL so
                // it self-expires if stream creation fails before finalizeReservation).
                if ($channelId !== null && $channelPlaylistUuid !== null) {
                    $channelStreamKey = static::getChannelStreamKey($channelId, $channelPlaylistUuid);
                    $reverseKey = static::getStreamChannelKey($reservationId);

                    Redis::pipeline(function ($pipe) use ($channelStreamKey, $reverseKey, $reservationId, $channelId, $channelPlaylistUuid) {
                        $pipe->setex($channelStreamKey, static::CHANNEL_STREAM_PENDING_TTL, $reservationId);
                        $pipe->setex($reverseKey, static::CHANNEL_STREAM_PENDING_TTL, "{$channelId}:{$channelPlaylistUuid}");
                    });
                }

                // Store client-to-profile affinity so the same client
                // is preferentially assigned the same profile next time.
                if ($clientIdentifier !== null) {
                    static::storeClientAffinity($clientIdentifier, $playlist->id, $profile->id);
                }

                return [$profile, $reservationId];
            }

            return [null, null];
        } catch (\Exception $e) {
            Log::error('Error in selectAndReserveProfile', [
                'playlist_id' => $playlist->id,
                'exception' => $e->getMessage(),
            ]);

            return [null, null];
        } finally {
            $lock->release();
        }
    }

    /**
     * Finalize a reservation by replacing the temporary reservation ID
     * with the real stream ID from the proxy.
     *
     * Called after the proxy has created the stream and returned its ID.
     * When `$channelId` and `$channelPlaylistUuid` are provided, the channel→stream
     * mapping is upgraded from the pending reservation ID to the real stream ID so
     * subsequent requests can find the active stream via `getChannelActiveStreamId()`.
     */
    public static function finalizeReservation(
        PlaylistProfile $profile,
        string $reservationId,
        string $realStreamId,
        ?int $channelId = null,
        ?string $channelPlaylistUuid = null,
    ): void {
        $streamsKey = static::getProfileStreamsKey($profile);
        $oldStreamKey = static::getStreamProfileKey($reservationId);
        $newStreamKey = static::getStreamProfileKey($realStreamId);

        try {
            Redis::pipeline(function ($pipe) use ($streamsKey, $oldStreamKey, $newStreamKey, $reservationId, $realStreamId, $profile) {
                // Remove the reservation entry
                $pipe->srem($streamsKey, $reservationId);
                $pipe->del($oldStreamKey);

                // Add the real stream entry
                $pipe->sadd($streamsKey, $realStreamId);
                $pipe->expire($streamsKey, static::STREAM_TRACKING_TTL);
                $pipe->set($newStreamKey, $profile->id);
                $pipe->expire($newStreamKey, static::STREAM_TRACKING_TTL);
            });

            // Upgrade the channel→stream mapping from pending reservation ID to real stream ID.
            if ($channelId !== null && $channelPlaylistUuid !== null) {
                $channelStreamKey = static::getChannelStreamKey($channelId, $channelPlaylistUuid);
                $oldReverseKey = static::getStreamChannelKey($reservationId);
                $newReverseKey = static::getStreamChannelKey($realStreamId);
                $channelValue = "{$channelId}:{$channelPlaylistUuid}";

                Redis::pipeline(function ($pipe) use ($channelStreamKey, $oldReverseKey, $newReverseKey, $realStreamId, $channelValue) {
                    // Upgrade channel key to real stream ID (long TTL)
                    $pipe->set($channelStreamKey, $realStreamId);
                    $pipe->expire($channelStreamKey, static::STREAM_TRACKING_TTL);

                    // Replace reservation reverse-mapping with real stream reverse-mapping
                    $pipe->del($oldReverseKey);
                    $pipe->set($newReverseKey, $channelValue);
                    $pipe->expire($newReverseKey, static::STREAM_TRACKING_TTL);
                });
            }

            Log::debug('Finalized profile reservation', [
                'profile_id' => $profile->id,
                'reservation_id' => $reservationId,
                'real_stream_id' => $realStreamId,
                'channel_id' => $channelId,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to finalize reservation for profile {$profile->id}", [
                'reservation_id' => $reservationId,
                'real_stream_id' => $realStreamId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancel a reservation (e.g. when stream creation fails).
     *
     * Decrements the count and cleans up the reservation entries.
     */
    public static function cancelReservation(PlaylistProfile $profile, string $reservationId): void
    {
        static::decrementConnections($profile, $reservationId);

        Log::debug('Cancelled profile reservation', [
            'profile_id' => $profile->id,
            'reservation_id' => $reservationId,
        ]);
    }

    /**
     * Check if a profile has available capacity.
     */
    public static function hasCapacity(PlaylistProfile $profile): bool
    {
        if (! $profile->enabled) {
            return false;
        }

        $activeConnections = static::getConnectionCount($profile);
        $maxConnections = $profile->effective_max_streams;

        return $activeConnections < $maxConnections;
    }

    /**
     * Get the current connection count for a profile.
     *
     * Uses Redis for real-time tracking.
     */
    public static function getConnectionCount(PlaylistProfile $profile): int
    {
        $key = static::getConnectionCountKey($profile);

        try {
            $count = Redis::get($key);

            return $count ? (int) $count : 0;
        } catch (\Exception $e) {
            Log::error("Failed to get connection count for profile {$profile->id}", [
                'exception' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Increment the connection count for a profile.
     *
     * Called when a new stream starts using this profile.
     */
    public static function incrementConnections(PlaylistProfile $profile, string $streamId): void
    {
        $countKey = static::getConnectionCountKey($profile);
        $streamKey = static::getStreamProfileKey($streamId);
        $streamsKey = static::getProfileStreamsKey($profile);

        try {
            Redis::pipeline(function ($pipe) use ($countKey, $streamKey, $streamsKey, $profile, $streamId) {
                $pipe->incr($countKey);
                $pipe->expire($countKey, static::STREAM_TRACKING_TTL);
                $pipe->set($streamKey, $profile->id);
                $pipe->expire($streamKey, static::STREAM_TRACKING_TTL);
                $pipe->sadd($streamsKey, $streamId);
                $pipe->expire($streamsKey, static::STREAM_TRACKING_TTL);
            });

            $newCount = static::getConnectionCount($profile);

            Log::info('Incremented connections for profile', [
                'profile_id' => $profile->id,
                'profile_name' => $profile->name,
                'stream_id' => $streamId,
                'new_count' => $newCount,
                'max_connections' => $profile->effective_max_streams,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to increment connections for profile {$profile->id}", [
                'stream_id' => $streamId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Decrement the connection count for a profile.
     *
     * Called when a stream ends.
     * Uses a Lua script to atomically decrement only if count > 0,
     * preventing TOCTOU races that could push the count negative.
     *
     * Also clears the channel→stream mapping for this stream via the reverse
     * lookup key, covering both normal stream-ended cleanup and reservation
     * cancellation (where streamId is a reservation ID).
     */
    public static function decrementConnections(PlaylistProfile $profile, string $streamId): void
    {
        $countKey = static::getConnectionCountKey($profile);
        $streamKey = static::getStreamProfileKey($streamId);
        $streamsKey = static::getProfileStreamsKey($profile);
        $channelReverseKey = static::getStreamChannelKey($streamId);

        try {
            // Atomic decrement-if-positive via Lua script.
            // Returns the new count (>= 0) or -1 if already at zero.
            $lua = <<<'LUA'
                local current = tonumber(redis.call('get', KEYS[1]) or 0)
                if current > 0 then
                    return redis.call('decr', KEYS[1])
                end
                return -1
            LUA;

            $result = Redis::eval($lua, 1, $countKey);

            if ($result == -1) {
                Log::warning("Attempted to decrement connections for profile {$profile->id} but count was already 0", [
                    'stream_id' => $streamId,
                ]);
            }

            // Look up the channel coordinates before deleting the reverse key.
            $channelValue = Redis::get($channelReverseKey);

            // Clean up stream references and the reverse channel-mapping key.
            Redis::pipeline(function ($pipe) use ($streamKey, $streamsKey, $channelReverseKey, $streamId) {
                $pipe->del($streamKey);
                $pipe->srem($streamsKey, $streamId);
                $pipe->del($channelReverseKey);
            });

            // Clear the channel→stream key only when it still points to this stream.
            // Guards against deleting a newer stream's entry when streams overlap
            // (e.g. rapid channel switch where the new stream starts before the old
            // stream's decrement webhook fires).
            if ($channelValue !== null) {
                $parts = explode(':', $channelValue, 2);

                if (count($parts) === 2) {
                    [$channelId, $playlistUuid] = $parts;
                    $channelStreamKey = static::getChannelStreamKey((int) $channelId, $playlistUuid);
                    $currentStreamValue = Redis::get($channelStreamKey);

                    if ($currentStreamValue === $streamId) {
                        Redis::del($channelStreamKey);
                    }
                }
            }

            $newCount = static::getConnectionCount($profile);

            Log::info('Decremented connections for profile', [
                'profile_id' => $profile->id,
                'profile_name' => $profile->name,
                'stream_id' => $streamId,
                'new_count' => $newCount,
                'max_connections' => $profile->effective_max_streams,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to decrement connections for profile {$profile->id}", [
                'stream_id' => $streamId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Decrement connection by stream ID (when profile is unknown).
     *
     * Looks up which profile the stream was using and decrements accordingly.
     */
    public static function decrementConnectionsByStreamId(string $streamId): void
    {
        $streamKey = static::getStreamProfileKey($streamId);

        try {
            $profileId = Redis::get($streamKey);

            if ($profileId) {
                $profile = PlaylistProfile::find($profileId);
                if ($profile) {
                    static::decrementConnections($profile, $streamId);
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to decrement connections by stream ID {$streamId}", [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the profile ID for a given stream.
     */
    public static function getProfileIdForStream(string $streamId): ?int
    {
        $key = static::getStreamProfileKey($streamId);

        try {
            $profileId = Redis::get($key);

            return $profileId ? (int) $profileId : null;
        } catch (\Exception $e) {
            Log::error("Failed to get profile ID for stream {$streamId}", [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get total pool capacity for a playlist.
     */
    public static function getTotalCapacity(Playlist $playlist): int
    {
        if (! $playlist->profiles_enabled) {
            return 0;
        }

        return $playlist->enabledProfiles()
            ->get()
            ->sum(fn ($profile) => $profile->effective_max_streams);
    }

    /**
     * Get total active connections across all profiles for a playlist.
     */
    public static function getTotalActiveConnections(Playlist $playlist): int
    {
        if (! $playlist->profiles_enabled) {
            return 0;
        }

        $total = 0;
        foreach ($playlist->enabledProfiles()->get() as $profile) {
            $total += static::getConnectionCount($profile);
        }

        return $total;
    }

    /**
     * Get pool status summary for a playlist.
     */
    public static function getPoolStatus(Playlist $playlist): array
    {
        if (! $playlist->profiles_enabled) {
            return [
                'enabled' => false,
                'profiles' => [],
                'total_capacity' => 0,
                'total_active' => 0,
                'available' => 0,
            ];
        }

        $profiles = [];
        $totalCapacity = 0;
        $totalActive = 0;

        foreach ($playlist->profiles()->get() as $profile) {
            $activeCount = static::getConnectionCount($profile);
            $maxStreams = $profile->effective_max_streams;

            // Get expiration date from provider_info (stored in database)
            $providerInfo = $profile->provider_info;
            $expDate = $providerInfo['user_info']['exp_date'] ?? null;

            // Convert Unix timestamp to date string for Carbon compatibility
            if ($expDate && is_numeric($expDate)) {
                $expDate = date('Y-m-d H:i:s', $expDate);
            }

            $profiles[] = [
                'id' => $profile->id,
                'name' => $profile->name ?? "Profile #{$profile->id}",
                'username' => $profile->username,
                'enabled' => $profile->enabled,
                'priority' => $profile->priority,
                'is_primary' => $profile->is_primary,
                'max_streams' => $maxStreams,
                'active_connections' => $activeCount,
                'available' => max(0, $maxStreams - $activeCount),
                'provider_info_updated_at' => $profile->provider_info_updated_at?->toIso8601String(),
                'exp_date' => $expDate, // Add expiration date for PlaylistInfo component (as date string)
            ];

            if ($profile->enabled) {
                $totalCapacity += $maxStreams;
                $totalActive += $activeCount;
            }
        }

        return [
            'enabled' => true,
            'profiles' => $profiles,
            'total_capacity' => $totalCapacity,
            'total_active' => $totalActive,
            'available' => max(0, $totalCapacity - $totalActive),
        ];
    }

    /**
     * Refresh provider info for all profiles in a playlist.
     */
    public static function refreshAllProfiles(Playlist $playlist): array
    {
        $results = [];

        foreach ($playlist->profiles()->get() as $profile) {
            $results[$profile->id] = static::refreshProfile($profile);
        }

        return $results;
    }

    /**
     * Refresh provider info for a single profile.
     */
    public static function refreshProfile(PlaylistProfile $profile): bool
    {
        try {
            $xtreamConfig = $profile->xtream_config;

            if (! $xtreamConfig) {
                Log::warning("Cannot refresh profile {$profile->id}: no xtream config");

                return false;
            }

            // Pass playlist for context (passes user agent, ssl settings, etc.)
            $xtream = XtreamService::make(
                playlist: $profile->playlist,
                xtream_config: $xtreamConfig
            );

            if (! $xtream) {
                Log::warning("Cannot refresh profile {$profile->id}: failed to create XtreamService");

                return false;
            }

            $userInfo = $xtream->userInfo(timeout: 5);

            if ($userInfo) {
                $maxConnections = (int) ($userInfo['user_info']['max_connections'] ?? 1);

                // Only auto-update max_streams if not explicitly set by the user.
                // A positive value means the user (or initial auto-detection) has
                // already configured it — respect that choice even if provider upgrades.
                // Auto-update only when max_streams is null/0 (never configured).
                $shouldUpdateMaxStreams = ! $profile->max_streams
                    || $profile->max_streams <= 0;

                $updateData = [
                    'provider_info' => $userInfo,
                    'provider_info_updated_at' => now(),
                ];

                if ($shouldUpdateMaxStreams && $maxConnections > 0) {
                    $updateData['max_streams'] = $maxConnections;
                }

                $profile->update($updateData);

                Log::info("Refreshed provider info for profile {$profile->id}", [
                    'max_connections' => $maxConnections,
                    'updated_max_streams' => $shouldUpdateMaxStreams && $maxConnections > 1,
                ]);

                return true;
            }

            Log::warning("Failed to get user info for profile {$profile->id}");

            return false;
        } catch (\Exception $e) {
            Log::error("Error refreshing profile {$profile->id}", [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Verify profile credentials are valid.
     */
    public static function verifyCredentials(PlaylistProfile $profile): array
    {
        $xtreamConfig = $profile->xtream_config;

        if (! $xtreamConfig) {
            return [
                'valid' => false,
                'error' => 'No Xtream configuration available',
            ];
        }

        return static::testCredentials($xtreamConfig);
    }

    /**
     * Test credentials from raw Xtream config data.
     *
     * This can be used to verify credentials before a profile is saved,
     * useful for the "Test Profile" action in the UI.
     *
     * @param  array  $xtreamConfig  Array with 'url', 'username', 'password' keys
     */
    public static function testCredentials(array $xtreamConfig): array
    {
        try {
            if (empty($xtreamConfig['url']) || empty($xtreamConfig['username']) || empty($xtreamConfig['password'])) {
                return [
                    'valid' => false,
                    'error' => 'Missing required credentials (url, username, or password)',
                ];
            }

            $xtream = XtreamService::make(xtream_config: $xtreamConfig);

            if (! $xtream) {
                return [
                    'valid' => false,
                    'error' => 'Failed to connect to provider',
                ];
            }

            $userInfo = $xtream->userInfo(timeout: 5);

            if ($userInfo && isset($userInfo['user_info'])) {
                $info = $userInfo['user_info'];

                return [
                    'valid' => true,
                    'username' => $info['username'] ?? $xtreamConfig['username'],
                    'status' => $info['status'] ?? 'Unknown',
                    'max_connections' => (int) ($info['max_connections'] ?? 1),
                    'active_cons' => (int) ($info['active_cons'] ?? 0),
                    'exp_date' => isset($info['exp_date']) ? date('Y-m-d', $info['exp_date']) : null,
                    'is_trial' => $info['is_trial'] ?? false,
                ];
            }

            return [
                'valid' => false,
                'error' => 'Invalid credentials or provider unavailable',
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create the primary profile from a playlist's xtream_config.
     *
     * Called when profiles are first enabled on a playlist.
     */
    public static function createPrimaryProfile(Playlist $playlist): ?PlaylistProfile
    {
        if (! $playlist->xtream_config) {
            return null;
        }

        $config = $playlist->xtream_config;

        // First, test credentials to get the provider's max_connections
        $xtreamConfig = [
            'url' => $config['url'] ?? $config['server'] ?? '',
            'username' => $config['username'] ?? '',
            'password' => $config['password'] ?? '',
        ];

        $testResult = static::testCredentials($xtreamConfig);
        $maxStreams = $testResult['valid'] ? $testResult['max_connections'] : 1;

        $profile = PlaylistProfile::create([
            'playlist_id' => $playlist->id,
            'user_id' => $playlist->user_id,
            'name' => 'Primary Account',
            'url' => $xtreamConfig['url'], // Store the URL in the profile
            'username' => $config['username'] ?? '',
            'password' => $config['password'] ?? '',
            'max_streams' => $maxStreams,
            'priority' => 0,
            'enabled' => true,
            'is_primary' => true,
        ]);

        // Fetch and store full provider info
        static::refreshProfile($profile);

        return $profile;
    }

    /**
     * Reconcile Redis connection counts with provider API.
     *
     * Useful for correcting drift between tracked and actual connections.
     */
    public static function reconcileConnections(PlaylistProfile $profile): void
    {
        try {
            // Refresh provider info to get current active_cons
            static::refreshProfile($profile);

            $providerActive = $profile->current_connections;
            $redisActive = static::getConnectionCount($profile);

            if ($providerActive !== $redisActive) {
                Log::info("Reconciling connection count for profile {$profile->id}", [
                    'redis_count' => $redisActive,
                    'provider_count' => $providerActive,
                ]);

                // Note: We can't simply set Redis to provider count because
                // provider count includes ALL connections (not just from m3u-editor).
                // Instead, we log the discrepancy for monitoring.
            }
        } catch (\Exception $e) {
            Log::error("Failed to reconcile connections for profile {$profile->id}", [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Quick reconcile of profile connection counts from proxy active streams.
     *
     * Used inline (e.g. after stopping a stream) to immediately correct
     * Redis counts without waiting for the scheduled reconcile task.
     *
     * Iterates ALL profiles (including disabled ones) to ensure stale Redis
     * counts on disabled profiles are also cleaned up.
     */
    public static function reconcileFromProxy(Playlist $playlist): void
    {
        if (! $playlist->profiles_enabled) {
            return;
        }

        $activeStreams = M3uProxyService::getPlaylistActiveStreams($playlist);

        // If API call failed, don't touch counts
        if ($activeStreams === null) {
            return;
        }

        // Build map of profile_id => active stream count
        $profileStreamCounts = [];
        foreach ($activeStreams as $stream) {
            $profileId = $stream['metadata']['provider_profile_id'] ?? null;
            if ($profileId) {
                $profileStreamCounts[$profileId] = ($profileStreamCounts[$profileId] ?? 0) + ($stream['client_count'] ?? 1);
            }
        }

        // Iterate ALL profiles (including disabled) to clean stale Redis counts.
        // Disabled profiles should have 0 active streams; if Redis still shows
        // a positive count from before the profile was disabled, correct it.
        foreach ($playlist->profiles()->get() as $profile) {
            $redisCount = static::getConnectionCount($profile);
            $proxyCount = $profileStreamCounts[$profile->id] ?? 0;

            if ($redisCount !== $proxyCount) {
                $key = static::getConnectionCountKey($profile);
                try {
                    Redis::set($key, $proxyCount);
                    Log::debug('Quick reconciled profile connection count', [
                        'profile_id' => $profile->id,
                        'enabled' => $profile->enabled,
                        'old_count' => $redisCount,
                        'new_count' => $proxyCount,
                    ]);
                } catch (\Exception $e) {
                    Log::error("Failed to quick reconcile profile {$profile->id}: {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Clean up stale stream entries for a profile.
     *
     * Called periodically to remove orphaned stream records.
     */
    public static function cleanupStaleStreams(PlaylistProfile $profile): int
    {
        $streamsKey = static::getProfileStreamsKey($profile);
        $cleaned = 0;

        try {
            $streamIds = Redis::smembers($streamsKey);

            if (empty($streamIds)) {
                return 0;
            }

            // Get the playlist to query active streams from the proxy
            $playlist = $profile->playlist;
            if (! $playlist) {
                return 0;
            }

            $activeStreams = M3uProxyService::getPlaylistActiveStreams($playlist);

            // If API call failed, don't touch anything
            if ($activeStreams === null) {
                return 0;
            }

            // Build a set of active stream IDs from the proxy
            $activeStreamIds = collect($activeStreams)
                ->pluck('stream_id')
                ->filter()
                ->toArray();

            // Remove any Redis-tracked streams that are no longer active in the proxy
            foreach ($streamIds as $streamId) {
                if (! in_array($streamId, $activeStreamIds)) {
                    $streamKey = static::getStreamProfileKey($streamId);
                    Redis::pipeline(function ($pipe) use ($streamsKey, $streamKey, $streamId) {
                        $pipe->srem($streamsKey, $streamId);
                        $pipe->del($streamKey);
                    });
                    $cleaned++;

                    Log::debug('Cleaned up stale stream entry', [
                        'profile_id' => $profile->id,
                        'stream_id' => $streamId,
                    ]);
                }
            }

            // If we cleaned any, correct the connection count
            if ($cleaned > 0) {
                $countKey = static::getConnectionCountKey($profile);
                $currentCount = (int) Redis::get($countKey);
                $correctedCount = max(0, $currentCount - $cleaned);
                Redis::set($countKey, $correctedCount);

                Log::info('Corrected connection count after stale stream cleanup', [
                    'profile_id' => $profile->id,
                    'old_count' => $currentCount,
                    'new_count' => $correctedCount,
                    'cleaned' => $cleaned,
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Failed to cleanup stale streams for profile {$profile->id}", [
                'exception' => $e->getMessage(),
            ]);
        }

        return $cleaned;
    }

    /**
     * Reset all connection tracking for a profile.
     *
     * Use with caution - primarily for testing or recovery.
     */
    public static function resetConnectionTracking(PlaylistProfile $profile): void
    {
        $countKey = static::getConnectionCountKey($profile);
        $streamsKey = static::getProfileStreamsKey($profile);

        try {
            // Get all stream IDs for this profile
            $streamIds = Redis::smembers($streamsKey);

            // Delete stream->profile mappings
            foreach ($streamIds as $streamId) {
                Redis::del(static::getStreamProfileKey($streamId));
            }

            // Reset count and streams set
            Redis::del($countKey);
            Redis::del($streamsKey);

            Log::info("Reset connection tracking for profile {$profile->id}");
        } catch (\Exception $e) {
            Log::error("Failed to reset connection tracking for profile {$profile->id}", [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reconcile and atomically select+reserve a profile.
     *
     * Called just before returning a 503 to give the system one last chance
     * to correct stale Redis counts (e.g. from race conditions when switching
     * channels where increment fires before the old stream's decrement webhook).
     *
     * Returns [PlaylistProfile, string $reservationId] on success, or [null, null].
     *
     * @return array{0: PlaylistProfile|null, 1: string|null}
     */
    public static function reconcileAndSelectProfile(Playlist $playlist, ?int $excludeProfileId = null, bool $forceSelect = false, ?string $clientIdentifier = null): array
    {
        if (! $playlist->profiles_enabled) {
            return [null, null];
        }

        // Reconcile Redis counts against actual proxy state
        static::reconcileFromProxy($playlist);

        // Now retry profile selection with atomic reservation
        return static::selectAndReserveProfile($playlist, $excludeProfileId, forceSelect: $forceSelect, clientIdentifier: $clientIdentifier);
    }

    /**
     * Get the active stream ID for a channel, if currently known to be streaming.
     *
     * Returns null when the key is absent or only a pending reservation exists
     * (reservation IDs start with the 'reservation:' prefix).
     */
    public static function getChannelActiveStreamId(int $channelId, string $playlistUuid): ?string
    {
        $key = static::getChannelStreamKey($channelId, $playlistUuid);

        try {
            $value = Redis::get($key);

            if ($value === null || str_starts_with($value, 'reservation:')) {
                return null;
            }

            return $value;
        } catch (\Exception $e) {
            Log::warning("Failed to get channel active stream ID for channel {$channelId}", [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check whether a channel has an active stream or pending reservation.
     */
    public static function isChannelStreamActive(int $channelId, string $playlistUuid): bool
    {
        $key = static::getChannelStreamKey($channelId, $playlistUuid);

        try {
            return Redis::exists($key) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Clear the channel→stream mapping for a given channel.
     *
     * Called when a stale key is detected (e.g. stream died but key was not cleaned up).
     */
    public static function clearChannelStreamMapping(int $channelId, string $playlistUuid): void
    {
        $key = static::getChannelStreamKey($channelId, $playlistUuid);

        try {
            Redis::del($key);
        } catch (\Exception $e) {
            Log::warning("Failed to clear channel stream mapping for channel {$channelId}", [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the Redis key for a profile's connection count.
     */
    protected static function getConnectionCountKey(PlaylistProfile $profile): string
    {
        return static::REDIS_PREFIX."{$profile->id}:connections";
    }

    /**
     * Get the Redis key for a profile's stream set.
     */
    protected static function getProfileStreamsKey(PlaylistProfile $profile): string
    {
        return static::REDIS_PREFIX."{$profile->id}:streams";
    }

    /**
     * Get the Redis key for stream->profile mapping.
     */
    protected static function getStreamProfileKey(string $streamId): string
    {
        return "stream:{$streamId}:profile_id";
    }

    /**
     * Get the Redis key tracking which stream is serving a given channel.
     */
    protected static function getChannelStreamKey(int $channelId, string $playlistUuid): string
    {
        return "channel_stream:{$channelId}:{$playlistUuid}";
    }

    /**
     * Get the Redis key for the reverse mapping: stream → channel coordinates.
     */
    protected static function getStreamChannelKey(string $streamId): string
    {
        return "stream:{$streamId}:channel";
    }
}
