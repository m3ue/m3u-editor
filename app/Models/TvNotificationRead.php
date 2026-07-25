<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TvNotificationRead extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tv_notification_id',
        'playlist_auth_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function tvNotification(): BelongsTo
    {
        return $this->belongsTo(TvNotification::class);
    }

    public function playlistAuth(): BelongsTo
    {
        return $this->belongsTo(PlaylistAuth::class);
    }
}
