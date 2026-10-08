<?php

namespace App\Filament\Tables;

use App\Models\ArrCacheMovie;
use App\Models\Channel;
use App\Models\Episode;
use App\Services\CachedContentDispatchService;
use App\Services\MediaSourcePreferenceService;
use Filament\Tables\Columns\IconColumn;

/**
 * Where a VOD movie or episode plays from, beyond the provider: a local
 * cache file, a media server copy (what Radarr/Sonarr caching ends up as),
 * or a Radarr/Sonarr request still waiting on its download.
 */
class CacheStateColumn
{
    public const CACHED = 'cached';

    public const MEDIA_SERVER = 'media_server';

    public const WAITING_ON_ARR = 'waiting_on_arr';

    public const NOT_CACHED = 'not_cached';

    public static function make(): IconColumn
    {
        return IconColumn::make('is_cached')
            ->label(__('Cached'))
            ->visible(fn (): bool => app(CachedContentDispatchService::class)->isEnabled())
            ->getStateUsing(fn (Channel|Episode $record): string => self::stateFor($record))
            ->icon(fn (string $state): string => match ($state) {
                self::MEDIA_SERVER => 'heroicon-o-server',
                self::WAITING_ON_ARR => 'heroicon-o-clock',
                default => 'heroicon-o-circle-stack',
            })
            ->color(fn (string $state): string => match ($state) {
                self::CACHED => 'success',
                self::MEDIA_SERVER => 'info',
                self::WAITING_ON_ARR => 'warning',
                default => 'gray',
            })
            ->tooltip(fn (string $state, Channel|Episode $record): string => match ($state) {
                self::CACHED => __('Cached file available. Playback will use the local cache.'),
                self::MEDIA_SERVER => __('Available on your media server. Playback will use it instead of the provider.'),
                self::WAITING_ON_ARR => __('Sent to :arr, waiting for the download. Playback uses the provider until then.', [
                    'arr' => $record instanceof Channel ? 'Radarr' : 'Sonarr',
                ]),
                default => __('Not cached. Use "Cache Now" to download the file for offline playback.'),
            })
            ->toggleable();
    }

    /**
     * Movies dynamic-group caching added to a Radarr that removes them again
     * once they leave every group (ArrCacheMovie). Only shown when the
     * viewer has any such movie.
     */
    public static function radarrManaged(): IconColumn
    {
        return IconColumn::make('radarr_managed')
            ->label(__('Radarr Cleanup'))
            ->visible(fn (): bool => app(CachedContentDispatchService::class)->isEnabled()
                && ArrCacheMovie::anyVisibleTo(auth()->user()))
            ->getStateUsing(fn (Channel $record): bool => $record->playlist !== null
                && ArrCacheMovie::isTracked($record->playlist->user_id, (int) $record->getTmdbId()))
            ->icon(fn (bool $state): ?string => $state ? 'heroicon-o-arrow-path-rounded-square' : null)
            ->color('info')
            ->tooltip(fn (bool $state): ?string => $state
                ? __('Added to Radarr by dynamic-group caching. Radarr removes it, files included, after it has been out of every dynamic group for the keep period. Use "Cache Now" to keep it.')
                : null)
            ->toggleable();
    }

    /**
     * A local file wins, then a media server copy, then a pending arr
     * request.
     */
    public static function stateFor(Channel|Episode $record): string
    {
        if ($record->isCached()) {
            return self::CACHED;
        }

        if (app(MediaSourcePreferenceService::class)->hasEligibleMatch($record)) {
            return self::MEDIA_SERVER;
        }

        if ($record->cachedContentFile()->arrTracked()->exists()) {
            return self::WAITING_ON_ARR;
        }

        return self::NOT_CACHED;
    }
}
