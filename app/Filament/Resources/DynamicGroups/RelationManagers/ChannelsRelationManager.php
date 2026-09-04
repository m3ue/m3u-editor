<?php

namespace App\Filament\Resources\DynamicGroups\RelationManagers;

use App\Filament\Resources\Vods\VodResource;
use App\Models\DynamicGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * VOD (Channel) members of the parent DynamicGroup. Visible only when the
 * parent's `type` is `'vod'` - DynamicGroups are single-type by construction
 * (matching their `dynamic_groups_config` rule row), so a series-type parent
 * has zero channels to show and the tab is hidden.
 *
 * Strictly read-only - no `recordActions()`, no `toolbarActions()`. Membership
 * is computed by `SyncDynamicGroups` from the parent playlist's
 * `dynamic_groups_config`; this manager is a transparency window, not an edit
 * surface.
 */
class ChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    protected static ?string $title = 'Movies';

    public static function getNavigationLabel(): string
    {
        return __('Movies');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === 'vod';
    }

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make(__('Movies'))
            ->badge($ownerRecord->channels()->count())
            ->icon('heroicon-m-film');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                // The parent's `channels()` relation is `morphedByMany(Channel::class, 'item', 'dynamic_group_items')`
                // - its pivot columns are stored on the relation. We just need
                // standard channel hydration; the relation eagerly loads the
                // pivot but we don't display it.
                return $query;
            })
            ->recordTitleAttribute('title')
            ->columns(VodResource::getTableColumns(showGroup: false, showPlaylist: false))
            ->filters(VodResource::getTableFilters(showPlaylist: false));
    }
}
