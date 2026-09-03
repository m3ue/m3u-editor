<?php

namespace App\Filament\Resources\Categories\Widgets;

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Models\DynamicGroup;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Footer widget on `ListCategories` showing the current user's series-type
 * Dynamic Groups (Trending / Popular / Top Genre / By TV Network / etc.).
 *
 * Parallel to `VodGroups\Widgets\DynamicGroupsWidget` - same shape, same
 * read-only invariant. Built because CJ's own test data (the "Netflix"
 * Dynamic Group) is series-type, so building only the VOD half would
 * leave the identical gap on the Series page.
 *
 * The user-scoping rule (admin sees all, non-admin sees only their own)
 * mirrors `DynamicGroupResource::getEloquentQuery()` so the widget and
 * its link-through target agree on visibility.
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
                    ->where('type', 'series')
                    ->when(
                        auth()->check() && ! auth()->user()->isAdmin(),
                        fn ($query) => $query->where('dynamic_groups.user_id', auth()->id()),
                    )
                    ->withCount('series')
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
                TextColumn::make('series_count')
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
