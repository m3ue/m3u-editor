<?php

namespace App\Filament\Resources\DynamicGroups\Pages;

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use Filament\Resources\Pages\ListRecords;

class ListDynamicGroups extends ListRecords
{
    protected static string $resource = DynamicGroupResource::class;
}
