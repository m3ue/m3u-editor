<?php

namespace App\Filament\Resources\StreamFileSettings\Pages;

use App\Filament\Resources\StreamFileSettings\StreamFileSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListStreamFileSettings extends ListRecords
{
    protected static string $resource = StreamFileSettingResource::class;

    protected ?string $subheading = 'Stream file settings define how .strm files are generated and organized. They can be assigned globally in Settings, to Groups/Categories, or directly to individual Series/VOD channels. Priority: Direct > Group/Category > Global.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('New Setting')
                ->slideOver(),
        ];
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
