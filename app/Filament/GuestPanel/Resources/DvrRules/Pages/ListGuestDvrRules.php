<?php

namespace App\Filament\GuestPanel\Resources\DvrRules\Pages;

use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Filament\GuestPanel\Resources\DvrRules\GuestDvrRuleResource;
use Filament\Resources\Pages\ListRecords;

class ListGuestDvrRules extends ListRecords
{
    use HasPlaylist;

    protected static string $resource = GuestDvrRuleResource::class;

    protected static ?string $title = '';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
