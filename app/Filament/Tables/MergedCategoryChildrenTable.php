<?php

namespace App\Filament\Tables;

use App\Models\Category;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Candidate child categories for a merged category: every assignable (non-merged)
 * category in the same playlist. Backs the ModalTableSelect on the Merged Category
 * resource so children can be picked in bulk.
 *
 * Table arguments: playlist_id (int), merged_category_id (int|null).
 */
class MergedCategoryChildrenTable
{
    public static function configure(Table $table): Table
    {
        $arguments = $table->getArguments();
        $playlistId = $arguments['playlist_id'] ?? null;
        $mergedCategoryId = $arguments['merged_category_id'] ?? null;

        return $table
            ->query(fn (): Builder => Category::query()->assignableTarget()->with('parent'))
            ->modifyQueryUsing(function (Builder $query) use ($playlistId): Builder {
                return $query
                    ->where('categories.user_id', auth()->id())
                    ->when($playlistId, fn (Builder $q) => $q->where('categories.playlist_id', $playlistId));
            })
            ->defaultSort('name', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Category'))
                    ->formatStateUsing(fn ($state, Category $record) => filled($state) ? $state : $record->name_internal)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $term = '%'.mb_strtolower($search).'%';

                        return $query->where(function (Builder $query) use ($term): void {
                            $query->whereRaw('LOWER(categories.name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(categories.name_internal) LIKE ?', [$term]);
                        });
                    })
                    ->sortable(),
                TextColumn::make('series_count')
                    ->label(__('Series'))
                    ->counts('series')
                    ->badge()
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->label(__('Currently merged into'))
                    ->placeholder(__('Not merged'))
                    ->badge()
                    ->color(fn (Category $record) => $record->parent_id === $mergedCategoryId ? 'success' : 'warning'),
            ])
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
