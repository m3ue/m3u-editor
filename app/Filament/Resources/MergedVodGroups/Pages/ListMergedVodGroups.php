<?php

namespace App\Filament\Resources\MergedVodGroups\Pages;

use App\Filament\Resources\MergedGroups\Pages\ListMergedGroups;
use App\Filament\Resources\MergedVodGroups\MergedVodGroupResource;

class ListMergedVodGroups extends ListMergedGroups
{
    protected static string $resource = MergedVodGroupResource::class;

    protected static string $groupType = 'vod';
}
