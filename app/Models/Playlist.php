<?php

namespace App\Models;

use App\Enums\PlaylistChannelId;
use App\Enums\PlaylistSourceType;
use App\Enums\Status;
use App\Jobs\UpdateXtreamStats;
use App\Traits\ShortUrlTrait;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Cache;

class Playlist extends Model
{
    use HasFactory;
    use ShortUrlTrait;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'channels' => 'integer',
        'synced' => 'datetime',
        'uploads' => 'array',
        'user_id' => 'integer',
        'sync_time' => 'float',
        'processing' => 'array',
        'dummy_epg' => 'boolean',
        'import_prefs' => 'array',
        'groups' => 'array',
        'xtream_config' => 'array',
        'xtream_fallback_urls' => 'array',
        'xtream_status' => 'array',
        'short_urls' => 'array',
        'proxy_options' => 'array',
        'short_urls_enabled' => 'boolean',
        'backup_before_sync' => 'boolean',
        'sync_logs_enabled' => 'boolean',
        'include_series_in_m3u' => 'boolean',
        'include_vod_in_m3u' => 'boolean',
        'auto_fetch_series_metadata' => 'boolean',
        'auto_sync_series_stream_files' => 'boolean',
        'auto_merge_channels_enabled' => 'boolean',
        'auto_merge_deactivate_failover' => 'boolean',
        'auto_merge_config' => 'array',
        'auto_probe_streams' => 'boolean',
        'find_replace_rules' => 'array',
        'sort_alpha_config' => 'array',
        'emby_config' => 'array',
        'custom_headers' => 'array',
        'strict_live_ts' => 'boolean',
        'use_sticky_session' => 'boolean',
        'profiles_enabled' => 'boolean',
        'bypass_provider_limits' => 'boolean',
        'enable_provider_affinity' => 'boolean',
        'is_network_playlist' => 'boolean',
        'status' => Status::class,
        'id_channel_by' => PlaylistChannelId::class,
        'source_type' => PlaylistSourceType::class,
        'disable_catchup' => 'boolean',
        'enable_channels' => 'boolean',
        'enable_vod_channels' => 'boolean',
        'enable_series' => 'boolean',
        'auto_retry_503_count' => 'integer',
        'auto_retry_503_last_at' => 'datetime',
    ];

    public function getFolderPathAttribute(): string
    {
        return "playlist/{$this->uuid}";
    }

    public function getFilePathAttribute(): string
    {
        return "playlist/{$this->uuid}/playlist.m3u";
    }

    public function isProcessing(): bool
    {
        return collect($this->processing ?? [])->values()->contains(true);
    }

    public function isProcessingLive(): bool
    {
        return $this->processing['live_processing'] ?? false;
    }

    public function isProcessingVod(): bool
    {
        return $this->processing['vod_processing'] ?? false;
    }

    public function isProcessingSeries(): bool
    {
        return $this->processing['series_processing'] ?? false;
    }

    /**
     * Returns true if this playlist is backed by a media server integration
     * (Emby, Jellyfin, Plex, LocalMedia). Standard playlists (m3u, xtream, local, null)
     * return false.
     */
    public function isMediaServerPlaylist(): bool
    {
        return in_array($this->source_type, [
            PlaylistSourceType::Emby,
            PlaylistSourceType::Jellyfin,
            PlaylistSourceType::Plex,
            PlaylistSourceType::LocalMedia,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the media server integration that owns this playlist (if any).
     */
    public function mediaServerIntegration(): HasOne
    {
        return $this->hasOne(MediaServerIntegration::class);
    }

    /**
     * Get networks associated with this playlist's media server integration.
     */
    public function getNetworks(): Collection
    {
        $integration = $this->mediaServerIntegration;
        if (! $integration) {
            // Fallback: get user's networks not linked to any specific integration
            return Network::where('user_id', $this->user_id)
                ->whereNull('media_server_integration_id')
                ->where('enabled', true)
                ->orderBy('channel_number')
                ->orderBy('name')
                ->get();
        }

        return $integration->networks()
            ->where('enabled', true)
            ->orderBy('channel_number')
            ->orderBy('name')
            ->get();
    }

    public function streamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class, 'stream_profile_id');
    }

    public function vodStreamProfile(): BelongsTo
    {
        return $this->belongsTo(StreamProfile::class, 'vod_stream_profile_id');
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /**
     * Get networks that output to this playlist (for network playlists).
     */
    public function networks(): HasMany
    {
        return $this->hasMany(Network::class, 'network_playlist_id');
    }

    public function enabled_channels(): HasMany
    {
        return $this->channels()
            ->where('enabled', true);
    }

    public function live_channels(): HasMany
    {
        return $this->channels()
            ->where('is_vod', false);
    }

    public function enabled_live_channels(): HasMany
    {
        return $this->live_channels()
            ->where('enabled', true);
    }

    public function vod_channels(): HasMany
    {
        return $this->channels()
            ->where('is_vod', true);
    }

    public function enabled_vod_channels(): HasMany
    {
        return $this->vod_channels()
            ->where('enabled', true);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function liveGroups(): HasMany
    {
        return $this->groups()->where('type', 'live');
    }

    public function vodGroups(): HasMany
    {
        return $this->groups()->where('type', 'vod');
    }

    public function sourceGroups(): HasMany
    {
        return $this->hasMany(SourceGroup::class);
    }

    public function sourceCategories(): HasMany
    {
        return $this->hasMany(SourceCategory::class);
    }

    public function mergedPlaylists(): BelongsToMany
    {
        return $this->belongsToMany(MergedPlaylist::class, 'merged_playlist_playlist');
    }

    public function epgMaps(): HasMany
    {
        return $this->hasMany(EpgMap::class);
    }

    public function channelScrubbers(): HasMany
    {
        return $this->hasMany(ChannelScrubber::class);
    }

    public function playlistAuths(): MorphToMany
    {
        return $this->morphToMany(PlaylistAuth::class, 'authenticatable');
    }

    public function postProcesses(): MorphToMany
    {
        return $this->morphToMany(PostProcess::class, 'processable');
    }

    public function syncStatuses(): HasMany
    {
        return $this->hasMany(PlaylistSyncStatus::class)
            ->orderBy('created_at', 'desc');
    }

    public function syncStatusesUnordered(): HasMany
    {
        return $this->hasMany(PlaylistSyncStatus::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function series(): HasMany
    {
        return $this->hasMany(Series::class);
    }

    public function enabled_series(): HasMany
    {
        return $this->series()->where('enabled', true);
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    public function playlistViewers(): MorphMany
    {
        return $this->morphMany(PlaylistViewer::class, 'viewerable');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(PlaylistAlias::class);
    }

    public function enabledAliases(): HasMany
    {
        return $this->aliases()->where('enabled', true)->orderBy('priority');
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(PlaylistProfile::class);
    }

    public function enabledProfiles(): HasMany
    {
        return $this->profiles()->where('enabled', true)->orderBy('priority');
    }

    public function primaryProfile(): ?PlaylistProfile
    {
        return $this->profiles()->where('is_primary', true)->first();
    }

    public function getAllConfigs(): array
    {
        $configs = [];

        // Primary config
        if ($this->xtream_config) {
            $configs[] = [
                'type' => 'primary',
                'id' => $this->id,
                'config' => $this->xtream_config,
                'priority' => -1, // Primary always has highest priority
            ];
        }

        // Alias configs
        foreach ($this->enabledAliases as $alias) {
            if ($alias->xtream_config) {
                $configs[] = [
                    'type' => 'alias',
                    'id' => $alias->id,
                    'config' => $alias->xtream_config,
                    'priority' => $alias->priority,
                ];
            }
        }

        return collect($configs)->sortBy('priority')->values()->all();
    }

    public function enableProxy(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value) {
                    // Check playlist user has access to proxy features
                    if (! $this->user?->canUseProxy()) {
                        return false;
                    }
                }

                return $value;
            }
        );
    }

    /**
     * Get all Xtream URLs in priority order: primary first, then fallbacks.
     *
     * @return string[]
     */
    public function getOrderedXtreamUrls(): array
    {
        $urls = [];

        $primary = $this->xtream_config['url'] ?? null;
        if ($primary) {
            $urls[] = rtrim($primary, '/');
        }

        foreach ($this->xtream_fallback_urls ?? [] as $url) {
            $normalized = rtrim((string) $url, '/');
            if ($normalized !== '' && ! in_array($normalized, $urls)) {
                $urls[] = $normalized;
            }
        }

        return $urls;
    }

    /**
     * Promote a specific URL to primary, demoting the current primary to fallbacks.
     * Use this when you know which URL actually works (e.g. after a successful failover).
     */
    public function promoteXtreamUrl(string $workingUrl): void
    {
        $allUrls = $this->getOrderedXtreamUrls();
        $normalizedWorking = rtrim($workingUrl, '/');

        if (! in_array($normalizedWorking, $allUrls)) {
            return;
        }

        $newFallbacks = array_values(array_filter(
            $allUrls,
            fn (string $u) => $u !== $normalizedWorking
        ));

        $config = $this->xtream_config;
        $config['url'] = $normalizedWorking;
        $this->update([
            'xtream_config' => $config,
            'xtream_fallback_urls' => $newFallbacks,
        ]);
    }

    /**
     * Rotate the primary Xtream URL to the next working URL.
     * Moves the failed URL into fallbacks and promotes the new URL to primary.
     *
     * @return string|null The new primary URL, or null if no alternatives exist.
     */
    public function rotateXtreamUrl(string $failedUrl): ?string
    {
        $allUrls = $this->getOrderedXtreamUrls();

        if (count($allUrls) <= 1) {
            return null;
        }

        $failedNormalized = rtrim($failedUrl, '/');

        // Find the next URL after the failed one
        $failedIndex = array_search($failedNormalized, $allUrls);
        if ($failedIndex === false) {
            return null;
        }

        $nextIndex = ($failedIndex + 1) % count($allUrls);
        $newPrimary = $allUrls[$nextIndex];

        // Build new fallback list: all URLs except the new primary
        $newFallbacks = [];
        foreach ($allUrls as $url) {
            if ($url !== $newPrimary) {
                $newFallbacks[] = $url;
            }
        }

        // Update the model
        $config = $this->xtream_config;
        $config['url'] = $newPrimary;
        $this->update([
            'xtream_config' => $config,
            'xtream_fallback_urls' => $newFallbacks,
        ]);

        return $newPrimary;
    }

    public function xtreamStatus(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $key = "p:{$attributes['id']}:xtream_status";
                $cached = Cache::get($key);
                if ($cached !== null) {
                    return $cached;
                }

                // Dispatch job to update in background if not cached/cache expired
                UpdateXtreamStats::dispatch($this);

                // Return stored value from database
                $results = is_string($value)
                    ? json_decode($value, true)
                    : ($value ?? []);

                return $results;
            }
        );
    }
}
