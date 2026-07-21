<?php

namespace App\Filament\Tables;

use App\Models\SourceCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SourceCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SourceCategory::query())
            ->modifyQueryUsing(function (Builder $query) use ($table): Builder {
                $arguments = $table->getArguments();

                if ($playlistId = $arguments['playlist_id'] ?? null) {
                    $query->where('playlist_id', $playlistId);
                }

                return $query;
            })
            ->defaultSort('name', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Category Name'))
                    ->searchable()
                    ->sortable(),
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

    private static function whereSelected(Builder $query, mixed $selectedValues, bool $selected): Builder
    {
        [$selectedIds, $selectedNames] = self::selectedIdsAndNames($selectedValues);

        if (empty($selectedIds) && empty($selectedNames)) {
            return $selected ? $query->whereRaw('1 = 0') : $query;
        }

        if (! $selected) {
            return $query
                ->when($selectedIds, fn (Builder $query): Builder => $query->whereNotIn('source_categories.id', $selectedIds))
                ->when($selectedNames, fn (Builder $query): Builder => $query->whereNotIn('source_categories.name', $selectedNames));
        }

        return $query->where(function (Builder $query) use ($selectedIds, $selectedNames): void {
            if (! empty($selectedIds)) {
                $query->whereIn('source_categories.id', $selectedIds);
            }

            if (! empty($selectedNames)) {
                $method = empty($selectedIds) ? 'whereIn' : 'orWhereIn';
                $query->{$method}('source_categories.name', $selectedNames);
            }
        });
    }

    /**
     * @return array{0: list<int>, 1: list<string>}
     */
    private static function selectedIdsAndNames(mixed $selectedValues): array
    {
        if (! is_array($selectedValues)) {
            return [[], []];
        }

        $selectedIds = [];
        $selectedNames = [];

        foreach ($selectedValues as $value) {
            if (is_numeric($value)) {
                $selectedIds[] = (int) $value;

                continue;
            }

            if (is_string($value) && $value !== '') {
                $selectedNames[] = $value;
            }
        }

        return [
            array_values(array_unique($selectedIds)),
            array_values(array_unique($selectedNames)),
        ];
    }
}
