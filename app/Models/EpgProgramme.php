<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class EpgProgramme extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'season' => 'integer',
            'episode' => 'integer',
            'is_new' => 'boolean',
            'previously_shown' => 'boolean',
            'premiere' => 'boolean',
        ];
    }

    public function epg(): BelongsTo
    {
        return $this->belongsTo(Epg::class);
    }

    /**
     * Scope to programmes that start within a given time range.
     */
    public function scopeStartingBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('start_time', [$from, $to]);
    }

    /**
     * Scope to programmes airing now or starting within the given minutes.
     */
    public function scopeUpcoming(Builder $query, int $minutes = 30): Builder
    {
        $now = now();

        return $query->where('start_time', '<=', $now->copy()->addMinutes($minutes))
            ->where('end_time', '>=', $now);
    }

    /**
     * Scope to programmes for specific EPG channel IDs.
     *
     * @param  list<string>  $channelIds
     */
    public function scopeForChannels(Builder $query, array $channelIds): Builder
    {
        return $query->whereIn('epg_channel_id', $channelIds);
    }

    /**
     * Scope to programmes for a specific EPG source.
     */
    public function scopeForEpg(Builder $query, int $epgId): Builder
    {
        return $query->where('epg_id', $epgId);
    }

    /**
     * Scope to only new episodes.
     */
    public function scopeNewOnly(Builder $query): Builder
    {
        return $query->where('is_new', true);
    }
}
