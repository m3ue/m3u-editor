<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class PlaylistAuth extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        // 'enabled' => 'boolean',
        'user_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlists(): MorphToMany
    {
        return $this->morphedByMany(Playlist::class, 'authenticatable');
    }

    public function customPlaylists(): MorphToMany
    {
        return $this->morphedByMany(CustomPlaylist::class, 'authenticatable');
    }

    public function mergedPlaylists(): MorphToMany
    {
        return $this->morphedByMany(MergedPlaylist::class, 'authenticatable');
    }
}
