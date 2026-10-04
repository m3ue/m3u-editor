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

                if (empty($record->stream_stats)) {
                    return 'failed';
                }

                return $record->stream_stats_inferred_from_id ? 'inferred' : 'ok';
            })
            ->icon(fn (string $state): string => match ($state) {
                'ok' => 'heroicon-o-check-circle',
                'inferred' => 'heroicon-o-document-duplicate',
                'failed' => 'heroicon-o-exclamation-triangle',
                default => 'heroicon-o-x-circle',
            })
            ->color(fn (string $state): string => match ($state) {
                'ok' => 'success',
                'inferred' => 'info',
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

                if ($record->stream_stats_inferred_from_id) {
                    return __('Stream info reused from a probed episode of the same season or series').' ('.$record->stream_stats_probed_at->diffForHumans().')';
                }

                return __('Probed').' '.$record->stream_stats_probed_at->diffForHumans();
            })
            ->toggleable()
            ->sortable();
    }
}
