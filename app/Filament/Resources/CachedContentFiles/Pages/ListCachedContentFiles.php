<?php

namespace App\Filament\Resources\CachedContentFiles\Pages;

use App\Filament\Resources\CachedContentFiles\CachedContentFileResource;
use App\Filament\Resources\CachedContentFiles\Widgets\CachedContentStatsOverview;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;

class ListCachedContentFiles extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = CachedContentFileResource::class;

    public function getSubheading(): ?string
    {
        return __('Download progress for cached VOD movies and series episodes.');
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CachedContentStatsOverview::class,
        ];
    }
}
