<?php

namespace App\Filament\Resources\VodDynamicGroups\Pages;

use App\Filament\Actions\DynamicGroupRuleActions;
use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\VodDynamicGroups\VodDynamicGroupResource;
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
 * VOD-only listing surface for DynamicGroup rows.
 *
 * Sister page to `SeriesDynamicGroups\Pages\ListSeriesDynamicGroups` -
 * the per-type "Dynamic Groups" sidebar listing that this refactor
 * splits out of the VOD Groups and Series Categories footer widgets.
 * Two Filament resources are backed by the same model so each one
 * surfaces under its parent content-type section (VOD Channels / Series).
 *
 * The query is already filtered to `type = 'vod'` by
 * `VodDynamicGroupResource::getEloquentQuery()`. No per-row type
 * switch needed here.
 *
 * The row's "view" action links to the shared
 * `DynamicGroupResource::getUrl('view', ...)` route - single detail
 * page keyed by record id, breadcrumb chains through the appropriate
 * per-type listing (see `Pages\ViewDynamicGroup`).
 *
 * Cache-specific tabs, actions, and columns are intentionally absent from
 * this navigation-only surface.
 */
class ListVodDynamicGroups extends ListRecords
{
    protected static string $resource = VodDynamicGroupResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __('Virtual categories built from TMDB lists, like Trending, Popular, In Theatres or a streaming service\'s catalog. Each group matches a playlist\'s movies by TMDB ID, refreshes daily and on playlist sync, and appears in that playlist\'s Xtream VOD categories.');
    }

    /**
     * Header action: a 'Create VOD Dynamic Group' slide-over with the same
     * rule fields, caching options and preview as the Playlist form's
     * `dynamic_groups_config` Repeater, plus a Playlist picker (prefilled with
     * the active sub-tab when not on 'All'). See DynamicGroupRuleActions.
     */
    protected function getHeaderActions(): array
    {
        return [
            DynamicGroupRuleActions::create('vod')
                ->visible(fn (): bool => VodDynamicGroupResource::shouldRegisterNavigation()),
        ];
    }

    /**
     * Per-playlist sub-tabs. Scope the Dynamic Groups view to a single
     * playlist. Keyed by `playlist_id` (matches `setupTabs()` in the
     * parent `VodGroupResource` / `CategoryResource` so the URL form
     * `?tab={id}` carries over cleanly when the View page's back
     * button comes back to this listing).
     */
    public function getTabs(): array
    {
        $base = static::getResource()::getEloquentQuery();

        // Build the per-playlist buckets via a single groupBy query (see
        // VodDynamicGroupResource::getEloquentQuery() - no withCount
        // attached so this combination is Postgres-safe). The "all" count
        // is derived from this same result instead of a second COUNT(*).
        $playlistCounts = (clone $base)
            ->selectRaw('playlist_id, count(*) as aggregate')
            ->groupBy('playlist_id')
            ->pluck('aggregate', 'playlist_id');

        $playlists = Playlist::query()
            ->whereIn('id', $playlistCounts->keys())
            ->orderBy('name')
            ->get(['id', 'name']);

        $tabs = [
            // `null` is the conventional "all" sentinel that
            // Filament's ListRecords treats as "no extra where".
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
            // withCount('channels') attaches the channels pivot count as
            // a subquery column the TextColumn::make('channels_count')
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
                ->withCount('channels')
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
                TextColumn::make('channels_count')
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
