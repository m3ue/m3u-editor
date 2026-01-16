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
     * Select the best available profile for streaming.
     *
     * Iterates through enabled profiles in priority order and returns
     * the first one with available capacity.
     */
    public static function selectProfile(Playlist $playlist, ?int $excludeProfileId = null): ?PlaylistProfile
    {
        if (! $playlist->profiles_enabled) {
            return null;
        }

        $query = $playlist->enabledProfiles();

        if ($excludeProfileId) {
            $query->where('id', '!=', $excludeProfileId);
        }

        $profiles = $query->get();

        foreach ($profiles as $profile) {
            if (static::hasCapacity($profile)) {
                return $profile;
            }
        }

        Log::warning("No profiles with capacity available for playlist {$playlist->id}");

        return null;
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

            Log::debug("Incremented connections for profile {$profile->id}", [
                'stream_id' => $streamId,
                'new_count' => static::getConnectionCount($profile),
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
     */
    public static function decrementConnections(PlaylistProfile $profile, string $streamId): void
    {
        $countKey = static::getConnectionCountKey($profile);
        $streamKey = static::getStreamProfileKey($streamId);
        $streamsKey = static::getProfileStreamsKey($profile);

        try {
            // Check count before decrementing to avoid race conditions
            $currentCount = (int) Redis::get($countKey);

            if ($currentCount > 0) {
                Redis::pipeline(function ($pipe) use ($countKey, $streamKey, $streamsKey, $streamId) {
                    $pipe->decr($countKey);
                    $pipe->del($streamKey);
                    $pipe->srem($streamsKey, $streamId);
                });
            } else {
                // Just clean up the stream references
                Redis::pipeline(function ($pipe) use ($streamKey, $streamsKey, $streamId) {
                    $pipe->del($streamKey);
                    $pipe->srem($streamsKey, $streamId);
                });

                Log::warning("Attempted to decrement connections for profile {$profile->id} but count was already 0", [
                    'stream_id' => $streamId,
                ]);
            }

            Log::debug("Decremented connections for profile {$profile->id}", [
                'stream_id' => $streamId,
                'new_count' => static::getConnectionCount($profile),
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

            $xtream = XtreamService::make(xtream_config: $xtreamConfig);

            if (! $xtream) {
                Log::warning("Cannot refresh profile {$profile->id}: failed to create XtreamService");

                return false;
            }

            $userInfo = $xtream->userInfo(timeout: 5);

            if ($userInfo) {
                $maxConnections = (int) ($userInfo['user_info']['max_connections'] ?? 1);

                // Update max_streams if not manually set (null/0) OR if it was left at the default of 1
                // This ensures auto-detection works for profiles that weren't properly configured
                $shouldUpdateMaxStreams = ! $profile->max_streams
                    || $profile->max_streams <= 0
                    || $profile->max_streams === 1;

                $updateData = [
                    'provider_info' => $userInfo,
                    'provider_info_updated_at' => now(),
                ];

                if ($shouldUpdateMaxStreams && $maxConnections > 1) {
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

            foreach ($streamIds as $streamId) {
                // Check if stream is still active in the proxy
                // This would need integration with m3u-proxy's stream tracking
                // For now, we'll rely on stream end events
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
}
