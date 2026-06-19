<?php

namespace App\Filament\Resources\MediaServerIntegrations\Widgets;

use App\Filament\Resources\ArrIntegrations\ArrIntegrationResource;
use App\Models\ArrIntegration;
use App\Services\Arr\ArrService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as FormActions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Support\Facades\Auth;

class ArrIntegrationsWidget extends BaseWidget
{
    protected static ?string $heading = 'Sonarr & Radarr';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->query(
                ArrIntegration::query()
                    ->where('user_id', Auth::id())
                    ->orderBy('name')
            )
            ->headerActions([
                Action::make('createArr')
                    ->label(__('Add Sonarr / Radarr'))
                    ->modalHeading(__('Add Sonarr / Radarr Integration'))
                    ->form(self::arrForm())
                    ->action(function (array $data): void {
                        ArrIntegration::create(array_merge(
                            $data,
                            ['user_id' => Auth::id()]
                        ));

                        Notification::make()
                            ->success()
                            ->title(__('Integration Added'))
                            ->send();
                    }),
            ])
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
                Action::make('edit')
                    ->label(__('Edit'))
                    ->icon('heroicon-s-pencil-square')
                    ->url(fn (ArrIntegration $record): string => ArrIntegrationResource::getUrl('edit', ['record' => $record]))
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

    /**
     * Form schema shared by the Add modal. Edit uses the full resource page.
     *
     * @return array<int, mixed>
     */
    private static function arrForm(): array
    {
        return [
            Section::make(__('Connection'))
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
                            ->native(false),
                    ]),

                    Grid::make(2)->schema([
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
                        ->required()
                        ->helperText(__('Found in Sonarr/Radarr under Settings → General → API Key.')),

                    FormActions::make([
                        Action::make('testAndDiscover')
                            ->label(__('Test Connection & Discover'))
                            ->icon('heroicon-o-signal')
                            ->action(function (Get $get, Set $set, $livewire): void {
                                $apiKey = $get('api_key');

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

                                if (! $get('quality_profile_id') && ! empty($profiles)) {
                                    $set('quality_profile_id', $profiles[0]['id']);
                                    $set('quality_profile_name', $profiles[0]['name']);
                                }
                                if (! $get('root_folder_path') && ! empty($folders)) {
                                    $set('root_folder_path', $folders[0]['path']);
                                }

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
                    ])->fullWidth(),

                    Hidden::make('quality_profile_id'),
                    Hidden::make('quality_profile_name'),
                    Hidden::make('root_folder_path'),

                    Grid::make(2)->schema([
                        Select::make('quality_profile_id')
                            ->label(__('Quality Profile'))
                            ->options(function (Get $get): array {
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
                            ->options(function (Get $get): array {
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

                    Hidden::make('quality_profiles_options')->dehydrated(false),
                    Hidden::make('root_folders_options')->dehydrated(false),
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
        ];
    }
}
