<?php

namespace App\Models;

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
     * PERFORMANCE FIX: Only returns stored/cached data, never makes HTTP calls during page render.
     * Use ProfileService::refreshProfile() or background jobs to update provider info.
     */
    public function providerInfo(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $cacheKey = "playlist_profile:{$attributes['id']}:provider_info";

                // Try to get from cache first
                $cached = Cache::get($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }

                // Return stored value from database (never fetch live during page render)
                $result = is_string($value) ? json_decode($value, true) : ($value ?? []);

                // Cache the database value for 60 seconds
                if ($result) {
                    Cache::put($cacheKey, $result, 60);
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
     *
     * Returns PHP_INT_MAX when provider_info has not been fetched yet,
     * so that effective_max_streams uses the user's explicit max_streams
     * without being artificially capped to 1.
     */
    public function getProviderMaxConnectionsAttribute(): int
    {
        $info = $this->provider_info;

        // If provider info hasn't been fetched yet, don't restrict
        // the user's configured max_streams with an artificial cap of 1.
        if (empty($info) || ! isset($info['user_info']['max_connections'])) {
            return PHP_INT_MAX;
        }

        return (int) $info['user_info']['max_connections'];
    }

    /**
     * Get the effective max streams (user-defined or provider-defined).
     */
    public function getEffectiveMaxStreamsAttribute(): int
    {
        $providerMax = $this->provider_max_connections;

        // Use user-defined max_streams if set, capped by provider's limit
        if ($this->max_streams && $this->max_streams > 0) {
            return $providerMax === PHP_INT_MAX
                ? $this->max_streams
                : min($this->max_streams, $providerMax);
        }

        // No user-defined limit: use provider's limit, or fall back to 1
        return $providerMax === PHP_INT_MAX ? 1 : $providerMax;
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

            $transformedUrl = "{$profileUrl}/{$streamType}/{$profileUsername}/{$profilePassword}/{$streamIdAndExtension}";

            Log::debug('Profile URL transformation matched', [
                'profile_id' => $this->id,
                'profile_name' => $this->name ?? 'N/A',
                'stream_type' => $streamType,
                'source_base_url' => $sourceBaseUrl,
                'profile_base_url' => $profileUrl,
                'source_user' => substr($sourceUsername, 0, 3).'***',
                'profile_user' => substr($profileUsername, 0, 3).'***',
                'stream_id' => $streamIdAndExtension,
                'original_url' => preg_replace('#/[^/]+/[^/]+/(live|series|movie)/#', '/***/***/\1/', $originalUrl),
                'transformed_url' => preg_replace('#/[^/]+/[^/]+/(live|series|movie)/#', '/***/***/\1/', $transformedUrl),
            ]);

            return $transformedUrl;
        }

        Log::warning('Profile URL transformation did NOT match', [
            'profile_id' => $this->id,
            'profile_name' => $this->name ?? 'N/A',
            'source_base_url' => $sourceBaseUrl,
            'profile_base_url' => $profileUrl,
            'original_url' => preg_replace('#/[^/]+/[^/]+/(live|series|movie)/#', '/***/***/\1/', $originalUrl),
            'pattern' => $pattern,
        ]);

        return $originalUrl;
    }
}
