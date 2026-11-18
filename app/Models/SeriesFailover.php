<?php

namespace App\Models;

use App\Services\SeriesFailoverLinker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a series to the same series in another playlist. Episode failovers are
 * derived from these links by episode TMDB ID, or season and episode number.
 *
 * Saving or deleting a single link (the Series form's Failover Series repeater)
 * links or unlinks the episodes too. MergeEpisodes writes without events because
 * it links episodes itself.
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

    protected static function booted(): void
    {
        static::creating(function (SeriesFailover $link): ?bool {
            // A series can't be its own failover.
            if ($link->series_id === $link->series_failover_id) {
                return false;
            }

            // Rows added from the Series form's repeater don't carry a user.
            $link->user_id ??= auth()->id();

            return null;
        });

        static::saved(function (SeriesFailover $link): void {
            $linker = app(SeriesFailoverLinker::class);

            $previousFailoverId = (int) $link->getOriginal('series_failover_id');

            if ($link->wasChanged('series_failover_id') && $previousFailoverId) {
                $linker->unlinkEpisodes($link->series, [$previousFailoverId]);
            }

            if ($link->wasRecentlyCreated || $link->wasChanged(['series_failover_id', 'sort'])) {
                $linker->linkEpisodesForSeries($link->series);
            }
        });

        static::deleted(function (SeriesFailover $link): void {
            if ($link->series) {
                app(SeriesFailoverLinker::class)->unlinkEpisodes($link->series, [$link->series_failover_id]);
            }
        });
    }

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
