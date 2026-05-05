<?php

namespace App\Filament\GuestPanel\Resources\Series;

use App\Facades\LogoFacade;
use App\Facades\PlaylistFacade;
use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Series;
use App\Models\SourceCategory;
use App\Services\DateFormatService;
use Filament\Actions;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SeriesResource extends Resource
{
    use HasPlaylist;

    protected static ?string $model = Series::class;

    protected static ?string $slug = 'series';

    public static function getNavigationBadge(): ?string
    {
        $playlist = PlaylistFacade::resolvePlaylistByUuid(static::getCurrentUuid());
        if ($playlist) {
            return (string) $playlist->series()->where('enabled', true)->count();
        }

        return '';
    }

    public static function getUrl(
        ?string $name = null,
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?Model $tenant = null,
        bool $shouldGuessMissingParameters = false,
        ?string $configuration = null
    ): string {
        $parameters['uuid'] = static::getCurrentUuid();

        // Default to 'index' if $name is not provided
        $routeName = static::getRouteBaseName($panel).'.'.($name ?? 'index');

        return route($routeName, $parameters, $isAbsolute);
    }

    public static function getEloquentQuery(): Builder
    {
        $playlist = PlaylistFacade::resolvePlaylistByUuid(static::getCurrentUuid());
        if ($playlist instanceof Playlist) {
            return parent::getEloquentQuery()
                ->with('playlist') // Eager load the playlist relationship
                ->where([
                    ['enabled', true], // Only show enabled series
                    ['playlist_id', $playlist?->id], // Only show series from the current playlist
                ]);
        }
        if ($playlist instanceof CustomPlaylist) {
            return parent::getEloquentQuery()
                ->with('customPlaylists') // Eager load the customPlaylists relationship
                ->whereHas('customPlaylists', function ($query) use ($playlist) {
                    $query->where('custom_playlists.id', $playlist->id);
                })
                ->where('enabled', true); // Only show enabled series
        }
        if ($playlist instanceof PlaylistAlias) {
            // Alias backed by a standard playlist
            if ($playlist->playlist_id) {
                $query = parent::getEloquentQuery()
                    ->with('playlist')
                    ->where('enabled', true)
                    ->where('playlist_id', $playlist->playlist_id);

                // Apply series category filter if configured on the alias
                $allowedCategoryNames = $playlist->getAllowedCategoryNames();
                if (! empty($allowedCategoryNames)) {
                    $allowedSourceCategoryIds = SourceCategory::where('playlist_id', $playlist->playlist_id)
                        ->whereIn('name', $allowedCategoryNames)
                        ->pluck('source_category_id')
                        ->all();
                    $query->whereIn('source_category_id', $allowedSourceCategoryIds);
                }

                return $query;
            }

            // Alias backed by a custom playlist
            if ($playlist->custom_playlist_id) {
                return parent::getEloquentQuery()
                    ->with('customPlaylists')
                    ->whereHas('customPlaylists', function ($query) use ($playlist) {
                        $query->where('custom_playlists.id', $playlist->custom_playlist_id);
                    })
                    ->where('enabled', true);
            }
        }

        return parent::getEloquentQuery();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover')
                    ->width(80)
                    ->height(120)
                    ->checkFileExistence(false)
                    ->getStateUsing(fn ($record) => LogoFacade::getSeriesLogoUrl($record))
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Info'))
                    ->description((fn ($record) => Str::limit($record->plot, 200)))
                    ->wrap()
                    ->extraAttributes(['style' => 'min-width: 350px;'])
                    ->searchable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->orWhereRaw('LOWER(series.name) LIKE ?', ['%'.strtolower($search).'%']);
                    }),
                Tables\Columns\TextColumn::make('seasons_count')
                    ->label(__('Seasons'))
                    ->counts('seasons')
                    ->badge()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('episodes_count')
                    ->label(__('Episodes'))
                    ->counts('episodes')
                    ->badge()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('category.name')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('genre')
                    ->searchable(),
                Tables\Columns\TextColumn::make('youtube_trailer')
                    ->label(__('YouTube Trailer'))
                    ->placeholder(__('No trailer ID set.'))
                    ->url(fn ($record): string => 'https://www.youtube.com/watch?v='.$record->youtube_trailer)
                    ->openUrlInNewTab()
                    ->icon('heroicon-s-play'),
                Tables\Columns\TextColumn::make('release_date')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rating')
                    ->badge()
                    ->color('success')
                    ->icon('heroicon-m-star')
                    ->searchable(),
                Tables\Columns\TextColumn::make('rating_5based')
                    ->numeric()
                    ->badge()
                    ->color('success')
                    ->icon('heroicon-m-star')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\ViewAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeries::route('/'),
            'view' => Pages\ViewSeries::route('/{record}'),
        ];
    }
}
