<?php

namespace App\Filament\Resources\DynamicGroups\RelationManagers;

use App\Filament\Resources\Series\SeriesResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Series members of the parent DynamicGroup. Visible only when the parent's
 * `type` is `'series'` - DynamicGroups are single-type by construction, so a
 * vod-type parent has zero series to show and the tab is hidden.
 *
 * Strictly read-only - no `recordActions()`, no `toolbarActions()`. Membership
 * is computed by `SyncDynamicGroups` from the parent playlist's
 * `dynamic_groups_config`; this manager is a transparency window, not an edit
 * surface.
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
        // record/bulk actions are stripped back out afterward (this manager stays
        // strictly read-only, see class docblock).
        return SeriesResource::setupTable($table, $this->ownerRecord->id)
            ->recordTitleAttribute('name')
            ->recordActions([])
            ->toolbarActions([]);
    }
}
