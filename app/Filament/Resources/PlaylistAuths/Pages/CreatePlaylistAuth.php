<?php

namespace App\Filament\Resources\PlaylistAuths\Pages;

use App\Filament\Resources\PlaylistAuths\PlaylistAuthResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlaylistAuth extends CreateRecord
{
    protected static string $resource = PlaylistAuthResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
