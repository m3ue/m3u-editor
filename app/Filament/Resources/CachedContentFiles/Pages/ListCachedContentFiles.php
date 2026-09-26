<?php

namespace App\Filament\Resources\CachedContentFiles\Pages;

use App\Filament\Resources\CachedContentFiles\CachedContentFileResource;
use Filament\Resources\Pages\ListRecords;

class ListCachedContentFiles extends ListRecords
{
    protected static string $resource = CachedContentFileResource::class;

    public function getSubheading(): ?string
    {
        return __('Download progress for cached VOD movies and series episodes.');
    }
}
