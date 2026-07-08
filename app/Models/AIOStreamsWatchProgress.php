<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIOStreamsWatchProgress extends Model
{
    protected $fillable = [
        'user_id',
        'integration_id',
        'item_id',
        'item_type',
        'position_seconds',
        'duration_seconds',
        'completed',
        'name',
        'poster_url',
        'last_watched_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'integration_id' => 'integer',
        'position_seconds' => 'integer',
        'duration_seconds' => 'integer',
        'completed' => 'boolean',
        'last_watched_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(MediaServerIntegration::class, 'integration_id');
    }
}
