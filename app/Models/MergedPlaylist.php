<?php

namespace App\Models;

use App\Enums\PlaylistChannelId;
use App\Pivots\MergedPlaylistPivot;
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

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
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

    public function enabled_channels(): HasManyThrough
    {
        return $this->channels()->where('enabled', true);
    }

    public function playlistAuths(): MorphToMany
    {
        return $this->morphToMany(PlaylistAuth::class, 'authenticatable');
    }
}
