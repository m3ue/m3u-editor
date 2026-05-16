<?php

namespace App\Enums;

enum SyncRunPhase: string
{
    case VodMetadata = 'vod_metadata';
    case VodTmdb = 'vod_tmdb';
    case VodStrm = 'vod_strm';
    case VodProbe = 'vod_probe';
    case VodStrmPostProbe = 'vod_strm_post_probe';

    case SeriesMetadata = 'series_metadata';
    case SeriesTmdb = 'series_tmdb';
    case SeriesStrm = 'series_strm';
    case SeriesProbe = 'series_probe';
    case SeriesStrmPostProbe = 'series_strm_post_probe';

    case FindReplace = 'find_replace';
    case CustomPlaylistSync = 'custom_playlist_sync';

    case SyncCompleted = 'sync_completed';

    public function getLabel(): string
    {
        return match ($this) {
            self::VodMetadata => 'VOD Metadata',
            self::VodTmdb => 'VOD TMDB IDs',
            self::VodStrm => 'VOD STRM Files',
            self::VodProbe => 'VOD Stream Probe',
            self::VodStrmPostProbe => 'VOD STRM Files (Post-Probe)',
            self::SeriesMetadata => 'Series Metadata',
            self::SeriesTmdb => 'Series TMDB IDs',
            self::SeriesStrm => 'Series STRM Files',
            self::SeriesProbe => 'Series Stream Probe',
            self::SeriesStrmPostProbe => 'Series STRM Files (Post-Probe)',
            self::FindReplace => 'Find & Replace / Sort',
            self::CustomPlaylistSync => 'Custom Playlist Sync',
            self::SyncCompleted => 'Sync Completed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::VodMetadata,
            self::VodTmdb,
            self::VodStrm,
            self::VodProbe,
            self::VodStrmPostProbe => 'info',
            self::SeriesMetadata,
            self::SeriesTmdb,
            self::SeriesStrm,
            self::SeriesProbe,
            self::SeriesStrmPostProbe => 'warning',
            self::FindReplace,
            self::CustomPlaylistSync => 'gray',
            self::SyncCompleted => 'success',
        };
    }

    public function isVod(): bool
    {
        return in_array($this, [
            self::VodMetadata,
            self::VodTmdb,
            self::VodStrm,
            self::VodProbe,
            self::VodStrmPostProbe,
        ]);
    }

    public function isSeries(): bool
    {
        return in_array($this, [
            self::SeriesMetadata,
            self::SeriesTmdb,
            self::SeriesStrm,
            self::SeriesProbe,
            self::SeriesStrmPostProbe,
        ]);
    }
}
