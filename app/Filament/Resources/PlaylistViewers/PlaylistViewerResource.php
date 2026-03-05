<?php

namespace App\Filament\Resources\PlaylistViewers;

use App\Filament\Resources\PlaylistViewers\Pages\ListPlaylistViewers;
use App\Filament\Resources\PlaylistViewers\Pages\ViewPlaylistViewer;
use App\Filament\Resources\PlaylistViewers\RelationManagers\WatchProgressRelationManager;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistViewer;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlaylistViewerResource extends Resource
{
    protected static ?string $model = PlaylistViewer::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Viewers';

    protected static string|\UnitEnum|null $navigationGroup = 'Playlist';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHasMorph('viewerable', [
                Playlist::class,
                CustomPlaylist::class,
                MergedPlaylist::class,
                PlaylistAlias::class,
            ], fn (Builder $q) => $q->where('user_id', auth()->id()))
            ->withCount('watchProgress');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-check')
                    ->falseIcon('heroicon-o-user'),

                TextColumn::make('viewerable_type')
                    ->label('Playlist Type')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'App\\Models\\Playlist' => 'Playlist',
                        'App\\Models\\CustomPlaylist' => 'Custom Playlist',
                        'App\\Models\\MergedPlaylist' => 'Merged Playlist',
                        'App\\Models\\PlaylistAlias' => 'Playlist Alias',
                        default => class_basename($state),
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'App\\Models\\Playlist' => 'primary',
                        'App\\Models\\CustomPlaylist' => 'info',
                        'App\\Models\\MergedPlaylist' => 'warning',
                        'App\\Models\\PlaylistAlias' => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('viewerable.name')
                    ->label('Playlist')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('watch_progress_count')
                    ->label('Watch Records')
                    ->counts('watchProgress')
                    ->sortable(),

                TextColumn::make('ulid')
                    ->label('Viewer ID')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('viewerable_type')
                    ->label('Playlist Type')
                    ->options([
                        'App\\Models\\Playlist' => 'Playlist',
                        'App\\Models\\CustomPlaylist' => 'Custom Playlist',
                        'App\\Models\\MergedPlaylist' => 'Merged Playlist',
                        'App\\Models\\PlaylistAlias' => 'Playlist Alias',
                    ]),
            ])
            ->recordActions([
                \Filament\Actions\ViewAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()
                        ->disabled(fn ($records) => $records->contains('is_admin', true))
                        ->tooltip('Admin viewers cannot be deleted'),
                ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getRelations(): array
    {
        return [
            WatchProgressRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlaylistViewers::route('/'),
            'view' => ViewPlaylistViewer::route('/{record}'),
        ];
    }
}
