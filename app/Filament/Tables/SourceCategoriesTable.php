<?php

namespace App\Filament\Tables;

use App\Filament\Tables\Traits\FiltersBySelection;
use App\Models\SourceCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SourceCategoriesTable
{
    use FiltersBySelection;

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

                return $query;
            })
            ->defaultSort('name', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Category Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('playlist.name')
                    ->label(__('Source Playlist'))
                    ->visible(fn (): bool => count((array) ($table->getArguments()['playlist_ids'] ?? [])) > 1),
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
