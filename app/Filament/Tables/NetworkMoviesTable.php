<?php

namespace App\Filament\Tables;

use App\Models\Channel;
use App\Models\Group;
use App\Services\LogoCacheService;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NetworkMoviesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Channel::query())
            ->modifyQueryUsing(function (Builder $query) use ($table): Builder {
                $arguments = $table->getArguments();

                $query->with(['playlist', 'group']);

                if ($playlistId = $arguments['playlist_id'] ?? null) {
                    $query->where('playlist_id', $playlistId);
                }

                // Only show VOD channels (movies)
                $query->where('is_vod', true);

                return $query;
            })
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(15)
            ->defaultSort('title', 'asc')
            ->columns([
                ImageColumn::make('logo')
                    ->label(__('Cover'))
                    ->height(60)
                    ->width(40)
                    ->defaultImageUrl(fn (): string => LogoCacheService::getPlaceholderUrl('poster')),

                TextColumn::make('title')
                    ->label(__('Movie Title'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('group')
                    ->label(__('Group'))
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('info.duration')
                    ->label(__('Duration'))
                    ->getStateUsing(function ($record) {
                        $info = $record->info ?? [];

                        return $info['duration'] ?? null;
                    }),

                TextColumn::make('info.rating')
                    ->label(__('Rating'))
                    ->getStateUsing(function ($record) {
                        $info = $record->info ?? [];

                        return $info['rating'] ?? null;
                    }),
            ])
            ->filters([
                SelectFilter::make('group')
                    ->label(__('Group'))
                    ->attribute('group_id')
                    ->options(fn () => Group::where('playlist_id', $table->getArguments()['playlist_id'] ?? null)->pluck('name', 'id'))
                    ->searchable()
                    ->preload(),
            ])
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(25)
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
