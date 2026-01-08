<?php

namespace App\Filament\GuestPanel\Resources\Series\Pages;

use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Filament\GuestPanel\Resources\Series\SeriesResource;
use Filament\Resources\Pages\ListRecords;

class ListSeries extends ListRecords
{
    use HasPlaylist;

    protected static string $resource = SeriesResource::class;

    protected static ?string $title = '';

    // public static function getUrl(
    //     array $parameters = [],
    //     bool $isAbsolute = true,
    //     ?string $panel = null,
    //     ?\Illuminate\Database\Eloquent\Model $tenant = null,
    //     bool $shouldGuessMissingParameters = false
    // ): string {
    //     // Try to get uuid from parameters, then from route, then from Livewire property
    //     $parameters['uuid'] = $parameters['uuid'] ?? static::getCurrentUuid();

    //     return route(static::getRouteName($panel), $parameters, $isAbsolute);
    // }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
