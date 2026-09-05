<?php

namespace App\Filament\Resources\DynamicGroups\RelationManagers;

use App\Filament\Resources\Vods\VodResource;
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
        // Reuse VodResource's full table setup (columns, filters, eager-loading,
        // pagination, sort) so this view can never drift from the canonical VOD
        // table - the same convention `VodGroups\RelationManagers\VodRelationManager`
        // uses. `$this->ownerRecord->id` is only consulted by setupTable() to decide
        // column visibility (truthy => hide Group/Playlist, same as `showGroup: false,
        // showPlaylist: false` before), not to scope the query - Filament's relation
        // manager machinery already scopes via the `channels` relationship.
        //
        // setupTable() also wires up VodResource's full record/bulk actions
        // (edit, delete, fetch metadata, sync, ...), which would break this
        // manager's "strictly read-only" contract (see class docblock) - strip
        // them back out rather than exposing mutation actions on a computed,
        // read-only membership view.
        return VodResource::setupTable($table, $this->ownerRecord->id)
            ->recordTitleAttribute('title')
            ->recordActions([])
            ->toolbarActions([]);
    }
}
