<?php

namespace App\Filament\Resources\VodGroups\Widgets;

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Models\DynamicGroup;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
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

    protected static ?string $heading = 'Dynamic Groups (TMDB)';

    protected int|string|array $columnSpan = 'full';

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
     * `config('feature.playlist_tmdb_dynamic_groups')` is enabled.
     */
    public static function canView(): bool
    {
        return (bool) config('feature.playlist_tmdb_dynamic_groups');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                DynamicGroup::query()
                    ->where('type', 'vod')
                    ->when(
                        $this->activePlaylistId !== null && $this->activePlaylistId !== '',
                        fn ($query) => $query->where('playlist_id', (int) $this->activePlaylistId),
                    )
                    ->when(
                        auth()->check() && ! auth()->user()->isAdmin(),
                        fn ($query) => $query->where('dynamic_groups.user_id', auth()->id()),
                    )
                    ->withCount('channels')
            )
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
            ->emptyStateDescription(__('Add Dynamic Groups in the Playlist form → Dynamic Groups (TMDB) section. Synced TMDB lists appear here with their current member counts.'))
            ->emptyStateIcon('heroicon-o-sparkles');
    }
}
