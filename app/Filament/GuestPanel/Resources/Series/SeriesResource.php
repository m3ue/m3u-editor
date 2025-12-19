<?php

namespace App\Filament\GuestPanel\Resources\Series;

use App\Facades\LogoFacade;
use App\Facades\PlaylistFacade;
use App\Filament\GuestPanel\Pages\Concerns\HasPlaylist;
use App\Filament\GuestPanel\Resources\Series\RelationManagers\EpisodesRelationManager;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\Series;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;

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
        ?\Illuminate\Database\Eloquent\Model $tenant = null,
        bool $shouldGuessMissingParameters = false
    ): string {
        $parameters['uuid'] = static::getCurrentUuid();

        // Default to 'index' if $name is not provided
        $routeName = static::getRouteBaseName($panel) . '.' . ($name ?? 'index');

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
                    ['playlist_id', $playlist?->id] // Only show series from the current playlist
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
                    ->getStateUsing(fn($record) => LogoFacade::getSeriesLogoUrl($record))
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Info')
                    ->description((fn($record) => Str::limit($record->plot, 200)))
                    ->wrap()
                    ->extraAttributes(['style' => 'min-width: 350px;'])
                    ->searchable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->orWhereRaw('LOWER(series.name) LIKE ?', ['%' . strtolower($search) . '%']);
                    }),
                Tables\Columns\TextColumn::make('seasons_count')
                    ->label('Seasons')
                    ->counts('seasons')
                    ->badge()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('episodes_count')
                    ->label('Episodes')
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
                    ->label('YouTube Trailer')
                    ->placeholder('No trailer ID set.')
                    ->url(fn($record): string => 'https://www.youtube.com/watch?v=' . $record->youtube_trailer)
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
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
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
            EpisodesRelationManager::class,
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
