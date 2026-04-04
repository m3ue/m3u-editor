<?php

namespace App\Filament\Resources\PlaylistAuths;

use App\Filament\Resources\PlaylistAuthResource\Pages;
use App\Filament\Resources\PlaylistAuthResource\RelationManagers;
use App\Filament\Resources\PlaylistAuths\Pages\ListPlaylistAuths;
use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Services\DateFormatService;
use App\Traits\HasUserFiltering;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PlaylistAuthResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = PlaylistAuth::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Playlist';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'username'];
    }

    public static function getNavigationSort(): ?int
    {
        return 6;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            // ->modifyQueryUsing(function (Builder $query) {
            //     $query->with('playlists');
            // })
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('username')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                // Tables\Columns\TextColumn::make('password')
                //     ->searchable()
                //     ->sortable()
                //     ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('assigned_model_name')
                    ->label('Assigned To')
                    ->toggleable(),
                ToggleColumn::make('enabled')
                    ->toggleable()
                    ->tooltip('Toggle auth status')
                    ->sortable(),
                TextColumn::make('max_connections')
                    ->label('Max Conns')
                    ->formatStateUsing(fn ($state) => $state === 0 ? "\u{221E}" : $state)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('enable_proxy')
                    ->label('Proxy')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        null => 'Inherit',
                        true, 1 => 'On',
                        false, 0 => 'Off',
                        default => 'Inherit',
                    })
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('created_at')
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
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
            'index' => ListPlaylistAuths::route('/'),
            // 'create' => Pages\CreatePlaylistAuth::route('/create'),
            // 'edit' => Pages\EditPlaylistAuth::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        $schema = [
            TextInput::make('name')
                ->label('Name')
                ->required()
                ->helperText('Used to reference this auth internally.')
                ->columnSpan(1),
            Toggle::make('enabled')
                ->label('Enabled')
                ->columnSpan(1)
                ->inline(false)
                ->default(true),
            TextInput::make('username')
                ->label('Username')
                ->required()
                ->rules(function ($record) {
                    return [
                        Rule::unique('playlist_auths', 'username')->ignore($record?->id),
                        Rule::unique('playlist_aliases', 'username'),
                    ];
                })
                ->columnSpan(1),
            TextInput::make('password')
                ->label('Password')
                ->password()
                ->required()
                ->revealable()
                ->columnSpan(1),
            DateTimePicker::make('expires_at')
                ->label('Expiration (date & time)')
                ->seconds(false)
                ->native(false)
                ->helperText('If set, this account will stop working at that exact time.')
                ->nullable()
                ->columnSpan(2),
            TextInput::make('max_connections')
                ->label('Max Concurrent Connections')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->helperText('Maximum concurrent proxy streams for this auth. Set to 0 for unlimited.')
                ->columnSpan(1),
            Select::make('enable_proxy')
                ->label('Proxy Override')
                ->options([
                    'on' => 'Force Proxy On',
                    'off' => 'Force Proxy Off',
                ])
                ->placeholder('Inherit from Playlist')
                ->default(null)
                ->nullable()
                ->afterStateHydrated(function ($component, $record) {
                    if (! $record) {
                        return;
                    }
                    $value = $record->getRawOriginal('enable_proxy');
                    if (is_null($value)) {
                        $component->state(null);
                    } else {
                        $component->state($value ? 'on' : 'off');
                    }
                })
                ->dehydrateStateUsing(function ($state) {
                    if ($state === null || $state === '') {
                        return null;
                    }

                    return $state === 'on';
                })
                ->helperText('Override the assigned playlist\'s proxy setting for this auth.')
                ->columnSpan(1),
            Placeholder::make('proxy_notice')
                ->content('Connection limits only apply when proxy is active. Non-proxied streams are direct redirects and cannot be tracked or limited.')
                ->columnSpan(2),
        ];

        return [
            Grid::make()
                ->hiddenOn(['edit']) // hide this field on the edit form
                ->schema($schema)
                ->columns(2),
            Grid::make()
                ->hiddenOn(['create']) // hide this field on the create form
                ->schema([
                    ...$schema,
                    Select::make('assigned_playlist')
                        ->label('Assigned to Playlist')
                        ->options(function ($record) {
                            $options = [];

                            // Add currently assigned playlist if any
                            if ($record && $record->isAssigned()) {
                                $assignedModel = $record->getAssignedModel();
                                if ($assignedModel) {
                                    $type = match (get_class($assignedModel)) {
                                        Playlist::class => 'Playlist',
                                        CustomPlaylist::class => 'Custom Playlist',
                                        MergedPlaylist::class => 'Merged Playlist',
                                        PlaylistAlias::class => 'Playlist Alias',
                                        default => 'Unknown'
                                    };
                                    $key = get_class($assignedModel).'|'.$assignedModel->id;
                                    $options[$key] = $assignedModel->name." ({$type}) - Currently Assigned";
                                }
                            }

                            // Add all available playlists for current user
                            $userId = auth()->id();

                            // Standard Playlists
                            $playlists = Playlist::where('user_id', $userId)->get();
                            foreach ($playlists as $playlist) {
                                $key = Playlist::class.'|'.$playlist->id;
                                if (! isset($options[$key])) {
                                    $options[$key] = $playlist->name.' (Playlist)';
                                }
                            }

                            // Custom Playlists
                            $customPlaylists = CustomPlaylist::where('user_id', $userId)->get();
                            foreach ($customPlaylists as $playlist) {
                                $key = CustomPlaylist::class.'|'.$playlist->id;
                                if (! isset($options[$key])) {
                                    $options[$key] = $playlist->name.' (Custom Playlist)';
                                }
                            }

                            // Merged Playlists
                            $mergedPlaylists = MergedPlaylist::where('user_id', $userId)->get();
                            foreach ($mergedPlaylists as $playlist) {
                                $key = MergedPlaylist::class.'|'.$playlist->id;
                                if (! isset($options[$key])) {
                                    $options[$key] = $playlist->name.' (Merged Playlist)';
                                }
                            }

                            // Playlist Aliases
                            $aliases = PlaylistAlias::where('user_id', $userId)->get();
                            foreach ($aliases as $alias) {
                                $key = PlaylistAlias::class.'|'.$alias->id;
                                if (! isset($options[$key])) {
                                    $options[$key] = $alias->name.' (Playlist Alias)';
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
                                    return get_class($assignedModel).'|'.$assignedModel->id;
                                }
                            }

                            return null;
                        })
                        ->afterStateHydrated(function ($component, $state, $record) {
                            if ($record && $record->isAssigned()) {
                                $assignedModel = $record->getAssignedModel();
                                if ($assignedModel) {
                                    $value = get_class($assignedModel).'|'.$assignedModel->id;
                                    $component->state($value);
                                }
                            }
                        })
                        ->afterStateUpdated(function ($state, $record) {
                            if (! $record) {
                                return;
                            }

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
                ])
                ->columns(2),
        ];
    }
}
