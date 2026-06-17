<?php

namespace App\Filament\Resources\ArrIntegrations;

use App\Filament\Resources\ArrIntegrations\Pages\CreateArrIntegration;
use App\Filament\Resources\ArrIntegrations\Pages\EditArrIntegration;
use App\Filament\Resources\ArrIntegrations\Pages\ListArrIntegrations;
use App\Models\ArrIntegration;
use App\Services\Arr\ArrService;
use App\Traits\HasUserFiltering;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ArrIntegrationResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = ArrIntegration::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('Sonarr & Radarr');
    }

    public static function getModelLabel(): string
    {
        return __('Sonarr/Radarr');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sonarr & Radarr');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Integrations');
    }

    protected static ?int $navigationSort = 105;

    /**
     * Restrict the resource to integrations owned by the current user.
     */
    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->canUseIntegrations();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', Auth::id());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Connection'))
                    ->description(__('Connect to your Sonarr or Radarr server.'))
                    ->collapsible()
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label(__('Display Name'))
                                ->placeholder(fn (Get $get): string => $get('type') === 'radarr' ? 'e.g., Radarr - 4K Movies' : 'e.g., Sonarr - 1080p TV')
                                ->required()
                                ->maxLength(255),

                            Select::make('type')
                                ->label(__('Type'))
                                ->options([
                                    'sonarr' => 'Sonarr',
                                    'radarr' => 'Radarr',
                                ])
                                ->required()
                                ->live()
                                ->native(false)
                                ->disabledOn('edit'),
                        ]),

                        Grid::make(2)->schema([
                            Select::make('playlist_id')
                                ->label(__('Playlist'))
                                ->relationship(
                                    'playlist',
                                    'name',
                                    fn (Builder $query): Builder => $query->where('user_id', Auth::id())
                                )
                                ->required()
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->helperText(__('Content added via this integration will be requested in the context of this playlist.')),

                            TextInput::make('url')
                                ->label(__('Server URL'))
                                ->placeholder('http://192.168.1.42:8989')
                                ->required()
                                ->url()
                                ->maxLength(255),
                        ]),

                        TextInput::make('api_key')
                            ->label(__('API Key'))
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrateStateUsing(fn ($state, ?ArrIntegration $record) => filled($state) ? $state : $record?->api_key)
                            ->helperText(fn (string $operation): string => $operation === 'edit'
                                ? 'Leave blank to keep the existing API key.'
                                : 'Found in Sonarr/Radarr under Settings → General → API Key.'),

                        Actions::make(self::getDiscoverActions())
                            ->fullWidth(),

                        // Hidden fields populated by the Discover action
                        Hidden::make('quality_profile_id'),
                        Hidden::make('quality_profile_name'),
                        Hidden::make('root_folder_path'),

                        Grid::make(2)->schema([
                            Select::make('quality_profile_id')
                                ->label(__('Quality Profile'))
                                ->options(function (Get $get) {
                                    $raw = $get('quality_profiles_options') ?? '[]';
                                    $profiles = json_decode($raw, true) ?: [];

                                    return collect($profiles)
                                        ->mapWithKeys(fn (array $p) => [$p['id'] => $p['name']])
                                        ->all();
                                })
                                ->helperText(__('Discovered from the server — click "Test Connection & Discover" above to populate.'))
                                ->visible(fn (Get $get): bool => filled($get('quality_profiles_options')))
                                ->native(false),

                            Select::make('root_folder_path')
                                ->label(__('Root Folder'))
                                ->options(function (Get $get) {
                                    $raw = $get('root_folders_options') ?? '[]';
                                    $folders = json_decode($raw, true) ?: [];

                                    return collect($folders)
                                        ->mapWithKeys(fn (array $f) => [$f['path'] => $f['path']])
                                        ->all();
                                })
                                ->helperText(__('Discovered from the server — click "Test Connection & Discover" above to populate.'))
                                ->visible(fn (Get $get): bool => filled($get('root_folders_options')))
                                ->native(false),
                        ]),

                        // Stash the discovered lists as hidden JSON so they're available
                        // to the visible Selects above (Filament option callbacks need access).
                        // These are form-only state — never written to the DB.
                        Hidden::make('quality_profiles_options')
                            ->dehydrated(false),
                        Hidden::make('root_folders_options')
                            ->dehydrated(false),
                    ]),

                Section::make(__('Options'))
                    ->schema([
                        Toggle::make('enabled')
                            ->label(__('Enabled'))
                            ->helperText(__('Disable to pause use of this integration without deleting it.'))
                            ->default(true),

                        Toggle::make('guest_enabled')
                            ->label(__('Allow Guest Requests'))
                            ->helperText(__('Allow guests on this playlist to request content via this integration.'))
                            ->default(false),
                    ]),

                Section::make(__('Status'))
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('last_test_at')
                                ->label(__('Last Tested'))
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(fn ($state) => $state ? $state->diffForHumans() : 'Never'),

                            TextInput::make('quality_profile_name')
                                ->label(__('Active Quality Profile'))
                                ->disabled()
                                ->dehydrated(false)
                                ->placeholder('—'),
                        ]),
                    ])
                    ->visible(fn (string $operation): bool => $operation === 'edit'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'sonarr' ? 'info' : 'purple')
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),

                TextColumn::make('playlist.name')
                    ->label(__('Playlist'))
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('url')
                    ->label(__('URL'))
                    ->searchable()
                    ->copyable()
                    ->limit(40),

                TextColumn::make('quality_profile_name')
                    ->label(__('Quality Profile'))
                    ->placeholder('—')
                    ->toggleable(),

                IconColumn::make('enabled')
                    ->label(__('Enabled'))
                    ->boolean()
                    ->sortable(),

                IconColumn::make('guest_enabled')
                    ->label(__('Guest'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('last_test_at')
                    ->label(__('Last Tested'))
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'sonarr' => 'Sonarr',
                        'radarr' => 'Radarr',
                    ]),
            ])
            ->actions([
                ActionGroup::make([
                    EditAction::make(),
                    Action::make('test')
                        ->label(__('Test Connection'))
                        ->icon('heroicon-o-signal')
                        ->action(function (ArrIntegration $record) {
                            $service = ArrService::make($record);
                            $result = $service->testConnection();

                            if ($result['ok']) {
                                $record->forceFill(['last_test_at' => now()])->save();

                                Notification::make()
                                    ->success()
                                    ->title(__('Connection Successful'))
                                    ->body(__('Connected to :name (v:version)', [
                                        'name' => $record->name,
                                        'version' => $result['version'] ?? 'unknown',
                                    ]))
                                    ->send();
                            } else {
                                Notification::make()
                                    ->danger()
                                    ->title(__('Connection Failed'))
                                    ->body($result['error'] ?? 'Unknown error')
                                    ->send();
                            }
                        }),
                    Action::make('syncProfiles')
                        ->label(__('Sync Profiles & Folders'))
                        ->icon('heroicon-o-arrow-path')
                        ->action(function (ArrIntegration $record) {
                            $service = ArrService::make($record);
                            $profiles = $service->fetchQualityProfiles();
                            $folders = $service->fetchRootFolders();

                            // Cache first as defaults on the model if not already set
                            $update = [];
                            if (! empty($profiles) && ! $record->quality_profile_id) {
                                $update['quality_profile_id'] = $profiles[0]['id'];
                                $update['quality_profile_name'] = $profiles[0]['name'];
                            }
                            if (! empty($folders) && ! $record->root_folder_path) {
                                $update['root_folder_path'] = $folders[0]['path'];
                            }

                            if (! empty($update)) {
                                $record->forceFill($update)->save();
                            }

                            Notification::make()
                                ->success()
                                ->title(__('Synced'))
                                ->body(__('Found :profiles profiles and :folders root folders.', [
                                    'profiles' => count($profiles),
                                    'folders' => count($folders),
                                ]))
                                ->send();
                        }),
                    DeleteAction::make(),
                ])->button(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArrIntegrations::route('/'),
            'create' => CreateArrIntegration::route('/create'),
            'edit' => EditArrIntegration::route('/{record}/edit'),
        ];
    }

    /**
     * Build the in-form "Test Connection & Discover" action. Runs against the
     * current form state, populates profile/folder hidden state, and surfaces
     * a Filament notification.
     *
     * @return array<int, Action>
     */
    private static function getDiscoverActions(): array
    {
        return [
            Action::make('testAndDiscover')
                ->label(__('Test Connection & Discover'))
                ->icon('heroicon-o-signal')
                ->action(function (Get $get, Set $set, $livewire) {
                    $apiKey = $get('api_key') ?: $livewire->record?->api_key;

                    if (! $get('type') || ! $get('url') || ! $apiKey) {
                        Notification::make()
                            ->danger()
                            ->title(__('Validation Error'))
                            ->body(__('Please fill in Type, URL, and API Key before testing.'))
                            ->send();

                        return;
                    }

                    $temp = new ArrIntegration([
                        'type' => $get('type'),
                        'url' => $get('url'),
                        'api_key' => $apiKey,
                    ]);

                    $service = ArrService::make($temp);
                    $test = $service->testConnection();

                    if (! $test['ok']) {
                        Notification::make()
                            ->danger()
                            ->title(__('Connection Failed'))
                            ->body($test['error'] ?? 'Unknown error')
                            ->send();

                        return;
                    }

                    $profiles = $service->fetchQualityProfiles();
                    $folders = $service->fetchRootFolders();

                    $set('quality_profiles_options', json_encode($profiles));
                    $set('root_folders_options', json_encode($folders));

                    // Pre-select the first option if nothing is set yet
                    if (! $get('quality_profile_id') && ! empty($profiles)) {
                        $set('quality_profile_id', $profiles[0]['id']);
                        $set('quality_profile_name', $profiles[0]['name']);
                    }
                    if (! $get('root_folder_path') && ! empty($folders)) {
                        $set('root_folder_path', $folders[0]['path']);
                    }

                    $livewire->record?->forceFill(['last_test_at' => now()])->save();

                    Notification::make()
                        ->success()
                        ->title(__('Connection Successful'))
                        ->body(__('Connected to v:version — found :p profiles, :f folders.', [
                            'version' => $test['version'] ?? 'unknown',
                            'p' => count($profiles),
                            'f' => count($folders),
                        ]))
                        ->send();
                }),
        ];
    }
}
