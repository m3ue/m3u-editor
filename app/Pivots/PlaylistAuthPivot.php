<?php

namespace App\Pivots;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use InvalidArgumentException;

class PlaylistAuthPivot extends Pivot
{
    protected $table = 'authenticatables';

    public function playlistAuth(): BelongsTo
    {
        return $this->belongsTo(PlaylistAuth::class);
    }

    public function type(): string
    {
        return match ($this->authenticatable_type) {
            'custom_playlist', CustomPlaylist::class => 'Custom Playlist',
            'merged_playlist', MergedPlaylist::class => 'Merged Playlist',
            'alias', PlaylistAlias::class => 'Playlist Alias',
            default => 'Playlist',
        };
    }

    public function model(): BelongsTo
    {
        return match ($this->authenticatable_type) {
            'custom_playlist', CustomPlaylist::class => $this->belongsTo(CustomPlaylist::class, 'authenticatable_id'),
            'merged_playlist', MergedPlaylist::class => $this->belongsTo(MergedPlaylist::class, 'authenticatable_id'),
            'alias', PlaylistAlias::class => $this->belongsTo(PlaylistAlias::class, 'authenticatable_id'),
            default => $this->belongsTo(Playlist::class, 'authenticatable_id'),
        };
    }

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Boot method to add model event listeners
     */
    protected static function boot()
    {
        parent::boot();

        // Before creating, check if this playlist_auth_id is already assigned
        static::creating(function ($pivot) {
            $existing = static::where('playlist_auth_id', $pivot->playlist_auth_id)->first();
            if ($existing) {
                throw new InvalidArgumentException(
                    "PlaylistAuth ID {$pivot->playlist_auth_id} is already assigned to a model. ".
                    'Use the assignTo() method on PlaylistAuth to reassign.'
                );
            }
        });
    }
}
