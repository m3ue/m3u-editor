<?php

namespace App\Filament\Resources\SeriesDynamicGroups\Pages;

use App\Filament\Actions\DynamicGroupRuleActions;
use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\SeriesDynamicGroups\SeriesDynamicGroupResource;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Services\CachedContentDispatchService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Series-only listing surface for DynamicGroup rows.
 *
 * Sister page to `VodDynamicGroups\Pages\ListVodDynamicGroups` - the
 * Dynamic Groups listing that this refactor splits out of the
 * per-type footer widgets, exposed under the existing Series nav
 * section so it sits as a sibling of Categories and Series itself.
 *
 * The query is already filtered to `type = 'series'` by
 * `SeriesDynamicGroupResource::getEloquentQuery()` - no per-row type
 * switch needed here.
 *
 * The row's "view" action links to the shared
 * `DynamicGroupResource::getUrl('view', ...)` route - single detail
 * page keyed by record id, breadcrumb chains through
 * `CategoryResource` (see `Pages\ViewDynamicGroup`).
 *
 * Cache-specific tabs, actions, and columns are intentionally absent from
 * this navigation-only surface.
 */
class ListSeriesDynamicGroups extends ListRecords
{
    protected static string $resource = SeriesDynamicGroupResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Virtual categories built from TMDB lists, like Trending, Popular, a TV network or a streaming service\'s catalog. Each group matches a playlist\'s series by TMDB ID, refreshes daily and on playlist sync, and appears in that playlist\'s Xtream series categories.');
    }

    /**
     * Header action: a 'Create Series Dynamic Group' slide-over with the same
     * rule fields, caching options and preview as the Playlist form's
     * `dynamic_groups_config` Repeater, plus a Playlist picker (prefilled with
     * the active sub-tab when not on 'All'). See DynamicGroupRuleActions.
     */
    protected function getHeaderActions(): array
    {
        return [
            DynamicGroupRuleActions::create('series')
                ->visible(fn (): bool => SeriesDynamicGroupResource::shouldRegisterNavigation()),
        ];
    }

    /**
     * Per-playlist sub-tabs. Scope the Dynamic Groups view to a single
     * playlist. See the VOD-side docblock for the full rationale.
     */
    public function getTabs(): array
    {
        $base = static::getResource()::getEloquentQuery();

        // Postgres-safe (see the VOD-side page and the resource's
        // getEloquentQuery() NOTE - no withCount attached here). The "all"
        // count is derived from this same result instead of a second
        // COUNT(*).
        $playlistCounts = (clone $base)
            ->selectRaw('playlist_id, count(*) as aggregate')
            ->groupBy('playlist_id')
            ->pluck('aggregate', 'playlist_id');

        $playlists = Playlist::query()
            ->whereIn('id', $playlistCounts->keys())
            ->orderBy('name')
            ->get(['id', 'name']);

        $tabs = [
            null => Tab::make(__('All Playlists'))
                ->badge($playlistCounts->sum()),
        ];
        foreach ($playlists as $playlist) {
            $tabs[(string) $playlist->id] = Tab::make($playlist->name)
                ->modifyQueryUsing(fn ($query) => $query->where('dynamic_groups.playlist_id', $playlist->id))
                ->badge($playlistCounts->get($playlist->id, 0));
        }

        return $tabs;
    }

    public function table(Table $table): Table
    {
        $cachingEnabled = app(CachedContentDispatchService::class)->isEnabled();

        return $table
            ->defaultSort('name')
            // withCount('series') attaches the series pivot count as
            // a subquery column the TextColumn::make('series_count')
            // reads from. Applied via modifyQueryUsing so it ONLY
            // attaches when the table renders - getTabs() runs a
            // separate groupBy('playlist_id') query against the
            // resource's getEloquentQuery() and that combination blows
            // up Postgres (subquery columns must be in GROUP BY).
            // Only the playlist columns the table and its Edit action read:
            // a full Playlist row carries `xtream_status`, whose accessor
            // queues UpdateXtreamStats on a cache miss whenever the model is
            // serialized.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('series')
                ->with(['playlist:id,name,dynamic_groups_config']))
            ->recordUrl(fn (DynamicGroup $record): string => DynamicGroupResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('playlist.name')
                    ->label(__('Playlist'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source')
                    ->formatStateUsing(fn (DynamicGroup $record): string => DynamicGroupResource::sourceLabelFor($record->type)[$record->source] ?? $record->source)
                    ->badge(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('enabled')
                    ->label(__('Enabled'))
                    ->boolean(),
                // The rule's "Cache group members" toggle, read from the
                // eager-loaded playlist config (no extra query per row).
                // Off for every row while caching is off in Settings, as
                // nothing is cached then.
                IconColumn::make('cache_enabled')
                    ->label(__('Caching Enabled'))
                    ->boolean()
                    ->state(fn (DynamicGroup $record): bool => $cachingEnabled && (bool) ($record->configRule()['cache_enabled'] ?? false))
                    ->tooltip($cachingEnabled ? null : __('Caching is currently disabled in Settings.')),
                TextColumn::make('series_count')
                    ->label(__('Items'))
                    ->numeric(),
                TextColumn::make('last_synced_at')
                    ->since()
                    ->placeholder(__('Never'))
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->button()
                    ->size('sm')
                    ->hiddenLabel(),
                DynamicGroupRuleActions::edit()
                    ->button()
                    ->size('sm')
                    ->hiddenLabel(),
                Action::make('view')
                    ->label(__('View'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (DynamicGroup $record): string => DynamicGroupResource::getUrl('view', ['record' => $record]))
                    ->button()
                    ->color('gray')
                    ->size('sm')
                    ->hiddenLabel(),
            ], RecordActionsPosition::BeforeCells)
            ->emptyStateHeading(__('No Dynamic Groups configured'))
            ->emptyStateDescription(__('Add Dynamic Groups in the Playlist form → Processing → Dynamic Groups (TMDB) section. Synced TMDB lists appear here with their current member counts.'))
            ->emptyStateIcon('heroicon-o-sparkles');
    }
}
