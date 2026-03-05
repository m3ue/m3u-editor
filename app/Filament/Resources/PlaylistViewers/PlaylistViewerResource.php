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
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
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

    protected static ?string $navigationLabel = 'Playlist Viewers';

    protected static string|\UnitEnum|null $navigationGroup = 'Playlist';

    protected static ?int $navigationSort = 5;

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
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->deferLoading()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
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
                        Playlist::class => 'Playlist',
                        CustomPlaylist::class => 'Custom Playlist',
                        MergedPlaylist::class => 'Merged Playlist',
                        PlaylistAlias::class => 'Playlist Alias',
                        default => class_basename($state),
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        Playlist::class => 'primary',
                        CustomPlaylist::class => 'info',
                        MergedPlaylist::class => 'warning',
                        PlaylistAlias::class => 'gray',
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
                        Playlist::class => 'Playlist',
                        CustomPlaylist::class => 'Custom Playlist',
                        MergedPlaylist::class => 'Merged Playlist',
                        PlaylistAlias::class => 'Playlist Alias',
                    ]),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->disabled(fn (PlaylistViewer $record) => $record->is_admin)
                    ->tooltip('Admin viewers cannot be deleted')
                    ->button()->hiddenLabel()->size('sm'),
                Action::make('clear_watch_progress')
                    ->label('Clear Watch Progress')
                    ->icon('heroicon-o-eye-slash')
                    ->color('warning')
                    ->action(function (PlaylistViewer $record) {
                        $record->watchProgress()->delete();
                    })
                    ->disabled(fn (PlaylistViewer $record) => $record->watchProgress()->count() === 0)
                    ->requiresConfirmation(true)
                    ->button()->hiddenLabel()->size('sm'),
                ViewAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->disabled(fn ($records) => $records->contains('is_admin', true))
                        ->tooltip('Admin viewers cannot be deleted'),
                    BulkAction::make('clear_watch_progress')
                        ->label('Clear Watch Progress')
                        ->icon('heroicon-o-eye-slash')
                        ->color('warning')
                        ->action(function ($records) {
                            $records->each(fn (PlaylistViewer $viewer) => $viewer->watchProgress()->delete());
                        })
                        ->requiresConfirmation(true)
                        ->disabled(fn ($records) => $records->every(fn ($record) => $record->watchProgress()->count() === 0)),
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
