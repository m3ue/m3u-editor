<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlaylistSyncStatusResource\Pages;
use App\Filament\Resources\PlaylistSyncStatusResource\RelationManagers;
use App\Models\PlaylistSyncStatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class PlaylistSyncStatusResource extends Resource
{
    protected static ?string $model = PlaylistSyncStatus::class;
    protected static ?string $label = 'Sync logs';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static ?string $parentResource = PlaylistResource::class;


    public static function getRecordTitle(?Model $record): string|null|Htmlable
    {
        return $record->created_at;
        // return $record->name;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Synced')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Playlist Name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('added_channels_count')
                    ->label('Added Channels')
                    ->counts('addedChannels')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('removed_channels_count')
                    ->label('Removed Channels')
                    ->counts('removedChannels')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('added_groups_count')
                    ->label('Added Groups')
                    ->counts('addedGroups')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('removed_groups_count')
                    ->label('Removed Groups')
                    ->counts('removedGroups')
                    ->toggleable()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->modalDescription('Delete this sync log?')
                    ->modalSubmitActionLabel('Delete now')
                    ->button()->hiddenLabel(),
                Tables\Actions\ViewAction::make()
                    ->url(
                        fn(Pages\ListPlaylistSyncStatuses $livewire, Model $record): string => static::$parentResource::getUrl('playlist-sync-statuses.view', [
                            'record' => $record,
                            'parent' => $livewire->parent,
                        ])
                    )->button()->hiddenLabel(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\LogsRelationManager::class,
        ];
    }

    public function getParentRelationshipKey(): string
    {
        return 'playlist_id';
    }
}
