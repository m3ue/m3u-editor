<?php

namespace App\Filament\Tables;

use App\Models\CustomPlaylist;
use App\Models\CustomPlaylistGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomPlaylistCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(function () use ($table): Builder {
                $arguments = $table->getArguments();
                $customPlaylist = CustomPlaylist::find($arguments['custom_playlist_id'] ?? null);

                if (! $customPlaylist) {
                    return CustomPlaylistGroup::none();
                }

                return $customPlaylist->filterableCategoriesQuery();
            })
            ->modelLabel(__('Category'))
            ->pluralModelLabel(__('Categories'))
            ->emptyStateHeading(__('No categories found'))
            ->emptyStateDescription(__('No categories in this custom playlist or its source playlists.'))
            ->defaultSort('name', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Category Name'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereRaw(
                        'LOWER(name) LIKE ?',
                        ['%'.mb_strtolower($search).'%']
                    ))
                    ->sortable(),
                IconColumn::make('in_bouquet')
                    ->label(__('In bouquet'))
                    ->visible(fn (): bool => ! empty($table->getArguments()['bouquet_group_names'] ?? []))
                    ->state(fn ($record): bool => in_array($record->name, $table->getArguments()['bouquet_group_names'] ?? [], true))
                    ->boolean(),
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
                BulkActionGroup::make([
                    //
                ]),
            ]);
    }
}
