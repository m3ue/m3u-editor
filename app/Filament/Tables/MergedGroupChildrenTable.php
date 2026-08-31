<?php

namespace App\Filament\Tables;

use App\Models\Group;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Candidate child groups for a merged group: every assignable (non-merged) group of
 * the same type in the same playlist. Backs the ModalTableSelect on the Merged Group
 * resource so children can be picked in bulk.
 *
 * Table arguments: playlist_id (int), type ('live'|'vod'), merged_group_id (int|null).
 */
class MergedGroupChildrenTable
{
    public static function configure(Table $table): Table
    {
        $arguments = $table->getArguments();
        $playlistId = $arguments['playlist_id'] ?? null;
        $type = $arguments['type'] ?? 'live';
        $mergedGroupId = $arguments['merged_group_id'] ?? null;

        return $table
            ->query(fn (): Builder => Group::query()->assignableTarget()->with('parent'))
            ->modifyQueryUsing(function (Builder $query) use ($playlistId, $type): Builder {
                return $query
                    ->where('groups.user_id', auth()->id())
                    ->where('groups.type', $type)
                    ->when($playlistId, fn (Builder $q) => $q->where('groups.playlist_id', $playlistId));
            })
            ->defaultSort('name', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Group'))
                    ->formatStateUsing(fn ($state, Group $record) => filled($state) ? $state : $record->name_internal)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $term = '%'.mb_strtolower($search).'%';

                        return $query->where(function (Builder $query) use ($term): void {
                            $query->whereRaw('LOWER(groups.name) LIKE ?', [$term])
                                ->orWhereRaw('LOWER(groups.name_internal) LIKE ?', [$term]);
                        });
                    })
                    ->sortable(),
                TextColumn::make('live_channels_count')
                    ->label(__('Channels'))
                    ->counts('channels')
                    ->badge()
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->label(__('Currently merged into'))
                    ->placeholder(__('Not merged'))
                    ->badge()
                    ->color(fn (Group $record) => $record->parent_id === $mergedGroupId ? 'success' : 'warning'),
            ])
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
