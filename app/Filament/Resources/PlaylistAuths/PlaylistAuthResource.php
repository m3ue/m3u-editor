<?php

namespace App\Filament\Resources\PlaylistAuths;

use App\Filament\Concerns\HasCopilotSupport;
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
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PlaylistAuthResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
    use HasUserFiltering;

    protected static ?string $model = PlaylistAuth::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('Playlist');
    }

    public static function getModelLabel(): string
    {
        return __('Playlist Auth');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Playlist Auths');
    }

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
                    ->label(__('Assigned To'))
                    ->toggleable(),
                ToggleColumn::make('enabled')
                    ->toggleable()
                    ->tooltip(__('Toggle auth status'))
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
                ->label(__('Name'))
                ->required()
                ->helperText(__('Used to reference this auth internally.'))
                ->columnSpan(1),
            Toggle::make('enabled')
                ->label(__('Enabled'))
                ->columnSpan(1)
                ->inline(false)
                ->default(true),
            TextInput::make('username')
                ->label(__('Username'))
                ->required()
                ->rules(function ($record) {
                    return [
                        Rule::unique('playlist_auths', 'username')->ignore($record?->id),
                        Rule::unique('playlist_aliases', 'username'),
                    ];
                })
                ->columnSpan(1),
            TextInput::make('password')
                ->label(__('Password'))
                ->password()
                ->required()
                ->revealable()
                ->columnSpan(1),
            DateTimePicker::make('expires_at')
                ->label(__('Expiration (date & time)'))
                ->seconds(false)
                ->native(false)
                ->helperText(__('If set, this account will stop working at that exact time.'))
                ->nullable()
                ->columnSpan(2),
        ];

        $dvrSection = Section::make(__('DVR Access'))
            ->description(__('Control whether this guest can schedule and manage DVR recordings.'))
            ->schema([
                Toggle::make('dvr_enabled')
                    ->label(__('Enable DVR'))
                    ->helperText(__('Allow this guest to view and schedule recordings via the public playlist viewer.'))
                    ->default(false)
                    ->live()
                    ->columnSpan(2),
                TextInput::make('dvr_max_concurrent_recordings')
                    ->label(__('Max Concurrent Recordings'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(99)
                    ->nullable()
                    ->placeholder(__('Inherit from DVR settings'))
                    ->helperText(__('Override the maximum number of simultaneous recordings for this guest. Leave empty to use the playlist\'s DVR setting.'))
                    ->visible(fn ($get) => $get('dvr_enabled'))
                    ->columnSpan(1),
                TextInput::make('dvr_storage_quota_gb')
                    ->label(__('Storage Quota (GB)'))
                    ->numeric()
                    ->minValue(1)
                    ->nullable()
                    ->placeholder(__('No quota'))
                    ->helperText(__('Maximum total disk space this guest\'s recordings may use. Leave empty for unlimited.'))
                    ->visible(fn ($get) => $get('dvr_enabled'))
                    ->columnSpan(1),
            ])
            ->columns(2)
            ->collapsible()
            ->collapsed(fn ($record) => ! ($record?->dvr_enabled));

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
                        ->label(__('Assigned to Playlist'))
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
                        ->placeholder(__('Select a playlist or leave empty'))
                        ->helperText(__('Assign this auth to a specific playlist. Each auth can only be assigned to one playlist at a time.'))
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
            $dvrSection,
        ];
    }
}
