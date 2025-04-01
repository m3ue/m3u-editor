<?php

namespace App\Filament\Resources\PlaylistAuthResource\RelationManagers;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Tables\Columns\PlaylistAuthNameColumn;
use App\Tables\Columns\PlaylistAuthUrlColumn;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PlaylistsRelationManager extends RelationManager
{
    protected static string $relationship = 'playlists';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('authenticatable_type')
                    ->required()
                    ->label('Type of Playlist')
                    ->live()
                    ->helperText('The type of playlist to assign this auth to.')
                    ->options([
                        Playlist::class => 'Playlist',
                        CustomPlaylist::class => 'Custom Playlist',
                        MergedPlaylist::class => 'Merged Playlist',
                    ])
                    ->default(Playlist::class) // Default to Playlists if no type is selected
                    ->searchable(),

                Forms\Components\Select::make('authenticatable_id')
                    ->required()
                    ->label('Playlist')
                    ->helperText('Select the playlist you would like to assign this auth to.')
                    ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                    ->hidden(fn($get) => $get('authenticatable_type') !== Playlist::class)
                    ->searchable(),
                Forms\Components\Select::make('authenticatable_id')
                    ->required()
                    ->label('Custom Playlist')
                    ->helperText('Select the playlist you would like to assign this auth to.')
                    ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                    ->hidden(fn($get) => $get('authenticatable_type') !== CustomPlaylist::class)
                    ->searchable(),
                Forms\Components\Select::make('authenticatable_id')
                    ->required()
                    ->label('Merged Playlist')
                    ->helperText('Select the playlist you would like to assign this auth to.')
                    ->options(MergedPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                    ->hidden(fn($get) => $get('authenticatable_type') !== MergedPlaylist::class)
                    ->searchable(),

                Forms\Components\TextInput::make('playlist_auth_id')
                    ->label('Playlist Auth ID')
                    ->default($this->ownerRecord->id)
                    ->hidden()
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                PlaylistAuthNameColumn::make('playlist_name')
                    ->label('Playlist'),
                PlaylistAuthUrlColumn::make('playlist_url')
                    ->label('Playlist URL')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Auth to Playlist')
                    ->modalHeading('Add Auth to Playlist'),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('Remove auth from Playlist')
                    ->modalHeading('Remove Auth')
                    ->modalDescription('Remove auth from Playlist?')
                    ->modalSubmitActionLabel('Remove')
                    ->button()
                    ->hiddenLabel(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Remove auth')
                        ->modalHeading('Remove Auth')
                        ->modalDescription('Remove auth from selected Playlist?')
                        ->modalSubmitActionLabel('Remove'),
                ]),
            ]);
    }
}
