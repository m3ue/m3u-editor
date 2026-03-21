<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpisodeFailover extends Model
{
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'episode_id' => 'integer',
        'episode_failover_id' => 'integer',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function episodeFailover(): BelongsTo
    {
        return $this->belongsTo(Episode::class, 'episode_failover_id');
    }
}
