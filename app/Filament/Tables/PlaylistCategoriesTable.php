<?php

namespace App\Filament\Tables;

use App\Filament\Tables\Traits\FiltersBySelection;
use App\Models\Category;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Picker table for a standard playlist's own series categories - the categories
 * its clients see, genre categories included - keyed by category id.
 */
class PlaylistCategoriesTable
{
    use FiltersBySelection;

    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Category::query())
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where('categories.playlist_id', (int) ($table->getArguments()['playlist_id'] ?? 0))
                ->withCount(['series as enabled_series_count' => fn (Builder $query) => $query->where('enabled', true)]))
            ->defaultSort('sort_order', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Category Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('enabled_series_count')
                    ->label(__('Enabled series'))
                    ->sortable(),
                IconColumn::make('in_bouquet')
                    ->label(__('In bouquet'))
                    ->visible(fn (): bool => ! empty($table->getArguments()['bouquet_group_ids'] ?? []))
                    ->state(fn (Category $record): bool => in_array($record->id, $table->getArguments()['bouquet_group_ids'] ?? [], false))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('selected')
                    ->label(__('Categories'))
                    ->placeholder(__('All categories'))
                    ->trueLabel(__('Selected only'))
                    ->falseLabel(__('Unselected only'))
                    ->queries(
                        true: fn (Builder $query): Builder => self::whereSelected($query, $table->getArguments()['selected'] ?? [], selected: true),
                        false: fn (Builder $query): Builder => self::whereSelected($query, $table->getArguments()['selected'] ?? [], selected: false),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(15);
    }
}
