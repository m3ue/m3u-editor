<?php

namespace App\Pivots;

use App\Models\MergedPlaylist;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

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
}
