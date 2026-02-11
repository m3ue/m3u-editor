<?php

namespace App\Models;

use App\Enums\PlaylistChannelId;
use App\Traits\ShortUrlTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Spatie\Tags\HasTags;

class CustomPlaylist extends Model
{
    use HasFactory;
    use HasTags;
    use ShortUrlTrait;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'dummy_epg' => 'boolean',
        'short_urls' => 'array',
        'proxy_options' => 'array',
        'short_urls_enabled' => 'boolean',
        'include_series_in_m3u' => 'boolean',
        'include_networks_in_m3u' => 'boolean',
        'include_vod_in_m3u' => 'boolean',
        'custom_headers' => 'array',
        'strict_live_ts' => 'boolean',
        'use_sticky_session' => 'boolean',
        'id_channel_by' => PlaylistChannelId::class,
    ];

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

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_custom_playlist');
    }

    public function enabled_channels(): BelongsToMany
    {
        return $this->channels()
            ->where('enabled', true);
    }

    public function series(): BelongsToMany
    {
        return $this->belongsToMany(Series::class, 'series_custom_playlist');
    }

    public function enabled_series(): BelongsToMany
    {
        return $this->series()
            ->where('enabled', true);
    }

    public function live_channels(): BelongsToMany
    {
        return $this->channels()
            ->where('is_vod', false);
    }

    public function enabled_live_channels(): BelongsToMany
    {
        return $this->live_channels()
            ->where('enabled', true);
    }

    public function vod_channels(): BelongsToMany
    {
        return $this->channels()
            ->where('is_vod', true);
    }

    public function enabled_vod_channels(): BelongsToMany
    {
        return $this->vod_channels()
            ->where('enabled', true);
    }

    public function customChannels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function groups(): MorphToMany
    {
        return $this->groupTags();
    }

    public function groupTags(): MorphToMany
    {
        return $this->morphToMany(\Spatie\Tags\Tag::class, 'taggable')
            ->where('type', $this->uuid);
    }

    public function categories(): MorphToMany
    {
        return $this->categoryTags();
    }

    public function categoryTags(): MorphToMany
    {
        return $this->morphToMany(\Spatie\Tags\Tag::class, 'taggable')
            ->where('type', $this->uuid.'-category');
    }

    // public function playlists(): HasManyThrough
    // {
    //     return $this->hasManyThrough(
    //         Playlist::class,
    //         CustomPlaylistPivot::class,
    //         'custom_playlist_id',
    //         'channel_id',
    //         'id',
    //         'channel_id'
    //     );
    // }

    public function playlistAuths(): MorphToMany
    {
        return $this->morphToMany(PlaylistAuth::class, 'authenticatable');
    }

    public function postProcesses(): MorphToMany
    {
        return $this->morphToMany(PostProcess::class, 'processable');
    }

    public function getAutoSortAttribute(): bool
    {
        return true;
    }

    /**
     * Get all unique source playlists that have channels assigned to this custom playlist.
     * This is useful for determining which provider credentials need to be configured
     * when creating a Playlist Alias.
     */
    public function getSourcePlaylists(): \Illuminate\Database\Eloquent\Collection
    {
        $playlistIds = $this->channels()
            ->whereNotNull('playlist_id')
            ->distinct()
            ->pluck('playlist_id');

        return Playlist::whereIn('id', $playlistIds)->get();
    }

    /**
     * Get source playlists with their xtream config info for alias configuration.
     * Returns an array of playlists with their URL base for matching.
     *
     * @return array<int, array{id: int, name: string, url: string|null}>
     */
    public function getSourcePlaylistsForAlias(): array
    {
        return $this->getSourcePlaylists()
            ->map(function (Playlist $playlist) {
                $url = $playlist->xtream_config['url'] ?? null;

                return [
                    'id' => $playlist->id,
                    'name' => $playlist->name,
                    'url' => $url ? rtrim($url, '/') : null,
                ];
            })
            ->filter(fn ($config) => $config['url'] !== null)
            ->toArray();
    }

    /**
     * Check if any source playlists have provider profiles enabled.
     * When this returns true, proxy mode should be required for proper connection pooling.
     */
    public function hasPooledSourcePlaylists(): bool
    {
        return $this->channels()
            ->whereNotNull('playlist_id')
            ->whereHas('playlist', function ($query) {
                $query->where('profiles_enabled', true);
            })
            ->exists();
    }

    /**
     * Get source playlists that have provider profiles enabled.
     */
    public function getPooledSourcePlaylists(): \Illuminate\Database\Eloquent\Collection
    {
        $playlistIds = $this->channels()
            ->whereNotNull('playlist_id')
            ->distinct()
            ->pluck('playlist_id');

        return Playlist::whereIn('id', $playlistIds)
            ->where('profiles_enabled', true)
            ->get();
    }
}
