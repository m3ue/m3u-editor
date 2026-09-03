<?php

namespace App\Filament\Resources\DynamicGroups\Pages;

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * No destructive actions (no Edit/Delete/Delete-actions) on purpose - this resource
 * is strictly a transparency window over synced `dynamic_group_items` membership.
 * Rule config continues to live on the Playlist form's Dynamic Groups (TMDB)
 * repeater.
 */
class ViewDynamicGroup extends ViewRecord
{
    protected static string $resource = DynamicGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('Back to Dynamic Groups'))
                ->url(DynamicGroupResource::getUrl('index'))
                ->icon('heroicon-s-arrow-left')
                ->color('gray'),
        ];
    }
}
