<?php

namespace App\Filament\Resources\MergedVodGroups;

use App\Filament\Resources\MergedGroups\MergedGroupResource;
use App\Filament\Resources\MergedVodGroups\Pages\ListMergedVodGroups;

/**
 * VOD counterpart of {@see MergedGroupResource}. Same Group model, scoped to
 * type = 'vod' and shown under the VOD Channels navigation group.
 */
class MergedVodGroupResource extends MergedGroupResource
{
    protected static string $groupType = 'vod';

    public static function getNavigationGroup(): ?string
    {
        return __('VOD Channels');
    }

    public static function getModelLabel(): string
    {
        return __('Merged VOD Group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Merged VOD Groups');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMergedVodGroups::route('/'),
        ];
    }
}
