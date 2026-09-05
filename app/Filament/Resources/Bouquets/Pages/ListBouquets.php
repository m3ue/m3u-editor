<?php

namespace App\Filament\Resources\Bouquets\Pages;

use App\Filament\Resources\Bouquets\BouquetResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListBouquets extends ListRecords
{
    protected static string $resource = BouquetResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Create reusable selections of a playlist\'s groups, then assign them to playlist aliases. An alias delivers the union of its assigned bouquets and its own manual group selections.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->slideOver(),
        ];
    }
}
