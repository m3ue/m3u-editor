<?php

namespace App\Pivots;

use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PlaylistAuthPivot extends Pivot
{
    protected $table = 'authenticatables';

    public function playlistAuth(): BelongsTo
    {
        return $this->belongsTo(PlaylistAuth::class);
    }

    public function type(): string
    {
        switch ($this->authenticatable_type) {
            case CustomPlaylist::class:
                return 'Custom Playlist';
            case MergedPlaylist::class:
                return 'Merged Playlist';
            default:
                return 'Playlist';
        }
    }

    public function model(): BelongsTo
    {
        switch ($this->authenticatable_type) {
            case CustomPlaylist::class:
                return $this->belongsTo(CustomPlaylist::class, 'authenticatable_id');
            case MergedPlaylist::class:
                return $this->belongsTo(MergedPlaylist::class, 'authenticatable_id');
            default:
                return $this->belongsTo(Playlist::class, 'authenticatable_id');
        }
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
                throw new \InvalidArgumentException(
                    "PlaylistAuth ID {$pivot->playlist_auth_id} is already assigned to a model. " .
                    "Use the assignTo() method on PlaylistAuth to reassign."
                );
            }
        });
    }
}
