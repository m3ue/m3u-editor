<?php

namespace App\Filament\Imports;

use App\Models\Channel;
use App\Models\Playlist;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;

class ChannelImporter extends Importer
{
    protected static ?string $model = Channel::class;

    public static function getOptionsFormComponents(): array
    {
        return [
            Select::make('playlist')
                ->required()
                ->label('Playlist')
                ->helperText('Select the playlist this import is associated with.')
                ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                ->preload()
                ->searchable(),
        ];
    }

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->requiredMapping()
                ->rules(['required', 'max:255']),
            ImportColumn::make('group')
                ->requiredMapping()
                ->rules(['required', 'max:255']),
            ImportColumn::make('channel')
                ->requiredMapping()
                ->rules(['required', 'numeric', 'min:0'])
                ->ignoreBlankState()
                ->castStateUsing(function (string $state): ?int {
                    if (blank($state)) {
                        return null;
                    }

                    return $state;
                }),
            ImportColumn::make('enabled')
                ->requiredMapping()
                ->rules(['required', 'boolean'])
                ->ignoreBlankState()
                ->castStateUsing(function (string $state): ?bool {
                    return boolval($state);
                }),
        ];
    }

    public function resolveRecord(): ?Model
    {
        return Channel::where([
            'name' => $this->data['name'],
            'group' => $this->data['group'],
            'playlist_id' => $this->options['playlist'],
        ])->first();
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your channel import has completed and '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
