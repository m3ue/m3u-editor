<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlaylistAuthResource\Pages;
use App\Filament\Resources\PlaylistAuthResource\RelationManagers;
use App\Models\PlaylistAuth;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlaylistAuthResource extends Resource
{
    protected static ?string $model = PlaylistAuth::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'username'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Playlist';

    public static function getNavigationSort(): ?int
    {
        return 5;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            // ->modifyQueryUsing(function (Builder $query) {
            //     $query->with('playlists');
            // })
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('username')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                // Tables\Columns\TextColumn::make('password')
                //     ->searchable()
                //     ->sortable()
                //     ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('assigned_model_name')
                    ->label('Assigned To')
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('enabled')
                    ->toggleable()
                    ->tooltip('Toggle auth status')
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
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm'),
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
            // RelationManagers\PlaylistsRelationManager::class, // Removed - auth assignment is now handled in playlist forms
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlaylistAuths::route('/'),
            // 'create' => Pages\CreatePlaylistAuth::route('/create'),
            'edit' => Pages\EditPlaylistAuth::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        $schema = [
            Forms\Components\TextInput::make('name')
                ->label('Name')
                ->required()
                ->helperText('Used to reference this auth internally.')
                ->columnSpan(1),
            Forms\Components\Toggle::make('enabled')
                ->label('Enabled')
                ->columnSpan(1)
                ->inline(false)
                ->default(true),
            Forms\Components\TextInput::make('username')
                ->label('Username')
                ->required()
                ->columnSpan(1),
            Forms\Components\TextInput::make('password')
                ->label('Password')
                ->password()
                ->required()
                ->revealable()
                ->columnSpan(1),
        ];

        $editSchema = array_merge($schema, [
            Forms\Components\Select::make('assigned_playlist')
                ->label('Assigned to Playlist')
                ->options(function ($record) {
                    $options = [];

                    // Add currently assigned playlist if any
                    if ($record && $record->isAssigned()) {
                        $assignedModel = $record->getAssignedModel();
                        if ($assignedModel) {
                            $type = match (get_class($assignedModel)) {
                                \App\Models\Playlist::class => 'Playlist',
                                \App\Models\CustomPlaylist::class => 'Custom Playlist',
                                \App\Models\MergedPlaylist::class => 'Merged Playlist',
                                default => 'Unknown'
                            };
                            $key = get_class($assignedModel) . '|' . $assignedModel->id;
                            $options[$key] = $assignedModel->name . " ({$type}) - Currently Assigned";
                        }
                    }

                    // Add all available playlists for current user
                    $userId = \Illuminate\Support\Facades\Auth::id();

                    // Standard Playlists
                    $playlists = \App\Models\Playlist::where('user_id', $userId)->get();
                    foreach ($playlists as $playlist) {
                        $key = \App\Models\Playlist::class . '|' . $playlist->id;
                        if (!isset($options[$key])) {
                            $options[$key] = $playlist->name . ' (Playlist)';
                        }
                    }

                    // Custom Playlists
                    $customPlaylists = \App\Models\CustomPlaylist::where('user_id', $userId)->get();
                    foreach ($customPlaylists as $playlist) {
                        $key = \App\Models\CustomPlaylist::class . '|' . $playlist->id;
                        if (!isset($options[$key])) {
                            $options[$key] = $playlist->name . ' (Custom Playlist)';
                        }
                    }

                    // Merged Playlists
                    $mergedPlaylists = \App\Models\MergedPlaylist::where('user_id', $userId)->get();
                    foreach ($mergedPlaylists as $playlist) {
                        $key = \App\Models\MergedPlaylist::class . '|' . $playlist->id;
                        if (!isset($options[$key])) {
                            $options[$key] = $playlist->name . ' (Merged Playlist)';
                        }
                    }

                    return $options;
                })
                ->searchable()
                ->nullable()
                ->placeholder('Select a playlist or leave empty')
                ->helperText('Assign this auth to a specific playlist. Each auth can only be assigned to one playlist at a time.')
                ->default(function ($record) {
                    if ($record && $record->isAssigned()) {
                        $assignedModel = $record->getAssignedModel();
                        if ($assignedModel) {
                            return get_class($assignedModel) . '|' . $assignedModel->id;
                        }
                    }
                    return null;
                })
                ->afterStateHydrated(function ($component, $state, $record) {
                    if ($record && $record->isAssigned()) {
                        $assignedModel = $record->getAssignedModel();
                        if ($assignedModel) {
                            $value = get_class($assignedModel) . '|' . $assignedModel->id;
                            $component->state($value);
                        }
                    }
                })
                ->afterStateUpdated(function ($state, $record) {
                    if (!$record) return;

                    if ($state) {
                        // Parse the selection (format: "ModelClass|ID")
                        [$modelClass, $modelId] = explode('|', $state, 2);
                        $model = $modelClass::find($modelId);

                        if ($model) {
                            $record->assignTo($model);
                        }
                    } else {
                        // Clear assignment
                        $record->clearAssignment();
                    }
                })
                ->dehydrated(false) // Don't save this field directly
                ->columnSpan(2),
        ]);
        return [
            Forms\Components\Grid::make()
                ->hiddenOn(['edit']) // hide this field on the edit form
                ->schema($schema)
                ->columns(2),
            Forms\Components\Section::make('Playlist Auth')
                ->hiddenOn(['create']) // hide this field on the create form
                ->schema($editSchema)
                ->columns(2),
        ];
    }
}
