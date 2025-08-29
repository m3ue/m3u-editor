<?php

namespace App\Filament\GuestPanel\Resources\Vods\Pages;

use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Filament\GuestPanel\Resources\Vods\VODResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListVod extends ListRecords
{
    use HasPlaylist;

    protected static string $resource = VodResource::class;
    protected static ?string $title = '';

    // @TODO:
    // Need to figure out the getUrl so filters, searches, etc work properly

    // public static function getUrl(
    //     array $parameters = [],
    //     bool $isAbsolute = true,
    //     ?string $panel = null,
    //     $tenant = null
    // ): string {
    //     $parameters['uuid'] = static::getCurrentUuid();
    //     return route(static::getRouteName($panel), $parameters, $isAbsolute);
    // }

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
