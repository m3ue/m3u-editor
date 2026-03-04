<?php

namespace App\Filament\Resources\MergedEpgs\Pages;

use App\Filament\Resources\MergedEpgs\MergedEpgResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMergedEpg extends EditRecord
{
    protected static string $resource = MergedEpgResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
