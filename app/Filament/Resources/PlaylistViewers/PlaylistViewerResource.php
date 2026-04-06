<?php

namespace App\Filament\Resources\PlaylistViewers;

use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\PlaylistViewers\Pages\ListPlaylistViewers;
use App\Filament\Resources\PlaylistViewers\Pages\ViewPlaylistViewer;
use App\Filament\Resources\PlaylistViewers\RelationManagers\WatchProgressRelationManager;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistViewer;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
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

class PlaylistViewerResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;

    protected static ?string $model = PlaylistViewer::class;

    public static function getNavigationLabel(): string
    {
        return __('Playlist Viewers');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Playlist');
    }

    public static function getModelLabel(): string
    {
        return __('Playlist Viewer');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Playlist Viewers');
    }

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
            ->reorderableColumns()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->deferLoading()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_admin')
                    ->label(__('Admin'))
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-check')
                    ->falseIcon('heroicon-o-user'),

                TextColumn::make('viewerable_type')
                    ->label(__('Playlist Type'))
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
                    ->label(__('Playlist'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('watch_progress_count')
                    ->label(__('Watch Records'))
                    ->counts('watchProgress')
                    ->sortable(),

                TextColumn::make('ulid')
                    ->label(__('Viewer ID'))
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('viewerable_type')
                    ->label(__('Playlist Type'))
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
                    ->tooltip(__('Admin viewers cannot be deleted'))
                    ->button()->hiddenLabel()->size('sm'),
                Action::make('clear_watch_progress')
                    ->label(__('Clear Watch Progress'))
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
                        ->tooltip(__('Admin viewers cannot be deleted')),
                    BulkAction::make('clear_watch_progress')
                        ->label(__('Clear Watch Progress'))
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
