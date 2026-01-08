<?php

namespace App\Filament\Resources\PostProcesses\Pages;

use App\Filament\Resources\PostProcesses\PostProcessResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePostProcess extends CreateRecord
{
    protected static string $resource = PostProcessResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
