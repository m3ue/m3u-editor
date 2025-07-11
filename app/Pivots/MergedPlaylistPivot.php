<?php

namespace App\Pivots;

use App\Models\Channel;
use App\Models\Group;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\Pivot;

class MergedPlaylistPivot extends Pivot
{
    protected $table = 'merged_playlist_playlist';

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function mergedPlaylist(): BelongsTo
    {
        return $this->belongsTo(MergedPlaylist::class);
    }

    public function channels(): HasManyThrough
    {
        return $this->hasManyThrough(
            Channel::class,
            Playlist::class
        );
    }

    public function groups(): HasManyThrough
    {
        return $this->hasManyThrough(
            Group::class,
            Playlist::class
        );
    }

    public function enabledChannels(): HasManyThrough
    {
        return $this->channels()->where('enabled', true);
    }
}
