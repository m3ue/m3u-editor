<?php

namespace App\Filament\Tables;

use App\Models\SourceCategory;
use Filament\Tables\Columns\TextColumn;
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
                    ->label('Category Name')
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                //
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
                \Filament\Actions\BulkActionGroup::make([
                    //
                ]),
            ]);
    }
}
