<?php

namespace App\Filament\Resources\PlaylistAliases\Pages;

use App\Filament\Resources\PlaylistAliases\PlaylistAliasResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListPlaylistAliases extends ListRecords
{
    protected static string $resource = PlaylistAliasResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Create an alias of an existing playlist or custom playlist to use a different Xtream API credentials, while still using the same underlying Channel, VOD and Series configurations of the linked playlist.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->slideOver()
                ->using(function (array $data, string $model): Model {
                    $assignedAuthIds = PlaylistAliasResource::pullAssignedAuthIdsFromFormData($data);
                    $record = $model::create($data);

                    if ($assignedAuthIds !== null) {
                        PlaylistAliasResource::syncAssignedAuths($record, $assignedAuthIds);
                    }

                    return $record;
                }),
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
