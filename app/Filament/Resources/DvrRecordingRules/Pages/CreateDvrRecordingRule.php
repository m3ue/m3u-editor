<?php

namespace App\Filament\Resources\DvrRecordingRules\Pages;

use App\Filament\Resources\DvrRecordingRules\DvrRecordingRuleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateDvrRecordingRule extends CreateRecord
{
    protected static string $resource = DvrRecordingRuleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = Auth::id();

        return $data;
    }
}
