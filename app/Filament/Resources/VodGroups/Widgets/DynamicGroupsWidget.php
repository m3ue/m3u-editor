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
    protected static ?string $heading = 'Dynamic Groups (TMDB)';

    protected int|string|array $columnSpan = 'full';

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
