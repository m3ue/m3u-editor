<?php

namespace App\Filament\Tables;

use App\Models\Group;
use App\Models\SourceGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                BulkActionGroup::make([
                    //
                ]),
            ]);
    }
}
