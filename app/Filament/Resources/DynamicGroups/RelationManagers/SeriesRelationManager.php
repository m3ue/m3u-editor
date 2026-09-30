<?php

namespace App\Filament\Resources\DynamicGroups\RelationManagers;

use App\Filament\Resources\Series\SeriesResource;
use Filament\Actions\ActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Series members of the parent DynamicGroup. Visible only when the parent's
 * `type` is `'series'` - DynamicGroups are single-type by construction, so a
 * vod-type parent has zero series to show and the tab is hidden.
 *
 * Membership is read-only: it is computed by `SyncDynamicGroups` from the
 * parent playlist's `dynamic_groups_config`, and this manager exposes no
 * membership-mutating actions (no edit/delete/move/TMDB). The one row action
 * and the one bulk action are the cache variants reused verbatim from
 * `SeriesResource` - caching a series' episodes is a property of the series
 * itself, not of its group membership, so it is safe to offer here (and in
 * bulk).
 */
class SeriesRelationManager extends RelationManager
{
    protected static string $relationship = 'series';

    protected static ?string $title = 'Series';

    public static function getNavigationLabel(): string
    {
        return __('Series');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type === 'series';
    }

    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make(__('Series'))
            ->badge($ownerRecord->series()->count())
            ->icon('heroicon-m-tv');
    }

    public function table(Table $table): Table
    {
        // Reuse SeriesResource's full table setup - see the parallel comment on
        // `ChannelsRelationManager::table()` for why (drift prevention, matches
        // `Categories\RelationManagers\SeriesRelationManager`'s convention) and why
        // the membership-mutating record/bulk actions are stripped back out
        // afterward (this manager keeps membership read-only, see class
        // docblock). The cache actions are re-added in the same shape the
        // canonical series list uses (single kebab row action, single toolbar
        // bulk action). Anything beyond cache still has to stay out: membership
        // is computed, so it cannot be bulk-edited.
        return SeriesResource::setupTable($table, $this->ownerRecord->id)
            ->recordTitleAttribute('name')
            ->defaultSort('dynamic_group_items.position')
            ->recordActions([
                ActionGroup::make([
                    SeriesResource::getCacheAllEpisodesAction(),
                ])->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                SeriesResource::getCacheAllEpisodesBulkAction(),
            ]);
    }
}
