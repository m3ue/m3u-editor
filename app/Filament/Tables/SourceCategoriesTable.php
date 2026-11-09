<?php

namespace App\Filament\Tables;

use App\Filament\Tables\Traits\FiltersBySelection;
use App\Filament\Tables\Traits\HasBouquetPickerColumns;
use App\Models\Category;
use App\Models\SourceCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SourceCategoriesTable
{
    use FiltersBySelection;
    use HasBouquetPickerColumns;

    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SourceCategory::query())
            ->modifyQueryUsing(function (Builder $query) use ($table): Builder {
                $arguments = $table->getArguments();

                // Scoped by a single playlist_id (playlist import preferences) or a
                // playlist_ids list (a merged-playlist alias picking across its sources).
                // An explicit empty list yields no rows rather than every row.
                if (array_key_exists('playlist_ids', $arguments)) {
                    $query->whereIn('playlist_id', (array) $arguments['playlist_ids'])->with('playlist');
                } elseif ($playlistId = $arguments['playlist_id'] ?? null) {
                    $query->where('playlist_id', $playlistId);
                }

                // Same approach as SourceGroupsTable: a correlated subquery keeps search and
                // sort on the real source_categories.name column.
                $customName = Category::query()
                    ->select('name')
                    ->whereColumn('categories.name_internal', 'source_categories.name')
                    ->whereColumn('categories.playlist_id', 'source_categories.playlist_id')
                    ->limit(1);

                return $query->select('source_categories.*')->selectSub($customName, 'display_name');
            })
            ->defaultSort('name', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Category Name'))
                    ->formatStateUsing(fn ($state, $record) => SourceCategory::labelWithProviderName($record->display_name, $state))
                    // Match the custom name as well as the source name, case-insensitively
                    // on PostgreSQL too, as SourceGroupsTable does.
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $term = '%'.mb_strtolower($search).'%';

                        return $query->where(function (Builder $query) use ($term): void {
                            $query->whereRaw('LOWER(source_categories.name) LIKE ?', [$term])
                                ->orWhereExists(function ($subQuery) use ($term): void {
                                    $subQuery->selectRaw('1')
                                        ->from('categories')
                                        ->whereColumn('categories.name_internal', 'source_categories.name')
                                        ->whereColumn('categories.playlist_id', 'source_categories.playlist_id')
                                        ->whereRaw('LOWER(categories.name) LIKE ?', [$term]);
                                });
                        });
                    })
                    ->sortable(),
                self::sourcePlaylistColumn($table),
                self::bouquetMembershipColumn($table),
            ])
            ->filters([
                TernaryFilter::make('enabled')
                    ->label(__('Categories'))
                    ->placeholder(__('All categories'))
                    ->trueLabel(__('Selected only'))
                    ->falseLabel(__('Unselected only'))
                    ->queries(
                        true: fn (Builder $query): Builder => self::whereSelected(
                            $query,
                            $table->getArguments()['selected'] ?? [],
                            selected: true,
                        ),
                        false: fn (Builder $query): Builder => self::whereSelected(
                            $query,
                            $table->getArguments()['selected'] ?? [],
                            selected: false,
                        ),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(15)
            ->headerActions([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    //
                ]),
            ]);
    }
}
