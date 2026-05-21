<?php

namespace App\Filament\Tables;

use App\Models\SourceGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

class SourceGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SourceGroup::query())
            ->modifyQueryUsing(function (Builder $query) use ($table): Builder {
                $arguments = $table->getArguments();
                $type = $arguments['type'] ?? null;

                if ($playlistId = $arguments['playlist_id'] ?? null) {
                    $query->where('source_groups.playlist_id', $playlistId);
                }
                if ($type) {
                    $query->where('source_groups.type', $type);
                }

                // Pull the imported group's custom name (if it exists) so the table
                // shows the user-facing name rather than the raw source name.
                return $query
                    ->leftJoin('groups', function (JoinClause $join) use ($type): void {
                        $join->on('groups.name_internal', '=', 'source_groups.name')
                            ->on('groups.playlist_id', '=', 'source_groups.playlist_id')
                            ->whereNull('groups.deleted_at');
                        if ($type) {
                            $join->where('groups.type', '=', $type);
                        }
                    })
                    ->select('source_groups.*')
                    ->selectRaw('COALESCE(groups.name, source_groups.name) as display_name');
            })
            ->defaultSort('display_name', 'asc')
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('Group Name'))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $query) use ($search): void {
                            $query->where('source_groups.name', 'like', "%{$search}%")
                                ->orWhere('groups.name', 'like', "%{$search}%");
                        });
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw("COALESCE(groups.name, source_groups.name) {$direction}");
                    }),
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
