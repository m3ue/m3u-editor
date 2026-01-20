<?php

namespace App\Filament\Tables;

use App\Models\Category;
use App\Models\Episode;
use App\Models\Series;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NetworkEpisodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Episode::query())
            ->modifyQueryUsing(function (Builder $query) use ($table): Builder {
                $arguments = $table->getArguments();

                $query->with(['series', 'season', 'playlist']);

                if ($playlistId = $arguments['playlist_id'] ?? null) {
                    $query->where('playlist_id', $playlistId);
                }

                // Only show enabled episodes
                $query->where('enabled', true);

                return $query;
            })
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->defaultGroup('series.name')
            ->defaultSort('series.name', 'asc')
            ->defaultSort('episode_num', 'asc')
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(15)
            ->columns([
                ImageColumn::make('info.movie_image')
                    ->label('Cover')
                    ->height(60)
                    ->width(40)
                    ->getStateUsing(function ($record) {
                        $info = $record->info ?? [];

                        return $info['movie_image'] ?? $info['cover_big'] ?? null;
                    })
                    ->defaultImageUrl('/images/placeholder-episode.png'),

                TextColumn::make('title')
                    ->label('Episode Title')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('series.name')
                    ->label('Series')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('series.category.name')
                    ->label('Category')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('season')
                    ->label('Season #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('episode_num')
                    ->label('Ep #')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('info.duration')
                    ->label('Duration')
                    ->getStateUsing(function ($record) {
                        $info = $record->info ?? [];

                        return $info['duration'] ?? null;
                    }),
            ])
            ->filters([
                SelectFilter::make('series')
                    ->label('Series')
                    ->attribute('series_id')
                    ->options(fn () => Series::where('playlist_id', $table->getArguments()['playlist_id'] ?? null)->pluck('name', 'id'))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('category')
                    ->label('Category')
                    ->options(fn () => Category::where('playlist_id', $table->getArguments()['playlist_id'] ?? null)->pluck('name', 'id'))
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        return $query->whereHas('series', function (Builder $query) use ($data) {
                            $query->where('category_id', $data['value']);
                        });
                    })
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
                \Filament\Actions\BulkActionGroup::make([
                    //
                ]),
            ]);
    }
}
