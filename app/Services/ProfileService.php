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

        // Pre-fetch proxy counts for all profiles in one request, then add
        // per-profile pending reservations from Redis. This replaces N individual
        // proxy calls with a single batch call.
        $profileIds = $profiles->pluck('id')->map(fn ($id) => (string) $id)->all();
        $proxyCounts = M3uProxyService::getActiveStreamsCountsBatch('provider_profile_id', $profileIds);

        // Check client affinity — prefer the profile the client used before.
        // Only active when enable_provider_affinity is set on the playlist.
        if ($clientIdentifier !== null && $playlist->enable_provider_affinity) {
            $affinityProfileId = static::getClientAffinity($clientIdentifier, $playlist->id);

            if ($affinityProfileId !== null) {
                $affinityProfile = $profiles->firstWhere('id', $affinityProfileId);

                if ($affinityProfile) {
                    $affinityCount = ((int) ($proxyCounts[(string) $affinityProfile->id] ?? 0))
                        + static::countPendingReservations($affinityProfile);

                    if ($affinityCount < $affinityProfile->effective_max_streams) {
                        Log::debug('Returning affinity profile for client', [
                            'client_identifier' => $clientIdentifier,
                            'profile_id' => $affinityProfile->id,
                            'profile_name' => $affinityProfile->name,
                        ]);

                        return $affinityProfile;
                    }

                    Log::info('Affinity profile at capacity, selecting next available', [
                        'client_identifier' => $clientIdentifier,
                        'affinity_profile_id' => $affinityProfile->id,
                        'affinity_profile_name' => $affinityProfile->name,
                        'active_connections' => $affinityCount,
                        'max_connections' => $affinityProfile->effective_max_streams,
                    ]);
                }
            }
        }

        $connectionCounts = [];

        foreach ($profiles as $profile) {
            $connectionCounts[$profile->id] = ((int) ($proxyCounts[(string) $profile->id] ?? 0))
                + static::countPendingReservations($profile);
            $activeConnections = $connectionCounts[$profile->id];
            $maxConnections = $profile->effective_max_streams;
            $hasCapacity = $activeConnections < $maxConnections;

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
            $best = $profiles->sortBy(fn ($p) => $connectionCounts[$p->id])->first();

            Log::info('Force-selected profile (bypass provider limits)', [
                'profile_id' => $best->id,
                'profile_name' => $best->name,
                'active_connections' => $connectionCounts[$best->id],
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
        string $streamType = 'channel',
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
                $channelStreamKey = static::getChannelStreamKey($channelId, $channelPlaylistUuid, $streamType);

                if (Redis::exists($channelStreamKey)) {
                    Log::debug('Channel reuse detected inside lock — skipping profile allocation', [
                        'channel_id' => $channelId,
                        'playlist_uuid' => $channelPlaylistUuid,
                        'playlist_id' => $playlist->id,
                        'stream_type' => $streamType,
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
                    $channelStreamKey = static::getChannelStreamKey($channelId, $channelPlaylistUuid, $streamType);
                    $reverseKey = static::getStreamChannelKey($reservationId);

                    Redis::pipeline(function ($pipe) use ($channelStreamKey, $reverseKey, $reservationId, $channelId, $channelPlaylistUuid, $streamType) {
                        $pipe->setex($channelStreamKey, static::CHANNEL_STREAM_PENDING_TTL, $reservationId);
                        $pipe->setex($reverseKey, static::CHANNEL_STREAM_PENDING_TTL, "{$streamType}:{$channelId}:{$channelPlaylistUuid}");
                    });
                }

                // Store client-to-profile affinity so the same client
                // is preferentially assigned the same profile next time.
                if ($clientIdentifier !== null && $playlist->enable_provider_affinity) {
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
        string $streamType = 'channel',
    ): void {
        $streamsKey = static::getProfileStreamsKey($profile);

        try {
            Redis::pipeline(function ($pipe) use ($streamsKey, $reservationId, $realStreamId) {
                // Replace reservation entry with real stream ID
                $pipe->srem($streamsKey, $reservationId);
                $pipe->sadd($streamsKey, $realStreamId);
                $pipe->expire($streamsKey, static::STREAM_TRACKING_TTL);
            });

            // Upgrade the channel→stream mapping from pending reservation ID to real stream ID.
            if ($channelId !== null && $channelPlaylistUuid !== null) {
                $channelStreamKey = static::getChannelStreamKey($channelId, $channelPlaylistUuid, $streamType);
                $oldReverseKey = static::getStreamChannelKey($reservationId);
                $newReverseKey = static::getStreamChannelKey($realStreamId);
                $channelValue = "{$streamType}:{$channelId}:{$channelPlaylistUuid}";

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

        $activeConnections = static::getEffectiveConnectionCount($profile);
        $maxConnections = $profile->effective_max_streams;

        return $activeConnections < $maxConnections;
    }

    /**
     * Count in-flight reservations for a profile.
     *
     * Reservations are stream set entries prefixed with "reservation:" — they
     * represent slots claimed inside the lock but not yet visible to the proxy.
     */
    public static function countPendingReservations(PlaylistProfile $profile): int
    {
        $streamsKey = static::getProfileStreamsKey($profile);

        try {
            $streamIds = Redis::smembers($streamsKey);

            return count(array_filter($streamIds, fn ($id) => str_starts_with($id, 'reservation:')));
        } catch (\Exception $e) {
            Log::warning("Failed to count pending reservations for profile {$profile->id}", [
                'exception' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Get the effective connection count for capacity enforcement.
     *
     * Uses the proxy API as ground truth (actual upstream connections), plus any
     * in-flight reservations tracked in Redis that are not yet visible in the proxy.
     *
     * This prevents two problems:
     *   - Stale Redis INCR counts after a proxy restart blocking new streams.
     *   - TOCTOU races where concurrent requests both see capacity before the first
     *     stream appears in the proxy (reservations fill the gap).
     */
    public static function getEffectiveConnectionCount(PlaylistProfile $profile): int
    {
        $proxyCount = M3uProxyService::getActiveStreamsCountByMetadata(
            'provider_profile_id',
            (string) $profile->id
        );

        return $proxyCount + static::countPendingReservations($profile);
    }

    /**
     * Track a stream (or reservation) against a profile.
     *
     * Adds the stream ID to the profile's streams set so in-flight reservations
     * are visible to getEffectiveConnectionCount() during capacity checks.
     */
    public static function incrementConnections(PlaylistProfile $profile, string $streamId): void
    {
        $streamsKey = static::getProfileStreamsKey($profile);

        try {
            Redis::pipeline(function ($pipe) use ($streamsKey, $streamId) {
                $pipe->sadd($streamsKey, $streamId);
                $pipe->expire($streamsKey, static::STREAM_TRACKING_TTL);
            });

            Log::info('Tracking stream for profile', [
                'profile_id' => $profile->id,
                'profile_name' => $profile->name,
                'stream_id' => $streamId,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to track stream for profile {$profile->id}", [
                'stream_id' => $streamId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove a stream (or reservation) from a profile's tracking set and clean up
     * the channel→stream mapping so the next request can acquire a new stream.
     *
     * Covers both normal stream-ended cleanup and reservation cancellation
     * (where streamId has the 'reservation:' prefix).
     */
    public static function decrementConnections(PlaylistProfile $profile, string $streamId): void
    {
        $streamsKey = static::getProfileStreamsKey($profile);
        $channelReverseKey = static::getStreamChannelKey($streamId);

        try {
            // Look up channel coordinates before deleting the reverse key.
            $channelValue = Redis::get($channelReverseKey);

            Redis::pipeline(function ($pipe) use ($streamsKey, $channelReverseKey, $streamId) {
                $pipe->srem($streamsKey, $streamId);
                $pipe->del($channelReverseKey);
            });

            // Clear the channel→stream key only when it still points to this stream,
            // to avoid clobbering a newer stream on rapid channel switches.
            // Format: "{streamType}:{channelId}:{playlistUuid}" (legacy: "{channelId}:{playlistUuid}")
            if ($channelValue !== null) {
                $parts = explode(':', $channelValue, 3);

                if (count($parts) === 3) {
                    [$streamType, $channelId, $playlistUuid] = $parts;
                } elseif (count($parts) === 2) {
                    // Legacy format written before stream-type namespacing was added.
                    $streamType = 'channel';
                    [$channelId, $playlistUuid] = $parts;
                } else {
                    $streamType = null;
                }

                if ($streamType !== null) {
                    $channelStreamKey = static::getChannelStreamKey((int) $channelId, $playlistUuid, $streamType);

                    if (Redis::get($channelStreamKey) === $streamId) {
                        Redis::del($channelStreamKey);
                    }
                }
            }

            Log::info('Cleaned up stream tracking for profile', [
                'profile_id' => $profile->id,
                'profile_name' => $profile->name,
                'stream_id' => $streamId,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to clean up stream for profile {$profile->id}", [
                'stream_id' => $streamId,
                'exception' => $e->getMessage(),
            ]);
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
     * Get pool status summary for a playlist.
     *
     * Cached for 5 seconds to avoid hammering the proxy API on table renders.
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

        return Cache::remember("pool_status_{$playlist->id}", 5, function () use ($playlist) {
            $allProfiles = $playlist->profiles()->get();
            $profileIds = $allProfiles->pluck('id')->map(fn ($id) => (string) $id)->all();
            $proxyCounts = M3uProxyService::getActiveStreamsCountsBatch('provider_profile_id', $profileIds);

            $profiles = [];
            $totalCapacity = 0;
            $totalActive = 0;

            foreach ($allProfiles as $profile) {
                $activeCount = (int) ($proxyCounts[(string) $profile->id] ?? 0);
                $maxStreams = $profile->effective_max_streams;

                $providerInfo = $profile->provider_info;
                $expDate = $providerInfo['user_info']['exp_date'] ?? null;

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
                    'exp_date' => $expDate,
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
        });
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
     * Ensure the primary profile exists and its credentials match the playlist's xtream_config.
     *
     * Creates the primary profile if it doesn't exist, or updates its URL/username/password
     * when the playlist's provider has changed (e.g. the user re-pointed the playlist to a
     * new provider). Without this sync the primary profile retains the old provider's domain
     * and credentials, causing streams to be directed to the removed provider.
     */
    public static function syncPrimaryProfile(Playlist $playlist): void
    {
        if (! $playlist->xtream_config) {
            return;
        }

        $config = $playlist->xtream_config;
        $newUrl = $config['url'] ?? $config['server'] ?? '';
        $newUsername = $config['username'] ?? '';
        $newPassword = $config['password'] ?? '';

        $primaryProfile = $playlist->profiles()->where('is_primary', true)->first();

        if (! $primaryProfile) {
            static::createPrimaryProfile($playlist);

            return;
        }

        // Only write to the DB when something has actually drifted.
        if (
            $primaryProfile->url !== $newUrl ||
            $primaryProfile->username !== $newUsername ||
            $primaryProfile->password !== $newPassword
        ) {
            $primaryProfile->update([
                'url' => $newUrl,
                'username' => $newUsername,
                'password' => $newPassword,
            ]);

            Log::info('Synced primary profile credentials to playlist xtream_config', [
                'playlist_id' => $playlist->id,
                'profile_id' => $primaryProfile->id,
            ]);
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
                    Redis::srem($streamsKey, $streamId);
                    $cleaned++;

                    Log::debug('Cleaned up stale stream entry', [
                        'profile_id' => $profile->id,
                        'stream_id' => $streamId,
                    ]);
                }
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
        $streamsKey = static::getProfileStreamsKey($profile);

        try {
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

        return static::selectAndReserveProfile($playlist, $excludeProfileId, forceSelect: $forceSelect, clientIdentifier: $clientIdentifier);
    }

    /**
     * Get the active stream ID for a channel, if currently known to be streaming.
     *
     * Returns null when the key is absent or only a pending reservation exists
     * (reservation IDs start with the 'reservation:' prefix).
     */
    public static function getChannelActiveStreamId(int $channelId, string $playlistUuid, string $streamType = 'channel'): ?string
    {
        $key = static::getChannelStreamKey($channelId, $playlistUuid, $streamType);

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
    public static function isChannelStreamActive(int $channelId, string $playlistUuid, string $streamType = 'channel'): bool
    {
        $key = static::getChannelStreamKey($channelId, $playlistUuid, $streamType);

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
    public static function clearChannelStreamMapping(int $channelId, string $playlistUuid, string $streamType = 'channel'): void
    {
        $key = static::getChannelStreamKey($channelId, $playlistUuid, $streamType);

        try {
            Redis::del($key);
        } catch (\Exception $e) {
            Log::warning("Failed to clear channel stream mapping for channel {$channelId}", [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the Redis key for a profile's stream set.
     */
    protected static function getProfileStreamsKey(PlaylistProfile $profile): string
    {
        return static::REDIS_PREFIX."{$profile->id}:streams";
    }

    /**
     * Get the Redis key tracking which stream is serving a given channel.
     */
    protected static function getChannelStreamKey(int $channelId, string $playlistUuid, string $streamType = 'channel'): string
    {
        return "{$streamType}_stream:{$channelId}:{$playlistUuid}";
    }

    /**
     * Get the Redis key for the reverse mapping: stream → channel coordinates.
     */
    protected static function getStreamChannelKey(string $streamId): string
    {
        return "stream:{$streamId}:channel";
    }
}
