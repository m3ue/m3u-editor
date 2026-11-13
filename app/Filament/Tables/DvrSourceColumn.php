<?php

namespace App\Filament\Tables;

use Closure;
use Filament\Tables\Columns\TextColumn;

/**
 * "DVR" badge for library rows (movies, series, episodes) that were added
 * from a DVR recording. [$isFromDvr] tells such a row apart, since series are
 * marked differently from movies and episodes.
 */
class DvrSourceColumn
{
    /**
     * @param  Closure(mixed): bool  $isFromDvr
     */
    public static function make(Closure $isFromDvr): TextColumn
    {
        return TextColumn::make('dvr_source')
            ->label(__('Source'))
            ->state(fn ($record): ?string => $isFromDvr($record) ? __('DVR') : null)
            ->badge()
            ->color('danger')
            ->icon('heroicon-m-video-camera')
            ->tooltip(fn ($record): ?string => $isFromDvr($record) ? __('Added to the library from a DVR recording') : null)
            ->toggleable();
    }
}
