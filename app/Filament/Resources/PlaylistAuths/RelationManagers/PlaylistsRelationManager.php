<?php

namespace App\Filament\Resources\PlaylistAuths\RelationManagers;

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Tables\Columns\PivotNameColumn;
use App\Tables\Columns\PlaylistUrlColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PlaylistsRelationManager extends RelationManager
{
    protected static string $relationship = 'playlists';

    protected static ?string $title = 'Assigned to';

    protected $listeners = ['refreshRelation' => '$refresh'];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('authenticatable_type')
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

                Select::make('authenticatable_id')
                    ->required()
                    ->label('Playlist')
                    ->helperText('Select the playlist you would like to assign this auth to.')
                    ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                    ->hidden(fn ($get) => $get('authenticatable_type') !== Playlist::class)
                    ->searchable(),
                Select::make('authenticatable_id')
                    ->required()
                    ->label('Custom Playlist')
                    ->helperText('Select the playlist you would like to assign this auth to.')
                    ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                    ->hidden(fn ($get) => $get('authenticatable_type') !== CustomPlaylist::class)
                    ->searchable(),
                Select::make('authenticatable_id')
                    ->required()
                    ->label('Merged Playlist')
                    ->helperText('Select the playlist you would like to assign this auth to.')
                    ->options(MergedPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                    ->hidden(fn ($get) => $get('authenticatable_type') !== MergedPlaylist::class)
                    ->searchable(),

                TextInput::make('playlist_auth_id')
                    ->label('Playlist Auth ID')
                    ->default($this->ownerRecord->id)
                    ->hidden(),
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
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Assign Auth to Playlist')
                    ->modalHeading('Assign Auth to Playlist')
                    ->using(function (array $data): Model {
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
            ->recordActions([
                DeleteAction::make()
                    ->label('Remove auth from Playlist')
                    ->modalHeading('Remove Auth')
                    ->modalDescription('Remove auth from Playlist?')
                    ->modalSubmitActionLabel('Remove')
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->button()
                    ->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
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
