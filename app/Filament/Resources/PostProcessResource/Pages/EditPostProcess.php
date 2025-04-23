<?php

namespace App\Filament\Resources\PostProcessResource\Pages;

use App\Filament\Resources\PostProcessResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPostProcess extends EditRecord
{
    protected static string $resource = PostProcessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave()
    {
        // $this->dispatch('refreshRelation');
    }
}
