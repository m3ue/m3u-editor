<?php

namespace App\Filament\Resources\DynamicGroups\RelationManagers;

use App\Filament\Resources\Vods\VodResource;
use Filament\Actions\ActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * VOD (Channel) members of the parent DynamicGroup. Visible only when the
 * parent's `type` is `'vod'` - DynamicGroups are single-type by construction
 * (matching their `dynamic_groups_config` rule row), so a series-type parent
 * has zero channels to show and the tab is hidden.
 *
 * Membership is read-only: it is computed by `SyncDynamicGroups` from the
 * parent playlist's `dynamic_groups_config`, and this manager exposes no
 * membership-mutating actions (no edit/delete/move/TMDB). The one row action
 * and the one bulk action are the cache variants reused verbatim from
 * `VodResource` - caching is a property of the channel itself, not of its
 * group membership, so it is safe to offer here (and in bulk).
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
        // manager's read-only membership contract (see class docblock) - strip
        // the membership-mutating actions back out, then re-add the cache
        // actions in the same shape the canonical VOD list uses (single kebab
        // row action, single toolbar bulk action). Anything beyond cache still
        // has to stay out: membership is computed, so it cannot be bulk-edited.
        return VodResource::setupTable($table, $this->ownerRecord->id)
            ->recordTitleAttribute('title')
            ->defaultSort('dynamic_group_items.position')
            ->recordActions([
                ActionGroup::make([
                    VodResource::getCacheNowAction(),
                ])->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                VodResource::getCacheNowBulkAction(),
            ]);
    }
}
