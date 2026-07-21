<?php

namespace App\Filament\Tables;

use App\Models\Group;
use App\Models\SourceGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class SourceGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SourceGroup::query())
            ->modifyQueryUsing(function (Builder $query) use ($table): Builder {
                $arguments = $table->getArguments();
                $type = $arguments['type'] ?? null;
                $playlistId = $arguments['playlist_id'] ?? null;

                if ($playlistId) {
                    $query->where('source_groups.playlist_id', $playlistId);
                }
                if ($type) {
                    $query->where('source_groups.type', $type);
                }

                // Resolve the imported group's custom name (if any) via a correlated
                // subquery rather than a join. A join would make the `name` column
                // ambiguous for search/sort (both tables have one) and could multiply
                // rows; the subquery keeps search/sort on the real source_groups.name
                // column, which Filament searches case-insensitively across databases.
                $customName = Group::query()
                    ->select('name')
                    ->whereColumn('groups.name_internal', 'source_groups.name')
                    ->whereColumn('groups.playlist_id', 'source_groups.playlist_id')
                    ->when($type, fn (Builder $subQuery) => $subQuery->where('groups.type', $type))
                    ->whereNull('groups.deleted_at')
                    ->limit(1);

                return $query->select('source_groups.*')->selectSub($customName, 'display_name');
            })
            ->defaultSort('name', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Group Name'))
                    ->formatStateUsing(fn ($state, $record) => filled($record->display_name) ? $record->display_name : $state)
                    // Match the displayed (custom) name as well as the source name. Uses
                    // LOWER(...) on both sides so it stays case-insensitive on PostgreSQL,
                    // whose LIKE is case-sensitive (unlike SQLite/MySQL).
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $term = '%'.mb_strtolower($search).'%';

                        return $query->where(function (Builder $query) use ($term): void {
                            $query->whereRaw('LOWER(source_groups.name) LIKE ?', [$term])
                                ->orWhereExists(function ($subQuery) use ($term): void {
                                    $subQuery->selectRaw('1')
                                        ->from('groups')
                                        ->whereColumn('groups.name_internal', 'source_groups.name')
                                        ->whereColumn('groups.playlist_id', 'source_groups.playlist_id')
                                        ->whereColumn('groups.type', 'source_groups.type')
                                        ->whereNull('groups.deleted_at')
                                        ->whereRaw('LOWER(groups.name) LIKE ?', [$term]);
                                });
                        });
                    })
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('enabled')
                    ->label(__('Groups'))
                    ->placeholder(__('All groups'))
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
                ->when($selectedIds, fn (Builder $query): Builder => $query->whereNotIn('source_groups.id', $selectedIds))
                ->when($selectedNames, fn (Builder $query): Builder => $query->whereNotIn('source_groups.name', $selectedNames));
        }

        return $query->where(function (Builder $query) use ($selectedIds, $selectedNames): void {
            if (! empty($selectedIds)) {
                $query->whereIn('source_groups.id', $selectedIds);
            }

            if (! empty($selectedNames)) {
                $method = empty($selectedIds) ? 'whereIn' : 'orWhereIn';
                $query->{$method}('source_groups.name', $selectedNames);
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
