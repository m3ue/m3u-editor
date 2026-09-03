<?php

namespace App\Filament\Tables;

use App\Models\CustomPlaylist;
use App\Models\CustomPlaylistGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomPlaylistGroupsTable
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

                return $customPlaylist->filterableGroupsQuery(($arguments['type'] ?? 'live') === 'vod');
            })
            // Without these the labels are derived from the CustomPlaylistGroup model name,
            // which reads as "No custom playlist groups" — wrongly implying the picker only
            // looks at groups created inside the custom playlist.
            ->modelLabel(__('Group'))
            ->pluralModelLabel(__('Groups'))
            ->emptyStateHeading(__('No groups found'))
            ->emptyStateDescription(__('No groups in this custom playlist or its source playlists.'))
            ->defaultSort('name', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Group Name'))
                    // Matched with LOWER(...) on both sides so it stays case-insensitive on
                    // PostgreSQL, whose LIKE is case-sensitive (unlike SQLite/MySQL).
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
