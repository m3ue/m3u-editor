<?php

namespace App\Models;

use App\Enums\PlaylistChannelId;
use App\Services\XtreamService;
use App\Traits\ShortUrlTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PlaylistAlias extends Model
{
    use HasFactory;
    use ShortUrlTrait;

    protected $casts = [
        'xtream_config' => 'array',
        'proxy_options' => 'array',
        'enabled' => 'boolean',
        'enable_proxy' => 'boolean',
        'priority' => 'integer',
        'custom_headers' => 'array',
        'strict_live_ts' => 'boolean',
    ];

    /**
     * Get the xtream_config attribute as a normalized array of configs.
     */
    protected function xtreamConfig(): Attribute
    {
        return Attribute::make(
            get: function (string $value) {
                $raw = json_decode($value, true);

                // Legacy format: single config object stored as array with 'url' key.
                if (is_array($raw) && array_key_exists('url', $raw)) {
                    return [$raw];
                }

                // New format: list of configs.
                if (is_array($raw)) {
                    $configs = [];
                    foreach ($raw as $index => $item) {
                        if (is_array($item) && ! empty($item['url'])) {
                            $configs[] = $item;
                        }
                    }

                    return $configs;
                }

                return [];
            },
        );
    }

    public function getPrimaryXtreamConfig(): ?array
    {
        return $this->xtream_config[0] ?? null;
    }

    public function findXtreamConfigByUrl(?string $url): ?array
    {
        if (! $url) {
            return null;
        }

        // Normalize URL for comparison
        $needle = rtrim(strtolower((string) $url), '/');

        foreach ($this->xtream_config as $cfg) {
            // Normalize config URL
            $cfgUrl = rtrim((string) strtolower($cfg['url'] ?? ''), '/');

            // If URLs match, return this config
            if ($cfgUrl !== '' && $cfgUrl === $needle) {
                return $cfg;
            }
        }

        // No matching config found
        return null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function streamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class);
    }

    public function vodStreamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class, 'vod_stream_profile_id');
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function customPlaylist(): BelongsTo
    {
        return $this->belongsTo(CustomPlaylist::class);
    }

    /**
     * Get the effective playlist (either the main playlist or custom playlist)
     * This method returns the playlist that should be used for configuration
     */
    public function getEffectivePlaylist()
    {
        // Load relationships if not already loaded
        if (! $this->relationLoaded('playlist') && $this->playlist_id) {
            $this->load('playlist');
        }

        if (! $this->relationLoaded('customPlaylist') && $this->custom_playlist_id) {
            $this->load('customPlaylist');
        }

        return $this->playlist ?? $this->customPlaylist;
    }

    /**
     * Check if this alias/playlist supports xtream
     */
    public function getXtreamAttribute(): bool
    {
        return ! empty($this->xtream_config);
    }

    /**
     * Get EPG settings
     */
    public function getAutoChannelIncrementAttribute(): bool
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? $effectivePlaylist->auto_channel_increment : false;
    }

    public function getDummyEpgLengthAttribute(): int
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? (int) ($effectivePlaylist->dummy_epg_length ?? 120) : 120;
    }

    public function getIdChannelByAttribute(): PlaylistChannelId
    {
        $effectivePlaylist = $this->getEffectivePlaylist();

        return $effectivePlaylist ? $effectivePlaylist->id_channel_by : PlaylistChannelId::ChannelId;
    }

    /**
     * Get groups through the effective playlist
     */
    public function groups()
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        if (! $effectivePlaylist) {
            return collect();
        }

        return $effectivePlaylist->groups();
    }

    public function groupTags()
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        if (! $effectivePlaylist) {
            return collect();
        }

        return $effectivePlaylist->groupTags();
    }

    /**
     * Get categories through the effective playlist
     */
    public function categories()
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        if (! $effectivePlaylist) {
            return collect();
        }

        return $effectivePlaylist->categories();
    }

    public function categoryTags()
    {
        $effectivePlaylist = $this->getEffectivePlaylist();
        if (! $effectivePlaylist) {
            return collect();
        }

        return $effectivePlaylist->categoryTags();
    }

    public function channels(): BelongsToMany|HasManyThrough
    {
        if ($this->custom_playlist_id) {
            return $this->belongsToMany(Channel::class, 'channel_custom_playlist', 'custom_playlist_id', 'channel_id', 'custom_playlist_id', 'id');
        }

        return $this->hasManyThrough(
            Channel::class,
            Playlist::class,
            'id', // Foreign key on Playlist table
            'playlist_id', // Foreign key on Channel table
            'playlist_id', // Local key on PlaylistAlias table
            'id'  // Local key on Playlist table
        );
    }

    public function series(): BelongsToMany|HasManyThrough
    {
        if ($this->custom_playlist_id) {
            return $this->belongsToMany(Series::class, 'series_custom_playlist', 'custom_playlist_id', 'series_id', 'custom_playlist_id', 'id');
        }

        return $this->hasManyThrough(
            Series::class,
            Playlist::class,
            'id', // Foreign key on Playlist table
            'playlist_id', // Foreign key on Series table
            'playlist_id', // Local key on PlaylistAlias table
            'id'  // Local key on Playlist table
        );
    }

    public function enabled_channels(): BelongsToMany|HasManyThrough
    {
        return $this->channels()
            ->where('enabled', true);
    }

    public function enabled_series(): BelongsToMany|HasManyThrough
    {
        return $this->series()
            ->where('enabled', true);
    }

    public function live_channels(): BelongsToMany|HasManyThrough
    {
        return $this->channels()
            ->where('is_vod', false);
    }

    public function enabled_live_channels(): BelongsToMany|HasManyThrough
    {
        return $this->live_channels()
            ->where('enabled', true);
    }

    public function vod_channels(): BelongsToMany|HasManyThrough
    {
        return $this->channels()
            ->where('is_vod', true);
    }

    public function enabled_vod_channels(): BelongsToMany|HasManyThrough
    {
        return $this->vod_channels()
            ->where('enabled', true);
    }

    public function liveCount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->live_channels()->count()
        );
    }

    public function vodCount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->vod_channels()->count()
        );
    }

    public function seriesCount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->series()->count()
        );
    }

    /**
     * Get the alias credentials (username/password) as an object instead of array.
     *
     * This normalises the alias authentication format so that controllers and
     * services can safely access:
     *
     *      $auth->username
     *      $auth->password
     *
     * regardless of whether the credentials originally came from PlaylistAlias
     * (array) or PlaylistAuth (Eloquent model / object).
     *
     * @return object|null
     */
    public function getAuthObjectAttribute()
    {
        // If explicit alias-level credentials exist, always prefer them.
        if ($this->username && $this->password) {
            return (object) [
                'username' => $this->username,
                'password' => $this->password,
            ];
        }

        return null;
    }

    /**
     * Fetch the Xtream status for this alias
     */
    public function xtreamStatus(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $key = "playlist_alias:{$attributes['id']}:xtream_status";

                $primaryConfig = $this->getPrimaryXtreamConfig();
                if (! $primaryConfig) {
                    return [];
                }

                try {
                    $xtream = XtreamService::make(xtream_config: $primaryConfig);
                    if ($xtream) {
                        return Cache::remember(
                            $key,
                            5, // cache for 5 seconds
                            function () use ($xtream) {
                                $userInfo = $xtream->userInfo(timeout: 3);

                                return $userInfo ?: [];
                            }
                        );
                    }
                } catch (\Exception $e) {
                    Log::error(
                        'Failed to fetch metadata for Xtream playlist alias '.$this->id,
                        ['exception' => $e]
                    );
                }

                return [];
            }
        );
    }

    /**
     * Transform channel URL to use this alias's provider config
     * Only transforms the standard URL, not custom URLs
     */
    public function transformChannelUrl(Channel $channel): string
    {
        $originalUrl = $channel->url ?? '';

        // We need at least one alias xtream config to do any transformation.
        $primaryAliasConfig = $this->getPrimaryXtreamConfig();
        if (! $primaryAliasConfig) {
            return $originalUrl;
        }

        // Get the channel's effective playlist to find its source config.
        $effectivePlaylist = $channel->getEffectivePlaylist();
        if (! $effectivePlaylist || ! $effectivePlaylist->xtream_config) {
            return $originalUrl;
        }

        $sourceConfig = $effectivePlaylist->xtream_config;

        // If this alias has multiple configs, choose the best match by base URL.
        $aliasConfig = $this->findXtreamConfigByUrl((string) ($sourceConfig['url'] ?? '')) ?? $primaryAliasConfig;

        return $this->transformUrl($originalUrl, $sourceConfig, $aliasConfig);
    }

    /**
     * Transform episode URL to use this alias's provider config
     */
    public function transformEpisodeUrl(Episode $episode): string
    {
        $originalUrl = $episode->url ?? '';

        // We need at least one alias xtream config to do any transformation.
        $primaryAliasConfig = $this->getPrimaryXtreamConfig();
        if (! $primaryAliasConfig) {
            return $originalUrl;
        }

        // Get the episode's effective playlist to find its source config.
        $effectivePlaylist = $episode->getEffectivePlaylist();
        if (! $effectivePlaylist || ! $effectivePlaylist->xtream_config) {
            return $originalUrl;
        }

        $sourceConfig = $effectivePlaylist->xtream_config;

        // If this alias has multiple configs, choose the best match by base URL.
        $aliasConfig = $this->findXtreamConfigByUrl((string) ($sourceConfig['url'] ?? '')) ?? $primaryAliasConfig;

        return $this->transformUrl($originalUrl, $sourceConfig, $aliasConfig);
    }

    /**
     * Transform URL from source config to alias config
     */
    private function transformUrl(
        string $originalUrl,
        array $sourceConfig,
        array $aliasConfig
    ): string {
        // Extract source provider details safely
        $sourceBaseUrl = rtrim((string) ($sourceConfig['url'] ?? ''), '/');
        $sourceUsername = (string) ($sourceConfig['username'] ?? '');
        $sourcePassword = (string) ($sourceConfig['password'] ?? '');

        // Extract alias provider details safely
        $aliasBaseUrl = rtrim((string) ($aliasConfig['url'] ?? ''), '/');
        $aliasUsername = (string) ($aliasConfig['username'] ?? '');
        $aliasPassword = (string) ($aliasConfig['password'] ?? '');

        // If any required value is missing, do not attempt to transform
        if (
            $sourceBaseUrl === '' ||
            $sourceUsername === '' ||
            $sourcePassword === '' ||
            $aliasBaseUrl === '' ||
            $aliasUsername === '' ||
            $aliasPassword === ''
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

            return "{$aliasBaseUrl}/{$streamType}/{$aliasUsername}/{$aliasPassword}/{$streamIdAndExtension}";
        }

        return $originalUrl;
    }
}
