<?php

namespace App\Filament\Resources\StreamProfiles\Pages;

use App\Filament\Resources\StreamProfiles\StreamProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListStreamProfiles extends ListRecords
{
    protected static string $resource = StreamProfileResource::class;

    protected ?string $subheading = 'Stream profiles are used to define how streams are transcoded by the proxy. They can be assigned to playlists to enable transcoding for those playlists. If a playlist does not have a stream profile assigned, direct stream proxying will be used.';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
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
