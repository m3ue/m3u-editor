<?php

namespace App\Filament\Resources\VodGroups\Widgets;

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Models\DynamicGroup;
use App\Services\TmdbService;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Reactive;

/**
 * Footer widget on `ListVodGroups` showing the current user's vod-type
 * Dynamic Groups (Trending / Popular / In Theatres / etc.) with a single
 * `view` action linking through to the full read-only detail page.
 *
 * Strictly read-only - no mutation actions, no toolbar actions, no bulk
 * actions. Same "no edit/delete anywhere on this feature" invariant as the
 * underlying `DynamicGroupResource`. This is the deliberate deviation from
 * the `ArrIntegrationsWidget` template.
 *
 * The user-scoping rule (admin sees all, non-admin sees only their own)
 * mirrors `DynamicGroupResource::getEloquentQuery()` so the row counts and
 * filters here line up exactly with the link-through target - otherwise
 * CJ could click "view" on a widget row and get a 404 / forbidden.
 */
class DynamicGroupsWidget extends BaseWidget
{
    protected static bool $isLazy = false;

    /**
     * Heading moved into the wrapping `<x-filament::section>` in the
     * shared widget view. Setting this to null prevents
     * `TableWidget::getTableHeading()` from rendering a second duplicate
     * heading inside the table's internal container.
     */
    protected static ?string $heading = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * Custom view that wraps `{{ $this->table }}` in a collapsible
     * `<x-filament::section>` with an always-visible "?" info tooltip in
     * the header. Shared with the Series-side widget — single source of
     * truth for the collapsible + tooltip UX so the two widgets can't
     * drift apart.
     */
    protected string $view = 'filament.widgets.dynamic-groups-table-widget';

    /**
     * Bound from the parent page (`ListVodGroups::getWidgetData()`).
     * String-cast of the active tab key, which `setupTabs()` maps from
     * `$playlist->id`. `null` = no tab selected = show all (regression guard).
     *
     * Reactive: when the user clicks a different tab on `ListVodGroups`,
     * the parent's `wire:click="$set('activeTab', ...)"` updates this
     * prop via Filament's `Livewire::make(..., fn () => [...$this->getWidgetData()])`
     * param closure, which re-invokes on every parent re-render.
     *
     * The #[Reactive] attribute is required for that re-invoked value to
     * actually reach this property on a live tab click (not just on initial
     * mount / full page load) — without it, Livewire treats the value passed
     * at first mount as this component's own local state and never re-syncs
     * it from the parent's re-renders. Filament's own `InteractsWithPageTable`
     * trait (vendor/filament/filament/src/Widgets/Concerns/InteractsWithPageTable.php)
     * uses this exact attribute for its `activeTab` property — same mechanism.
     */
    #[Reactive]
    public ?string $activePlaylistId = null;

    /**
     * Experimental feature - only render when
     * `config('feature.playlist_tmdb_dynamic_groups')` is enabled, and only
     * when TMDB is actually configured. Without a TMDB API key,
     * `dynamic_group_items` can never be populated (SyncDynamicGroups is a
     * no-op), so showing the widget's "no dynamic groups yet" empty state
     * would misleadingly suggest the feature just needs a rule added rather
     * than a TMDB key.
     */
    public static function canView(): bool
    {
        return (bool) config('feature.playlist_tmdb_dynamic_groups')
            && app(TmdbService::class)->isConfigured();
    }

    /**
     * Shared scoping for both the table's own query and the collapse-state
     * check (`hasDynamicGroups()`) — so a widget that says "I have nothing
     * to show" is reading from exactly the same row set as the table that
     * would render those rows. Mirrors `DynamicGroupResource::getEloquentQuery()`:
     * admin sees all, non-admin sees only their own; per-tab playlist scope
     * when `$activePlaylistId` is set; type filter (vod here, series on the
     * Series-side widget). Does NOT include `->withCount()` because the
     * collapse-state check only needs `->exists()`.
     */
    protected function baseQuery(): Builder
    {
        return DynamicGroup::query()
            ->where('type', 'vod')
            ->when(
                $this->activePlaylistId !== null && $this->activePlaylistId !== '',
                fn ($query) => $query->where('playlist_id', (int) $this->activePlaylistId),
            )
            ->when(
                auth()->check() && ! auth()->user()->isAdmin(),
                fn ($query) => $query->where('dynamic_groups.user_id', auth()->id()),
            );
    }

    /**
     * Drives the widget's default collapsed state — collapsed when there's
     * nothing to show for the current user + active playlist tab, expanded
     * otherwise. Recomputed fresh on every render (CJ confirmed: no
     * persisted collapse state across page loads), so a user who adds new
     * dynamic groups sees the widget auto-expand on their next sync without
     * needing to click it open.
     */
    public function hasDynamicGroups(): bool
    {
        return $this->baseQuery()->exists();
    }

    /**
     * Single source of truth for the copy shown both as the table's
     * empty-state description and as the always-visible header tooltip
     * — if these ever diverge, this test catches it:
     *   tests/Feature/DynamicGroupsWidgetTest.php -> `getDynamicGroupsHelpText()`.
     */
    public function getDynamicGroupsHelpText(): string
    {
        return __('Add Dynamic Groups in the Playlist form → Processing → Dynamic Groups (TMDB) section. Synced TMDB lists appear here with their current member counts.');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseQuery()->withCount('channels'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('playlist.name')
                    ->label(__('Playlist'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source')
                    ->formatStateUsing(fn (DynamicGroup $record): string => DynamicGroupResource::sourceLabelFor($record->type)[$record->source] ?? $record->source)
                    ->badge(),
                IconColumn::make('enabled')
                    ->boolean(),
                TextColumn::make('channels_count')
                    ->label(__('Items'))
                    ->numeric(),
                TextColumn::make('last_synced_at')
                    ->since()
                    ->placeholder(__('Never'))
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('View'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (DynamicGroup $record): string => DynamicGroupResource::getUrl('view', ['record' => $record]))
                    ->button()
                    ->size('sm')
                    ->hiddenLabel(),
            ], RecordActionsPosition::BeforeCells)
            ->emptyStateHeading(__('No Dynamic Groups configured'))
            ->emptyStateDescription($this->getDynamicGroupsHelpText())
            ->emptyStateIcon('heroicon-o-sparkles');
    }
}
