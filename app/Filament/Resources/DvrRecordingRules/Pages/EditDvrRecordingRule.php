<?php

namespace App\Filament\Resources\DvrRecordingRules\Pages;

use App\Filament\Resources\DvrRecordingRules\DvrRecordingRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDvrRecordingRule extends EditRecord
{
    protected static string $resource = DvrRecordingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
