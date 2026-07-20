<?php

namespace App\Filament\Resources\PlaylistAuths;

use App\Filament\Actions\GeneratePasswordAction;
use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\PlaylistAuthResource\Pages;
use App\Filament\Resources\PlaylistAuthResource\RelationManagers;
use App\Filament\Resources\PlaylistAuths\Pages\ListPlaylistAuths;
use App\Models\CustomPlaylist;
use App\Models\MediaServerIntegration;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\StreamProfile;
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
use Filament\Forms\Components\Radio;
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
        $proxySection = auth()->user()->canUseProxy()
            ? Section::make(__('Proxy Access'))
                ->description(__('Advertise the m3u proxy to compatible clients (e.g. the TV app) authenticating with this user, allowing them to enable proxied playback and select a transcoding profile.'))
                ->compact()
                ->hidden(fn () => ! (auth()->user()?->canUseProxy() ?? false))
                ->schema([
                    Toggle::make('proxy_enabled')
                        ->label(__('Enable Proxy'))
                        ->helperText(__('Allow this user\'s clients to see and use the proxy, including per-device transcoding profile selection.'))
                        ->default(false)
                        ->live()
                        ->columnSpan(2),
                    Radio::make('proxy_profile_access')
                        ->label(__('Transcoding Profile Access'))
                        ->options([
                            'all' => __('All profiles'),
                            'selected' => __('Selected profiles'),
                            'none' => __('None (direct proxy only)'),
                        ])
                        ->default('all')
                        ->live()
                        ->required()
                        ->helperText(__('Which transcoding profiles this user may apply when streaming through the proxy.'))
                        ->visible(fn ($get) => $get('proxy_enabled'))
                        ->columnSpan(2),
                    Select::make('proxy_stream_profile_ids')
                        ->label(__('Allowed Profiles'))
                        ->multiple()
                        ->required()
                        ->options(fn () => StreamProfile::where('user_id', auth()->id())
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray())
                        ->helperText(__('Only these profiles will be offered to (and accepted from) this user.'))
                        ->hidden(fn ($get) => ! $get('proxy_enabled') || $get('proxy_profile_access') !== 'selected')
                        ->columnSpan(2),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed(fn ($record) => ! ($record?->proxy_enabled))
            : null;

        $dvrSection = Section::make(__('DVR Access'))
            ->description(__('Control whether this user can schedule and manage DVR recordings.'))
            ->compact()
            ->hidden(fn () => ! (config('dvr.dvr_enabled', true) && config('proxy.proxy_integration_enabled', true)))
            ->schema([
                Toggle::make('dvr_enabled')
                    ->label(__('Enable DVR'))
                    ->helperText(__('Allow this user to view and schedule recordings when the assigned playlist also has DVR enabled.'))
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
                    ->helperText(__('Override the maximum number of simultaneous recordings for this user. Leave empty to use the playlist\'s DVR setting.'))
                    ->visible(fn ($get) => $get('dvr_enabled'))
                    ->columnSpan(1),
                TextInput::make('dvr_storage_quota_gb')
                    ->label(__('Storage Quota (GB)'))
                    ->numeric()
                    ->minValue(1)
                    ->nullable()
                    ->placeholder(__('No quota'))
                    ->helperText(__('Maximum total disk space this user\'s recordings may use. Leave empty for unlimited.'))
                    ->visible(fn ($get) => $get('dvr_enabled'))
                    ->columnSpan(1),
            ])
            ->columns(2)
            ->collapsible()
            ->collapsed(fn ($record) => ! ($record?->dvr_enabled));

        $requestsSection = Section::make(__('Content Requests'))
            ->description(__('Control whether this user can browse and request content from Sonarr and Radarr.'))
            ->compact()
            ->schema([
                Toggle::make('request_enabled')
                    ->label(__('Enable Content Requests'))
                    ->helperText(__('Allow this user to request content when the assigned playlist and ARR integration also allow user requests.'))
                    ->default(false)
                    ->live()
                    ->columnSpan(2),
                Toggle::make('auto_approve_requests')
                    ->label(__('Auto-approve Content Requests'))
                    ->helperText(__('Disable to require admin approval before media is added to Sonarr/Radarr.'))
                    ->hintIcon(
                        'heroicon-m-question-mark-circle',
                        tooltip: __('When enabled, content requests from this user are sent directly to Sonarr/Radarr. When disabled, requests are held for admin approval in the Download Queue.')
                    )
                    ->visible(fn ($get) => $get('request_enabled'))
                    ->default(false)
                    ->columnSpan(2),
            ])
            ->columns(2)
            ->collapsible()
            ->collapsed(fn ($record) => ! ($record?->request_enabled));

        $aiostreamsSection = Section::make(__('AIOStreams Access'))
            ->description(__('Control whether this user can access AIOStreams content from the assigned playlist.'))
            ->compact()
            ->hidden(fn () => MediaServerIntegration::where('user_id', auth()->id())
                ->where('type', 'aiostreams')
                ->where('enabled', true)
                ->doesntExist()
            )
            ->schema([
                Toggle::make('aiostreams_enabled')
                    ->label(__('Enable AIOStreams'))
                    ->helperText(__('Allow this user to browse and stream AIOStreams catalogs when the assigned playlist also has AIOStreams enabled.'))
                    ->default(false)
                    ->live()
                    ->columnSpan(2),
            ])
            ->columns(2)
            ->collapsible()
            ->collapsed(fn ($record) => ! ($record?->aiostreams_enabled));

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
                    Grid::make()
                        ->columns(3)
                        ->schema([
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
                                ->suffixAction(GeneratePasswordAction::make())
                                ->columnSpan(1),
                            DateTimePicker::make('expires_at')
                                ->label(__('Expiration (date & time)'))
                                ->seconds(false)
                                ->native(false)
                                ->hintIcon(
                                    'heroicon-m-question-mark-circle',
                                    tooltip: __('If set, this account will stop working at that exact time.')
                                )
                                ->nullable()
                                ->columnSpan(1),
                        ]),
                    Grid::make()
                        ->columns(2)
                        ->schema([
                            TextInput::make('max_connections')
                                ->label(__('Max Connections'))
                                ->hintIcon(
                                    'heroicon-m-question-mark-circle',
                                    tooltip: __('Leave blank for unlimited. Only enforced when the assigned playlist has the proxy enabled.')
                                )
                                ->helperText(__('Maximum number of concurrent streams for this auth user.'))
                                ->numeric()
                                ->minValue(1)
                                ->nullable(),
                            Toggle::make('stop_oldest_on_limit')
                                ->label(__('Stop Oldest Stream on Limit'))
                                ->inline(false)
                                ->hintIcon(
                                    'heroicon-m-question-mark-circle',
                                    tooltip: __('Leave unchecked to use the global setting. Only applies when the assigned playlist has the proxy enabled.')
                                )
                                ->helperText(__('When at max connections, stop the oldest stream to allow the new one. When off, use the global setting.'))
                                ->nullable(),
                        ]),
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

                            $takenPlaylists = $assignedIds[(new Playlist)->getMorphClass()] ?? [];
                            Playlist::where('user_id', $userId)
                                ->when($takenPlaylists, fn ($q) => $q->whereNotIn('id', $takenPlaylists))
                                ->get()
                                ->each(function (Playlist $playlist) use (&$options) {
                                    $key = Playlist::class.'|'.$playlist->id;
                                    $options[$key] ??= $playlist->name.' (Playlist)';
                                });

                            $takenCustom = $assignedIds[(new CustomPlaylist)->getMorphClass()] ?? [];
                            CustomPlaylist::where('user_id', $userId)
                                ->when($takenCustom, fn ($q) => $q->whereNotIn('id', $takenCustom))
                                ->get()
                                ->each(function (CustomPlaylist $playlist) use (&$options) {
                                    $key = CustomPlaylist::class.'|'.$playlist->id;
                                    $options[$key] ??= $playlist->name.' (Custom Playlist)';
                                });

                            $takenMerged = $assignedIds[(new MergedPlaylist)->getMorphClass()] ?? [];
                            MergedPlaylist::where('user_id', $userId)
                                ->when($takenMerged, fn ($q) => $q->whereNotIn('id', $takenMerged))
                                ->get()
                                ->each(function (MergedPlaylist $playlist) use (&$options) {
                                    $key = MergedPlaylist::class.'|'.$playlist->id;
                                    $options[$key] ??= $playlist->name.' (Merged Playlist)';
                                });

                            $takenAliases = $assignedIds[(new PlaylistAlias)->getMorphClass()] ?? [];
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
            $proxySection,
            $requestsSection,
            $dvrSection,
            $aiostreamsSection,
        ];
    }
}
