<?php

namespace App\Filament\Resources\SyncRuns\Pages;

use App\Filament\Resources\SyncRuns\SyncRunResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListSyncRuns extends ListRecords
{
    protected static string $resource = SyncRunResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Per-attempt history of playlist syncs and post-sync orchestration runs.');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
