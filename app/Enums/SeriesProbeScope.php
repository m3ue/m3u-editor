<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Which episodes automatic series probing measures. Episodes of a season (or series) almost
 * always share codec, resolution, HDR and audio layout, so the sampled scopes probe one
 * episode per group and copy its stream stats to the rest of the group, marking the copies
 * with stream_stats_inferred_from_id.
 */
enum SeriesProbeScope: string implements HasLabel
{
    case All = 'all';
    case Season = 'season';
    case Series = 'series';

    public function getLabel(): string
    {
        return match ($this) {
            self::All => __('All Episodes'),
            self::Season => __('First episode of each season'),
            self::Series => __('First episode of each series'),
        };
    }

    /**
     * The episodes column that defines a sampling group, or null when every episode is probed.
     */
    public function groupColumn(): ?string
    {
        return match ($this) {
            self::All => null,
            self::Season => 'season_id',
            self::Series => 'series_id',
        };
    }
}
