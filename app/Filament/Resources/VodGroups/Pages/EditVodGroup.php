<?php

namespace App\Filament\Resources\VodGroups\Pages;

use App\Filament\Resources\VodGroups\VodGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditVodGroup extends EditRecord
{
    protected static string $resource = VodGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
