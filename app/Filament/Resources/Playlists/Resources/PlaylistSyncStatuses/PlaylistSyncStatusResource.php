<?php

namespace App\Filament\Resources\Playlists\Resources\PlaylistSyncStatuses;

use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\PlaylistSyncStatus;
use Filament\Actions;
use Filament\Infolists;
use Filament\Resources\ParentResourceRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class PlaylistSyncStatusResource extends Resource
{
    protected static ?string $model = PlaylistSyncStatus::class;

    protected static ?string $parentResource = PlaylistResource::class;

    public static function getParentResourceRegistration(): ?ParentResourceRegistration
    {
        return PlaylistResource::asParent()
            ->relationship('syncStatuses');
    }

    protected static ?string $recordTitleAttribute = 'created_at';

    protected static ?string $label = 'Sync Status';

    protected static ?string $pluralLabel = 'Sync Statuses';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Since these are log entries, we typically don't want to create/edit them
                // This form is mainly for display purposes if needed
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Sync Information')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label('Playlist name'),
                        Infolists\Components\TextEntry::make('sync_stats.time_rounded')
                            ->label('Sync time')
                            ->helperText('Total time to sync playlist (in seconds)'),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Synced at')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('sync_stats.status')
                            ->label('Status')
                            ->default('success')
                            ->badge()
                            ->color(function ($state) {
                                return match ($state) {
                                    'success' => 'success',
                                    'canceled' => 'warning',
                                    default => 'info',
                                };
                            }),
                        Infolists\Components\TextEntry::make('sync_stats.message')
                            ->label('Message')
                            ->columnSpan(2)
                            ->default('N/A'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('created_at')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Synced')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('name')
                    ->label('Playlist Name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sync_stats.status')
                    ->label('Status')
                    ->searchable()
                    ->badge()
                    ->color(function ($state) {
                        return match ($state) {
                            'success' => 'success',
                            'canceled' => 'warning',
                            default => 'info',
                        };
                    })
                    ->default('success')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('added_channels_count')
                    ->label('Added Channels')
                    ->counts('addedChannels')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('removed_channels_count')
                    ->label('Removed Channels')
                    ->counts('removedChannels')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('added_groups_count')
                    ->label('Added Groups')
                    ->counts('addedGroups')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('removed_groups_count')
                    ->label('Removed Groups')
                    ->counts('removedGroups')
                    ->toggleable()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Actions\DeleteAction::make()
                    ->modalDescription('Delete this sync log?')
                    ->modalSubmitActionLabel('Delete now')
                    ->button()->hiddenLabel()->size('sm'),
                Actions\ViewAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\LogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlaylistSyncStatuses::route('/'),
            'create' => Pages\CreatePlaylistSyncStatus::route('/create'),
            'view' => Pages\ViewPlaylistSyncStatus::route('/{record}'),
            'edit' => Pages\EditPlaylistSyncStatus::route('/{record}/edit'),
        ];
    }
}
