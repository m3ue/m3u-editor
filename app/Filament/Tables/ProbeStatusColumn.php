<?php

namespace App\Filament\Tables;

use Filament\Tables\Columns\IconColumn;

class ProbeStatusColumn
{
    public static function make(): IconColumn
    {
        return IconColumn::make('stream_stats_probed_at')
            ->label(__('Probed'))
            ->getStateUsing(function ($record): string {
                if ($record->stream_stats_probed_at === null) {
                    return 'never';
                }

                return empty($record->stream_stats) ? 'failed' : 'ok';
            })
            ->icon(fn (string $state): string => match ($state) {
                'ok' => 'heroicon-o-check-circle',
                'failed' => 'heroicon-o-exclamation-triangle',
                default => 'heroicon-o-x-circle',
            })
            ->color(fn (string $state): string => match ($state) {
                'ok' => 'success',
                'failed' => 'warning',
                default => 'gray',
            })
            ->tooltip(function ($record): string {
                if ($record->stream_stats_probed_at === null) {
                    return __('Not probed yet');
                }

                if (empty($record->stream_stats)) {
                    return __('Probe ran but returned no stream info').' ('.$record->stream_stats_probed_at->diffForHumans().')';
                }

                return __('Probed').' '.$record->stream_stats_probed_at->diffForHumans();
            })
            ->toggleable()
            ->sortable();
    }
}
