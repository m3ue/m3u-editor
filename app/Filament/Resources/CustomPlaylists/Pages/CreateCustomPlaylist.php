<?php

namespace App\Filament\Resources\CustomPlaylists\Pages;

use App\Filament\Resources\CustomPlaylists\CustomPlaylistResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomPlaylist extends CreateRecord
{
    protected static string $resource = CustomPlaylistResource::class;
}
