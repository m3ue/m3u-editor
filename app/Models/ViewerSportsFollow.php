<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team a viewer follows on the M3U TV Sports screen ("My Teams"), synced
 * across that viewer's devices.
 */
class ViewerSportsFollow extends Model
{
    use HasFactory;

    protected $fillable = [
        'playlist_viewer_id',
        'team_key',
        'league_id',
        'team_id',
        'name',
        'followed_at',
    ];

    protected $casts = [
        'followed_at' => 'datetime',
    ];

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(PlaylistViewer::class, 'playlist_viewer_id');
    }
}
