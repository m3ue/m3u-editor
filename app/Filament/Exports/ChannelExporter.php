<?php

namespace App\Filament\Exports;

use App\Models\Channel;
use App\Models\Playlist;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Filament\Forms;
use Filament\Forms\Components\Select;

class ChannelExporter extends Exporter
{
    protected static ?string $model = Channel::class;

    public static function getOptionsFormComponents(): array
    {
        return [
            Select::make('playlist')
                ->required()
                ->label('Playlist')
                ->helperText('Select the playlist you would like to export channels for.')
                ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                ->searchable(),
            // Forms\Components\Toggle::make('enabled')
            //     ->label('Only export enabled channels?')
            //     ->default(true),
        ];
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name'),
            ExportColumn::make('group'),
            ExportColumn::make('channel'),
            ExportColumn::make('enabled'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your channel export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
