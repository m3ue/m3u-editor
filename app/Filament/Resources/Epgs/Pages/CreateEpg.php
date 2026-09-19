<?php

namespace App\Filament\Resources\Epgs\Pages;

use App\Filament\Resources\Epgs\Concerns\ValidatesSchedulesDirectLineupSelection;
use App\Filament\Resources\Epgs\EpgResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEpg extends CreateRecord
{
    use ValidatesSchedulesDirectLineupSelection;

    protected static string $resource = EpgResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->validateSchedulesDirectLineupSelection($data);
    }
}
