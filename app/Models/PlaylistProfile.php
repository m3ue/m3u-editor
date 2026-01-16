<?php

namespace App\Models;

use App\Services\XtreamService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PlaylistProfile extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'playlist_id' => 'integer',
        'user_id' => 'integer',
        'max_streams' => 'integer',
        'priority' => 'integer',
        'enabled' => 'boolean',
        'is_primary' => 'boolean',
        'provider_info' => 'array',
        'provider_info_updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Get the playlist that owns this profile.
     */
    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /**
     * Get the user that owns this profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Build xtream_config array compatible with XtreamService.
     *
     * Uses this profile's URL if set, otherwise falls back to playlist's base URL.
     * This allows profiles to connect to different providers.
     */
    public function getXtreamConfigAttribute(): ?array
    {
        if (! $this->playlist || ! $this->playlist->xtream_config) {
            return null;
        }

        $baseConfig = $this->playlist->xtream_config;

        // Use profile's URL if set, otherwise use playlist's URL
        $url = $this->url ?? $baseConfig['url'] ?? $baseConfig['server'] ?? null;

        if (! $url) {
            return null;
        }

        return [
            // Use 'url' key to match XtreamService::init() expectations
            'url' => $url,
            'username' => $this->username,
            'password' => $this->password,
            'output' => $baseConfig['output'] ?? 'ts',
        ];
    }

    /**
     * Get provider info with caching.
     * Fetches user info from the Xtream API and caches it.
     */
    public function providerInfo(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $cacheKey = "playlist_profile:{$attributes['id']}:provider_info";
                $result = null;

                // Try to get from cache first
                $cached = Cache::get($cacheKey);
                if ($cached !== null) {
                    $result = $cached;
                } elseif ($value && isset($attributes['provider_info_updated_at'])) {
                    // If we have a recent value in the database, use that
                    $updatedAt = \Carbon\Carbon::parse($attributes['provider_info_updated_at']);
                    if ($updatedAt->diffInMinutes(now()) < 5) {
                        $decoded = is_string($value) ? json_decode($value, true) : $value;
                        Cache::put($cacheKey, $decoded, 60);
                        $result = $decoded;
                    }
                }

                if ($result === null) {
                    // Fetch fresh data from the provider
                    try {
                        $xtreamConfig = $this->xtream_config;
                        if ($xtreamConfig) {
                            $xtream = XtreamService::make(xtream_config: $xtreamConfig);
                            if ($xtream) {
                                $userInfo = $xtream->userInfo(timeout: 3);
                                if ($userInfo) {
                                    // Update the database
                                    $this->updateQuietly([
                                        'provider_info' => $userInfo,
                                        'provider_info_updated_at' => now(),
                                    ]);

                                    Cache::put($cacheKey, $userInfo, 60);
                                    $result = $userInfo;
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning("Failed to fetch provider info for profile {$attributes['id']}", [
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }

                // Return stored value or empty array if no result
                if ($result === null) {
                    $result = is_string($value) ? json_decode($value, true) : ($value ?? []);
                }

                return $result;
            }
        );
    }

    /**
     * Get the current connection count from provider info.
     */
    public function getCurrentConnectionsAttribute(): int
    {
        $info = $this->provider_info;

        return (int) ($info['user_info']['active_cons'] ?? 0);
    }

    /**
     * Get the max connections allowed by the provider.
     */
    public function getProviderMaxConnectionsAttribute(): int
    {
        $info = $this->provider_info;

        return (int) ($info['user_info']['max_connections'] ?? 1);
    }

    /**
     * Get the effective max streams (user-defined or provider-defined).
     */
    public function getEffectiveMaxStreamsAttribute(): int
    {
        // Use user-defined max_streams if set, otherwise use provider's limit
        if ($this->max_streams && $this->max_streams > 0) {
            return min($this->max_streams, $this->provider_max_connections);
        }

        return $this->provider_max_connections;
    }

    /**
     * Get available stream slots.
     */
    public function getAvailableStreamsAttribute(): int
    {
        return max(0, $this->effective_max_streams - $this->current_connections);
    }

    /**
     * Check if this profile has available capacity.
     */
    public function hasCapacity(): bool
    {
        return $this->enabled && $this->available_streams > 0;
    }

    /**
     * Scope to only enabled profiles.
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope to order by selection priority (priority ASC, then by available capacity DESC).
     */
    public function scopeOrderBySelection($query)
    {
        return $query->orderBy('priority')->orderBy('id');
    }

    /**
     * Scope to get only profiles with capacity.
     * Note: This checks the provider info, so it may require fresh data.
     */
    public function scopeWithCapacity($query)
    {
        return $query->enabled()->orderBySelection();
    }

    /**
     * Get the primary profile for a playlist.
     */
    public static function getPrimaryForPlaylist(int $playlistId): ?self
    {
        return static::where('playlist_id', $playlistId)
            ->where('is_primary', true)
            ->first();
    }

    /**
     * Select the best available profile for streaming.
     *
     * @param  int  $playlistId  The playlist ID
     * @param  int|null  $excludeProfileId  Optional profile ID to exclude (for failover)
     */
    public static function selectForStreaming(int $playlistId, ?int $excludeProfileId = null): ?self
    {
        $query = static::where('playlist_id', $playlistId)
            ->enabled()
            ->orderBySelection();

        if ($excludeProfileId) {
            $query->where('id', '!=', $excludeProfileId);
        }

        // Get all profiles and check capacity
        $profiles = $query->get();

        foreach ($profiles as $profile) {
            if ($profile->hasCapacity()) {
                return $profile;
            }
        }

        return null;
    }

    /**
     * Transform a channel URL to use this profile's credentials.
     */
    public function transformChannelUrl(Channel $channel): string
    {
        $originalUrl = $channel->url ?? '';

        // Don't transform custom URLs
        if ($channel->url_custom) {
            return $channel->url_custom;
        }

        return $this->transformUrl($originalUrl);
    }

    /**
     * Transform an episode URL to use this profile's credentials.
     */
    public function transformEpisodeUrl(Episode $episode): string
    {
        $originalUrl = $episode->url ?? '';

        return $this->transformUrl($originalUrl);
    }

    /**
     * Transform a URL to use this profile's credentials.
     *
     * Replaces the playlist's primary credentials with this profile's credentials.
     * If the profile has a custom URL, the entire base URL is replaced as well.
     */
    public function transformUrl(string $originalUrl): string
    {
        $playlist = $this->playlist;

        if (! $playlist || ! $playlist->xtream_config) {
            return $originalUrl;
        }

        $sourceConfig = $playlist->xtream_config;

        // Extract source provider details
        $sourceBaseUrl = rtrim((string) ($sourceConfig['server'] ?? $sourceConfig['url'] ?? ''), '/');
        $sourceUsername = (string) ($sourceConfig['username'] ?? '');
        $sourcePassword = (string) ($sourceConfig['password'] ?? '');

        // This profile's credentials and URL
        $profileUrl = $this->url ? rtrim($this->url, '/') : $sourceBaseUrl;
        $profileUsername = $this->username;
        $profilePassword = $this->password;

        // If any required value is missing, do not transform
        if (
            $sourceBaseUrl === '' ||
            $sourceUsername === '' ||
            $sourcePassword === '' ||
            $profileUsername === '' ||
            $profilePassword === '' ||
            $profileUrl === ''
        ) {
            return $originalUrl;
        }

        // Pattern matches:
        // http://domain:port/(live|series|movie)/username/password/<stream>
        $pattern =
            '#^'.preg_quote($sourceBaseUrl, '#').
            '/(live|series|movie)/'.preg_quote($sourceUsername, '#').
            '/'.preg_quote($sourcePassword, '#').
            '/(.+)$#';

        if (preg_match($pattern, $originalUrl, $matches)) {
            $streamType = $matches[1];
            $streamIdAndExtension = $matches[2];

            // Use profile's URL (which may be different from source URL)
            return "{$profileUrl}/{$streamType}/{$profileUsername}/{$profilePassword}/{$streamIdAndExtension}";
        }

        return $originalUrl;
    }
}
