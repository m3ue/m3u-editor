<?php

namespace App\Filament\Resources\Epgs\Pages;

use App\Filament\Resources\Epgs\EpgResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListEpgs extends ListRecords
{
    protected static string $resource = EpgResource::class;

    protected ?string $subheading = 'Add multiple EPG sources and map them to your playlists. Multiple EPG sources can be mapped to the same playlist, and the guide data will be merged together.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
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
