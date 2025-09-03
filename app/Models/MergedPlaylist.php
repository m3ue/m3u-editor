<?php

namespace App\Models;

use App\Enums\PlaylistChannelId;
use App\Pivots\MergedPlaylistPivot;
use App\Traits\ShortUrlTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class MergedPlaylist extends Model
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
        'user_id' => 'integer',
        'dummy_epg' => 'boolean',
        'short_urls' => 'array',
        'proxy_options' => 'array',
        'short_urls_enabled' => 'boolean',
        'include_series_in_m3u' => 'boolean',
        'id_channel_by' => PlaylistChannelId::class
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class, 'merged_playlist_playlist');
    }

    public function channels(): HasManyThrough
    {
        return $this->hasManyThrough(
            Channel::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        );
    }

    public function groups(): HasManyThrough
    {
        return $this->hasManyThrough(
            Group::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        );
    }

    public function enabled_channels(): HasManyThrough
    {
        return $this->channels()->where('enabled', true);
    }

    public function series(): hasManyThrough
    {
        return $this->hasManyThrough(
            Series::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        );
    }

    public function enabled_series(): hasManyThrough
    {
        return $this->series()->where('enabled', true);
    }

    public function seasons(): hasManyThrough
    {
        return $this->hasManyThrough(
            Season::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        );
    }

    public function episodes(): hasManyThrough
    {
        return $this->hasManyThrough(
            Episode::class,
            MergedPlaylistPivot::class,
            'merged_playlist_id',
            'playlist_id',
            'id',
            'playlist_id'
        );
    }

    public function live_channels(): hasManyThrough
    {
        return $this->channels()
            ->where('is_vod', false);
    }

    public function enabled_live_channels(): hasManyThrough
    {
        return $this->live_channels()
            ->where('enabled', true);
    }

    public function vod_channels(): hasManyThrough
    {
        return $this->channels()
            ->where('is_vod', true);
    }

    public function enabled_vod_channels(): hasManyThrough
    {
        return $this->vod_channels()
            ->where('enabled', true);
    }

    public function playlistAuths(): MorphToMany
    {
        return $this->morphToMany(PlaylistAuth::class, 'authenticatable');
    }

    public function postProcesses(): MorphToMany
    {
        return $this->morphToMany(PostProcess::class, 'processable');
    }
}
