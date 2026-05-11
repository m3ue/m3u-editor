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
use App\Pivots\PlaylistAuthPivot;
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
                    EditAction::make()
                        ->using(function (PlaylistAuth $record, array $data): PlaylistAuth {
                            unset($data['assigned_playlist']);
                            $record->update($data);

                            return $record;
                        }),
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
        return [
            Grid::make()
                ->schema([
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
                    Select::make('assigned_playlist')
                        ->label(__('Assigned to Playlist'))
                        ->options(function ($record) {
                            $options = [];
                            $userId = auth()->id();

                            // Collect IDs already assigned to other auths, keyed by model class
                            $assignedIds = [];
                            PlaylistAuthPivot::select('authenticatable_type', 'authenticatable_id', 'playlist_auth_id')
                                ->get()
                                ->each(function (PlaylistAuthPivot $pivot) use ($record, &$assignedIds) {
                                    // Always exclude assignments belonging to other auths
                                    if ($record && $pivot->playlist_auth_id === $record->id) {
                                        return;
                                    }
                                    $assignedIds[$pivot->authenticatable_type][] = $pivot->authenticatable_id;
                                });

                            // Add currently assigned playlist for edit so it appears as selected
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

                            $takenPlaylists = $assignedIds[Playlist::class] ?? [];
                            Playlist::where('user_id', $userId)
                                ->when($takenPlaylists, fn ($q) => $q->whereNotIn('id', $takenPlaylists))
                                ->get()
                                ->each(function (Playlist $playlist) use (&$options) {
                                    $key = Playlist::class.'|'.$playlist->id;
                                    $options[$key] ??= $playlist->name.' (Playlist)';
                                });

                            $takenCustom = $assignedIds[CustomPlaylist::class] ?? [];
                            CustomPlaylist::where('user_id', $userId)
                                ->when($takenCustom, fn ($q) => $q->whereNotIn('id', $takenCustom))
                                ->get()
                                ->each(function (CustomPlaylist $playlist) use (&$options) {
                                    $key = CustomPlaylist::class.'|'.$playlist->id;
                                    $options[$key] ??= $playlist->name.' (Custom Playlist)';
                                });

                            $takenMerged = $assignedIds[MergedPlaylist::class] ?? [];
                            MergedPlaylist::where('user_id', $userId)
                                ->when($takenMerged, fn ($q) => $q->whereNotIn('id', $takenMerged))
                                ->get()
                                ->each(function (MergedPlaylist $playlist) use (&$options) {
                                    $key = MergedPlaylist::class.'|'.$playlist->id;
                                    $options[$key] ??= $playlist->name.' (Merged Playlist)';
                                });

                            $takenAliases = $assignedIds[PlaylistAlias::class] ?? [];
                            PlaylistAlias::where('user_id', $userId)
                                ->when($takenAliases, fn ($q) => $q->whereNotIn('id', $takenAliases))
                                ->get()
                                ->each(function (PlaylistAlias $alias) use (&$options) {
                                    $key = PlaylistAlias::class.'|'.$alias->id;
                                    $options[$key] ??= $alias->name.' (Playlist Alias)';
                                });

                            return $options;
                        })
                        ->searchable()
                        ->nullable()
                        ->placeholder(__('Select a playlist or leave empty'))
                        ->helperText(__('Assign this auth to a specific playlist. Each auth can only be assigned to one playlist at a time.'))
                        ->afterStateHydrated(function ($component, $state, $record) {
                            if ($record && $record->isAssigned()) {
                                $assignedModel = $record->getAssignedModel();
                                if ($assignedModel) {
                                    $component->state(get_class($assignedModel).'|'.$assignedModel->id);
                                }
                            }
                        })
                        ->afterStateUpdated(function ($state, $record) {
                            if (! $record) {
                                return;
                            }

                            if ($state) {
                                [$modelClass, $modelId] = explode('|', $state, 2);
                                $model = $modelClass::find($modelId);

                                if ($model) {
                                    $record->assignTo($model);
                                }
                            } else {
                                $record->clearAssignment();
                            }
                        })
                        ->columnSpan(2),
                ])
                ->columns(2),
        ];
    }
}
