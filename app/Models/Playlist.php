<?php

namespace App\Models;

use App\Enums\PlaylistChannelId;
use App\Enums\PlaylistSourceType;
use App\Enums\Status;
use App\Services\XtreamService;
use App\Traits\ShortUrlTrait;
use AshAllenDesign\ShortURL\Models\ShortURL;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
        'xtream_status' => 'array',
        'short_urls' => 'array',
        'proxy_options' => 'array',
        'short_urls_enabled' => 'boolean',
        'backup_before_sync' => 'boolean',
        'sync_logs_enabled' => 'boolean',
        'include_series_in_m3u' => 'boolean',
        'include_vod_in_m3u' => 'boolean',
        'auto_fetch_series_metadata' => 'boolean',
        'auto_merge_channels_enabled' => 'boolean',
        'auto_merge_deactivate_failover' => 'boolean',
        'auto_merge_config' => 'array',
        'emby_config' => 'array',
        'custom_headers' => 'array',
        'strict_live_ts' => 'boolean',
        'status' => Status::class,
        'id_channel_by' => PlaylistChannelId::class,
        'source_type' => PlaylistSourceType::class,
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
        return collect($this->processing)->values()->contains(true);
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function aliases(): HasMany
    {
        return $this->hasMany(PlaylistAlias::class);
    }

    public function enabledAliases(): HasMany
    {
        return $this->aliases()->where('enabled', true)->orderBy('priority');
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
                'priority' => -1 // Primary always has highest priority
            ];
        }

        // Alias configs
        foreach ($this->enabledAliases as $alias) {
            if ($alias->xtream_config) {
                $configs[] = [
                    'type' => 'alias',
                    'id' => $alias->id,
                    'config' => $alias->xtream_config,
                    'priority' => $alias->priority
                ];
            }
        }

        return collect($configs)->sortBy('priority')->values()->all();
    }

    public function xtreamStatus(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $results = $value;
                $key = "playlist:{$attributes['id']}:xtream_status";
                if ($this->xtream) {
                    // This value is live, cache for 5s at a time, then fetch again
                    try {
                        $xtream = XtreamService::make(xtream_config: $this->xtream_config);
                        if ($xtream) {
                            $results = Cache::remember(
                                $key,
                                5, // cache for 5 seconds
                                function () use ($xtream) {
                                    $userInfo = $xtream->userInfo(timeout: 3);
                                    return $userInfo ?: [];
                                }
                            );
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to fetch metadata for Xtream playlist ' . $this->id, ['exception' => $e]);
                    }
                }
                return is_string($results)
                    ? json_decode($results, true)
                    : $results;
            }
        );
    }
}
