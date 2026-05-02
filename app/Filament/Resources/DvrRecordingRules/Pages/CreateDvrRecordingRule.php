<?php

namespace App\Filament\Resources\DvrRecordingRules\Pages;

use App\Filament\Resources\DvrRecordingRules\DvrRecordingRuleResource;
use App\Jobs\DvrSchedulerTick;
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

    protected function afterCreate(): void
    {
        // Dispatch immediate scheduler tick so matching recordings materialise without waiting up to 60s.
        DvrSchedulerTick::dispatch();
    }
}
