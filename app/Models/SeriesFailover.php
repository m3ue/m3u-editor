<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a series to the same series in another playlist. Episode failovers are
 * derived from these links by episode TMDB ID, or season and episode number.
 */
class SeriesFailover extends Model
{
    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'series_id' => 'integer',
        'series_failover_id' => 'integer',
        'sort' => 'integer',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function seriesFailover(): BelongsTo
    {
        return $this->belongsTo(Series::class, 'series_failover_id');
    }
}
