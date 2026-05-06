<?php

namespace App\Filament\Resources\SyncRuns\Pages;

use App\Filament\Resources\SyncRuns\SyncRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSyncRun extends ViewRecord
{
    protected static string $resource = SyncRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
