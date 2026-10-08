<?php

namespace App\Filament\Resources\ArrIntegrations;

use App\Filament\Clusters\MediaServers\MediaServersCluster;
use App\Filament\Resources\ArrIntegrations\Pages\ListArrIntegrations;
use App\Models\ArrIntegration;
use App\Services\Arr\ArrService;
use App\Services\CachedContentDispatchService;
use App\Traits\HasUserFiltering;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
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
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ArrIntegrationResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = ArrIntegration::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $cluster = MediaServersCluster::class;

    // Prefixed by the cluster: the admin URL is /media-server-integrations/arr.
    protected static ?string $slug = 'arr';

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
                Toggle::make('enabled')
                    ->label(__('Enabled'))
                    ->helperText(__('Disable to pause use of this integration without deleting it.'))
                    ->default(true),
                Fieldset::make(__('Connection'))
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

                        TextInput::make('url')
                            ->label(__('Server URL'))
                            ->placeholder('http://192.168.1.42:8989')
                            ->required()
                            ->url()
                            ->maxLength(255),

                        TextInput::make('api_key')
                            ->label(__('API Key'))
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrateStateUsing(fn ($state, ?ArrIntegration $record) => filled($state) ? $state : $record?->api_key)
                            ->helperText(fn (string $operation): string => $operation === 'edit'
                                ? 'Leave blank to keep the existing API key.'
                                : 'Found in Sonarr/Radarr under Settings → General → API Key.'),

                        Grid::make(1)->columnSpanFull()->schema([
                            Actions::make(self::getDiscoverActions())
                                ->fullWidth(),
                        ]),

                        Hidden::make('quality_profile_name'),

                        // Until "Test Connection & Discover" loads the server's lists,
                        // these show just the saved choice, locked, with a prompt.
                        Fieldset::make(__('Defaults'))->schema([
                            Select::make('quality_profile_id')
                                ->label(__('Quality Profile'))
                                ->options(function (Get $get): array {
                                    if (blank($get('quality_profiles_options'))) {
                                        return filled($get('quality_profile_id'))
                                            ? [$get('quality_profile_id') => $get('quality_profile_name') ?: $get('quality_profile_id')]
                                            : [];
                                    }

                                    return collect(json_decode($get('quality_profiles_options'), true) ?: [])
                                        ->mapWithKeys(fn (array $p) => [$p['id'] => $p['name']])
                                        ->all();
                                })
                                ->disabled(fn (Get $get): bool => blank($get('quality_profiles_options')))
                                ->helperText(fn (Get $get): ?string => blank($get('quality_profiles_options'))
                                    ? __('Click "Test Connection & Discover" above to load the options from the server.')
                                    : null)
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, $state): void {
                                    $raw = $get('quality_profiles_options') ?? '[]';
                                    $profiles = json_decode($raw, true) ?: [];
                                    $profile = collect($profiles)->firstWhere('id', $state);
                                    if ($profile) {
                                        $set('quality_profile_name', $profile['name']);
                                    }
                                })
                                ->native(false),

                            Select::make('root_folder_path')
                                ->label(__('Root Folder'))
                                ->options(function (Get $get): array {
                                    if (blank($get('root_folders_options'))) {
                                        return filled($get('root_folder_path'))
                                            ? [$get('root_folder_path') => $get('root_folder_path')]
                                            : [];
                                    }

                                    return collect(json_decode($get('root_folders_options'), true) ?: [])
                                        ->mapWithKeys(fn (array $f) => [$f['path'] => $f['path']])
                                        ->all();
                                })
                                ->disabled(fn (Get $get): bool => blank($get('root_folders_options')))
                                ->helperText(fn (Get $get): ?string => blank($get('root_folders_options'))
                                    ? __('Click "Test Connection & Discover" above to load the options from the server.')
                                    : null)
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

                Fieldset::make(__('Options'))
                    ->schema([
                        Grid::make()
                            ->columns(1)
                            ->schema([

                                Toggle::make('guest_enabled')
                                    ->label(__('Allow Guest Requests'))
                                    ->helperText(__('Allow guests to request content via this integration on any playlist that has content requests enabled.'))
                                    ->default(false),

                                Toggle::make('cache_enabled')
                                    ->label(__('Use for caching'))
                                    ->disabled(fn (): bool => ! app(CachedContentDispatchService::class)->isEnabled())
                                    ->helperText(fn (): string => app(CachedContentDispatchService::class)->isEnabled()
                                        ? __('On playlists that prefer media server sources, Cache Now and dynamic group caching add new titles here instead of downloading them from the provider. Titles already in the library are never changed or removed.')
                                        : __('Turn on "Enable cache" in Settings > Cache to use this integration for caching.'))
                                    ->live()
                                    ->default(false),

                                Toggle::make('cache_cleanup')
                                    ->label(__('Remove after leaving dynamic groups'))
                                    ->helperText(__('Movies dynamic group caching adds here are removed, files included, once they have been out of every dynamic group for the longest "Keep after leaving (days)" among your caching rules (at least 1 day). Only movies added while this is on are removed, never ones already in the library. Use Cache Now on a movie to keep it.'))
                                    ->visible(fn (Get $get): bool => $get('type') === 'radarr' && (bool) $get('cache_enabled'))
                                    ->default(false),

                                Toggle::make('cache_failback')
                                    ->label(__('Fail back to the provider'))
                                    ->helperText(__('When a movie or episode sent here fails to download, or still isn\'t downloaded or downloading after 24 hours, it is downloaded from the playlist provider instead and unmonitored here. Nothing is ever deleted from the arr.'))
                                    ->visible(fn (Get $get): bool => (bool) $get('cache_enabled'))
                                    ->default(false),
                            ]),
                    ]),

                Section::make(__('Webhook'))
                    ->key('webhook')
                    ->compact()
                    ->description(__('Add this URL as a notification in Radarr/Sonarr (Settings → Connect → Webhook) for real-time queue updates.'))
                    ->afterHeader(self::getWebhookActions())
                    ->schema([
                        TextInput::make('webhook_url')
                            ->label(__('Webhook URL'))
                            ->formatStateUsing(fn (?ArrIntegration $record): ?string => $record?->webhook_url)
                            ->disabled()
                            ->dehydrated(false)
                            ->copyable()
                            ->extraAttributes(['class' => 'font-mono text-xs'])
                            ->helperText(__('Set "On Grab", "On Download", "On Movie Added", and "On Manual Interaction Required" triggers in Radarr/Sonarr.')),
                    ])
                    ->visible(fn (string $operation): bool => $operation === 'edit'),

                Fieldset::make(__('Status'))
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('last_test_at')
                                ->label(__('Last Tested'))
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state)->diffForHumans() : 'Never'),

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
            ->headerActions([
                CreateAction::make()
                    ->label(__('Add Sonarr / Radarr'))
                    ->slideOver()
                    ->mutateDataUsing(fn (array $data): array => [...$data, 'user_id' => Auth::id()]),
            ])
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                ToggleColumn::make('enabled')
                    ->label(__('Enabled'))
                    ->sortable(),

                ToggleColumn::make('guest_enabled')
                    ->label(__('Guest'))
                    ->sortable(),

                ToggleColumn::make('cache_enabled')
                    ->label(__('Caching'))
                    ->disabled(fn (): bool => ! app(CachedContentDispatchService::class)->isEnabled())
                    ->tooltip(fn (): string => app(CachedContentDispatchService::class)->isEnabled()
                        ? __('On playlists that prefer media server sources, Cache Now and dynamic group caching add new titles here instead of downloading them from the provider. Titles already in the library are never changed or removed.')
                        : __('Turn on "Enable cache" in Settings > Cache to use this integration for caching.'))
                    ->sortable(),

                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'sonarr' ? 'info' : 'warning')
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),

                TextColumn::make('url')
                    ->label(__('URL'))
                    ->searchable()
                    ->copyable()
                    ->limit(40),

                TextColumn::make('quality_profile_name')
                    ->label(__('Quality Profile'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('last_test_at')
                    ->label(__('Last Tested'))
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'sonarr' => 'Sonarr',
                        'radarr' => 'Radarr',
                    ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('test')
                        ->label(__('Test Connection'))
                        ->icon('heroicon-o-signal')
                        ->action(function (ArrIntegration $record): void {
                            $result = ArrService::make($record)->testConnection();

                            if (! $result['ok']) {
                                Notification::make()
                                    ->danger()
                                    ->title(__('Connection Failed'))
                                    ->body($result['error'] ?? 'Unknown error')
                                    ->send();

                                return;
                            }

                            $record->forceFill(['last_test_at' => now()])->save();

                            Notification::make()
                                ->success()
                                ->title(__('Connection Successful'))
                                ->body(__('Connected to :name (v:version)', [
                                    'name' => $record->name,
                                    'version' => $result['version'] ?? 'unknown',
                                ]))
                                ->send();
                        }),
                    Action::make('syncProfiles')
                        ->label(__('Sync Profiles & Folders'))
                        ->icon('heroicon-o-arrow-path')
                        ->action(function (ArrIntegration $record): void {
                            $service = ArrService::make($record);
                            $profiles = $service->fetchQualityProfiles();
                            $folders = $service->fetchRootFolders();

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
                ])->button()->hiddenLabel()->size('sm'),
                EditAction::make()
                    ->slideOver()
                    ->button()->hiddenLabel()->size('sm'),
            ], RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateHeading(__('No Sonarr or Radarr integrations'))
            ->emptyStateDescription(__('Add a Sonarr or Radarr server to enable content requesting.'))
            ->emptyStateIcon('heroicon-o-arrow-down-tray');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArrIntegrations::route('/'),
        ];
    }

    /**
     * Register and Test buttons for the Webhook section. Both use the URL
     * shown in the field, so the arr gets the address this page is open at.
     *
     * @return array<int, Action>
     */
    private static function getWebhookActions(): array
    {
        return [
            Action::make('testWebhook')
                ->label(__('Test Webhook'))
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->action(fn (ArrIntegration $record) => self::webhookNotification(
                    ArrService::make($record)->testWebhook($record->webhook_url),
                    $record,
                    __('Webhook test succeeded'),
                    __(':name reached this app.', ['name' => $record->name]),
                    __('Webhook test failed'),
                )->send()),
            Action::make('registerWebhook')
                ->label(__('Register Webhook'))
                ->icon('heroicon-o-link')
                ->requiresConfirmation()
                ->modalIcon('heroicon-o-link')
                ->modalDescription(fn (ArrIntegration $record): string => __(':name will send queue updates to the Webhook URL shown here. It tests the URL first and saves nothing if it can\'t reach it.', ['name' => $record->name]))
                ->action(fn (ArrIntegration $record) => self::webhookNotification(
                    ArrService::make($record)->registerWebhook($record->webhook_url),
                    $record,
                    __('Webhook registered'),
                    __(':name will now send queue updates to this app.', ['name' => $record->name]),
                    __('Could not register the webhook'),
                )->send()),
        ];
    }

    /**
     * @param  array{ok: bool, error?: string}  $result
     */
    private static function webhookNotification(array $result, ArrIntegration $record, string $successTitle, string $successBody, string $failureTitle): Notification
    {
        if ($result['ok']) {
            return Notification::make()->success()->title($successTitle)->body($successBody);
        }

        return Notification::make()
            ->danger()
            ->title($failureTitle)
            ->body(trim(($result['error'] ?? '').' '.__(':name must be able to reach the Webhook URL. If this page is open at localhost, open it at the app\'s LAN address and try again.', ['name' => $record->name])));
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
                ->action(function (Get $get, Set $set, ?ArrIntegration $record) {
                    $apiKey = $get('api_key') ?: $record?->api_key;

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

                    $record?->forceFill(['last_test_at' => now()])->save();

                    Notification::make()
                        ->success()
                        ->title(__('Connection Successful'))
                        ->body(__('Connected to v:version - found :p profiles, :f folders.', [
                            'version' => $test['version'] ?? 'unknown',
                            'p' => count($profiles),
                            'f' => count($folders),
                        ]))
                        ->send();
                }),
        ];
    }
}
