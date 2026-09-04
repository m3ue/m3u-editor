<?php

namespace App\Filament\Resources\DynamicGroups\Pages;

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only View surface for a DynamicGroup row.
 *
 * The auto-generated breadcrumb (Dynamic Groups > {Group Name}) was the
 * reason this resource was first taken off the routes — clicking the
 * "Dynamic Groups" link landed users on the global list, which is the
 * wrong context for someone who drilled in from a VOD/Series/Categories
 * page. We override `getHeaderBreadcrumbs()` to chain back through the
 * owning playlist instead, so the breadcrumb matches the user's actual
 * context regardless of which content-type page they came from.
 *
 * No destructive actions on purpose - this page is strictly a transparency
 * window over the dynamic_group_items rows the owning playlist's last
 * `dynamic_groups` phase produced. Rule config lives on the Playlist form.
 */
class ViewDynamicGroup extends ViewRecord
{
    protected static string $resource = DynamicGroupResource::class;

    /**
     * Filament auto-generates breadcrumbs from the resource name. Override
     * here so the link chain reads:
     *
     *     Playlists → {Playlist Name} → {Group Name}
     *
     * where the first two are links. Users who drilled in from a VOD/
     * Series/Categories page can always back out via the playlist view,
     * which is the natural parent context for a per-playlist row.
     *
     * @return array<int, string|null>
     */
    public function getBreadcrumb(): string
    {
        return $this->getRecord()->name;
    }

    protected function getHeaderBreadcrumbs(): array
    {
        $record = $this->getRecord();

        return [
            PlaylistResource::getUrl('view', ['record' => $record->playlist_id]) => $record->playlist?->name ?? __('Playlist'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            // The original "Back to Dynamic Groups" link landed users on a
            // list they never asked to be on. Point at the playlist view
            // instead so the back action matches the breadcrumb chain above.
            Action::make('back_to_playlist')
                ->label(__('Back to Playlist'))
                ->url(fn (): string => PlaylistResource::getUrl('view', ['record' => $this->getRecord()->playlist_id]))
                ->icon('heroicon-o-arrow-left')
                ->color('gray'),
        ];
    }
}
