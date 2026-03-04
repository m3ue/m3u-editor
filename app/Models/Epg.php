<?php

namespace App\Models;

use App\Enums\EpgSourceType;
use App\Enums\Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;

class Epg extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'synced' => 'datetime',
        'user_id' => 'integer',
        'uploads' => 'array',
        'status' => Status::class,
        'processing' => 'boolean',
        'processing_started_at' => 'datetime',
        'is_cached' => 'boolean',
        'cache_meta' => 'array',
        'source_type' => EpgSourceType::class,
        'sd_token_expires_at' => 'datetime',
        'sd_last_sync' => 'datetime',
        'sd_station_ids' => 'array',
        'sd_errors' => 'array',
        'sd_days_to_import' => 'integer',
        'sd_metadata' => 'array',
        'sd_debug' => 'boolean',
        'is_merged' => 'boolean',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array
     */
    protected $hidden = [
        // 'sd_password',
        'sd_token',
    ];

    /**
     * Boot function for model
     */
    protected static function boot()
    {
        parent::boot();
        static::creating(function ($epg) {
            if (empty($epg->uuid)) {
                $epg->uuid = Str::uuid();
            }
        });
    }

    public function getFolderPathAttribute(): string
    {
        return "epg/{$this->uuid}";
    }

    public function getFilePathAttribute(): string
    {
        return "epg/{$this->uuid}/epg.xml";
    }

    public function getCachedEpgMetaAttribute()
    {
        if (! $this->is_cached || empty($this->cache_meta)) {
            return [
                'min_date' => null,
                'max_date' => null,
                'version' => null,
            ];
        }
        $range = $this->cache_meta['programme_date_range'] ?? null;
        $version = $this->cache_meta['cache_version'] ?? null;

        return [
            'min_date' => $range['min_date'] ?? null,
            'max_date' => $range['max_date'] ?? null,
            'version' => $version,
        ];
    }

    public function isSchedulesDirect(): bool
    {
        return $this->source_type === EpgSourceType::SCHEDULES_DIRECT;
    }

    public function hasValidSchedulesDirectToken(): bool
    {
        return $this->sd_token &&
            $this->sd_token_expires_at &&
            $this->sd_token_expires_at->isFuture();
    }

    public function hasSchedulesDirectCredentials(): bool
    {
        return ! empty($this->sd_username) && ! empty($this->sd_password);
    }

    public function hasSchedulesDirectLineup(): bool
    {
        return ! empty($this->sd_lineup_id);
    }

    public function isMerged(): bool
    {
        return (bool) $this->is_merged;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(EpgChannel::class);
    }

    public function epgMaps(): HasMany
    {
        return $this->hasMany(EpgMap::class);
    }

    public function sourceEpgs(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'merged_epg_epg', 'merged_epg_id', 'epg_id')
            ->withPivot('sort_order');
    }

    public function mergedByEpgs(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'merged_epg_epg', 'epg_id', 'merged_epg_id');
    }

    /**
     * Get all Playlists types (including Standard, Custom, Merged and Aliases) associated with this EPG.
     * Returns a merged, de-duplicated Collection of playlist-like models.
     */
    public function getAllPlaylists(): Collection
    {
        $playlists = $this->getPlaylists();
        $customs = $this->getCustomPlaylists();
        $merged = $this->getMergedPlaylists();
        $aliases = $this->getPlaylistAliases();

        $all = $playlists->concat($customs)->concat($merged)->concat($aliases);

        return $all->unique(function ($item) {
            return $item->getTable().'-'.$item->id;
        })->values();
    }

    /**
     * Get Playlists linked to channels that map to this EPG.
     */
    public function getPlaylists(): Collection
    {
        return Playlist::select('playlists.*')
            ->join('channels', 'channels.playlist_id', '=', 'playlists.id')
            ->join('epg_channels', 'epg_channels.id', '=', 'channels.epg_channel_id')
            ->where('epg_channels.epg_id', $this->id)
            ->distinct()
            ->get();
    }

    /**
     * Get CustomPlaylists linked to channels that map to this EPG.
     */
    public function getCustomPlaylists(): SupportCollection|Collection
    {
        $idsFromChannel = CustomPlaylist::join('channels', 'channels.custom_playlist_id', '=', 'custom_playlists.id')
            ->join('epg_channels', 'epg_channels.id', '=', 'channels.epg_channel_id')
            ->where('epg_channels.epg_id', $this->id)
            ->pluck('custom_playlists.id');

        $idsFromPivot = CustomPlaylist::join('channel_custom_playlist', 'channel_custom_playlist.custom_playlist_id', '=', 'custom_playlists.id')
            ->join('channels', 'channels.id', '=', 'channel_custom_playlist.channel_id')
            ->join('epg_channels', 'epg_channels.id', '=', 'channels.epg_channel_id')
            ->where('epg_channels.epg_id', $this->id)
            ->pluck('custom_playlists.id');

        $ids = $idsFromChannel->concat($idsFromPivot)->unique()->values()->all();

        return $ids ? CustomPlaylist::whereIn('id', $ids)->get() : collect();
    }

    /**
     * Get MergedPlaylists that include playlists which have channels mapped to this EPG.
     */
    public function getMergedPlaylists(): SupportCollection|Collection
    {
        $ids = MergedPlaylist::join('merged_playlist_playlist', 'merged_playlist_playlist.merged_playlist_id', '=', 'merged_playlists.id')
            ->join('playlists', 'playlists.id', '=', 'merged_playlist_playlist.playlist_id')
            ->join('channels', 'channels.playlist_id', '=', 'playlists.id')
            ->join('epg_channels', 'epg_channels.id', '=', 'channels.epg_channel_id')
            ->where('epg_channels.epg_id', $this->id)
            ->pluck('merged_playlists.id')
            ->unique()
            ->values()
            ->all();

        return $ids ? MergedPlaylist::whereIn('id', $ids)->get() : collect();
    }

    /**
     * Get PlaylistAliases for playlists that have channels mapped to this EPG.
     */
    public function getPlaylistAliases(): SupportCollection|Collection
    {
        $ids = PlaylistAlias::join('playlists', 'playlists.id', '=', 'playlist_aliases.playlist_id')
            ->join('channels', 'channels.playlist_id', '=', 'playlists.id')
            ->join('epg_channels', 'epg_channels.id', '=', 'channels.epg_channel_id')
            ->where('epg_channels.epg_id', $this->id)
            ->pluck('playlist_aliases.id')
            ->unique()
            ->values()
            ->all();

        return $ids ? PlaylistAlias::whereIn('id', $ids)->get() : collect();
    }

    public function postProcesses(): MorphToMany
    {
        return $this->morphToMany(PostProcess::class, 'processable');
    }
}
