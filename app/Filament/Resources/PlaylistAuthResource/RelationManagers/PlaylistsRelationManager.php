<?php

namespace App\Filament\Resources\PlaylistAuthResource\RelationManagers;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Tables\Columns\PivotNameColumn;
use App\Tables\Columns\PlaylistUrlColumn;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PlaylistsRelationManager extends RelationManager
{
    protected static string $relationship = 'playlists';

    protected static ?string $title = 'Assigned to';

    protected $listeners = ['refreshRelation' => '$refresh'];
    
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
                PivotNameColumn::make('playlist_name')
                    ->label('Playlist'),
                PlaylistUrlColumn::make('playlist_url')
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
                    ->label('Assign Auth to Playlist')
                    ->modalHeading('Assign Auth to Playlist')
                    ->using(function (array $data): \Illuminate\Database\Eloquent\Model {
                        $playlistAuth = $this->ownerRecord;
                        
                        // Get the model to assign to
                        $modelClass = $data['authenticatable_type'];
                        $modelId = $data['authenticatable_id'];
                        $model = $modelClass::findOrFail($modelId);
                        
                        // Use the assignTo method to ensure single assignment
                        $playlistAuth->assignTo($model);
                        
                        // Return the created pivot record for Filament
                        return $playlistAuth->assignedPlaylist;
                    }),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('Remove auth from Playlist')
                    ->modalHeading('Remove Auth')
                    ->modalDescription('Remove auth from Playlist?')
                    ->modalSubmitActionLabel('Remove')
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->button()
                    ->hiddenLabel(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Remove auth')
                        ->modalHeading('Remove Auth')
                        ->modalDescription('Remove auth from selected Playlist?')
                        ->modalSubmitActionLabel('Remove')
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle'),
                ]),
            ]);
    }
}
