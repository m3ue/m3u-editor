<?php

namespace App\Filament\Resources\PlaylistAliases\Pages;

use App\Filament\Resources\PlaylistAliases\PlaylistAliasResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlaylistAlias extends CreateRecord
{
    protected static string $resource = PlaylistAliasResource::class;

    protected function getRedirectUrl(): string
    {
        return EditPlaylistAlias::getUrl(['record' => $this->getRecord()]);
    }
}
