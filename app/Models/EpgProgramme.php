<?php

namespace App\Models;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
     * Scope to programmes on the given EPG channels, matching each programme's
     * (epg_id, epg_channel_id) pair rather than the XMLTV channel string alone.
     * XMLTV ids are only unique within one EPG, so matching the string alone
     * pulls in programmes from any other EPG that reuses it (#1827).
     *
     * @param  QueryBuilderContract|list<int>  $epgChannelIds  epg_channels.id values, typically a
     *                                                         subquery selecting channels.epg_channel_id
     */
    public function scopeOnEpgChannels(Builder $query, QueryBuilderContract|array $epgChannelIds): Builder
    {
        return $query->whereExists(fn (QueryBuilder $epgChannels) => $epgChannels->selectRaw('1')
            ->from('epg_channels')
            ->whereColumn('epg_channels.epg_id', 'epg_programmes.epg_id')
            ->whereColumn('epg_channels.channel_id', 'epg_programmes.epg_channel_id')
            ->whereIn('epg_channels.id', $epgChannelIds));
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
