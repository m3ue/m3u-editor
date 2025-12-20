<?php

namespace App\Filament\Resources\MediaServerIntegrations;

use App\Filament\Resources\MediaServerIntegrations\Pages\CreateMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\Pages\EditMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\Pages\ListMediaServerIntegrations;
use App\Jobs\SyncMediaServer;
use App\Models\MediaServerIntegration;
use App\Services\MediaServerService;
use App\Traits\HasUserFiltering;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class MediaServerIntegrationResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = MediaServerIntegration::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Media Servers';

    protected static ?string $modelLabel = 'Media Server';

    protected static ?string $pluralModelLabel = 'Media Servers';

    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';

    protected static ?int $navigationSort = 100;

    public static function getRecordTitle(?Model $record): string|null|Htmlable
    {
        return $record?->name;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Server Configuration')
                    ->description('Configure your Emby or Jellyfin media server connection')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('name')
                                ->label('Display Name')
                                ->placeholder('e.g., Living Room Jellyfin')
                                ->required()
                                ->maxLength(255),

                            Select::make('type')
                                ->label('Server Type')
                                ->options([
                                    'emby' => 'Emby',
                                    'jellyfin' => 'Jellyfin',
                                ])
                                ->required()
                                ->native(false),
                        ]),

                        Grid::make(3)->schema([
                            TextInput::make('host')
                                ->label('Host / IP Address')
                                ->placeholder('192.168.1.100 or media.example.com')
                                ->required()
                                ->maxLength(255),

                            TextInput::make('port')
                                ->label('Port')
                                ->numeric()
                                ->default(8096)
                                ->required()
                                ->minValue(1)
                                ->maxValue(65535),

                            Toggle::make('ssl')
                                ->label('Use HTTPS')
                                ->helperText('Enable if your server uses SSL/TLS')
                                ->default(false),
                        ]),

                        TextInput::make('api_key')
                            ->label('API Key')
                            ->password()
                            ->revealable()
                            ->required()
                            ->helperText('Generate an API key in your media server\'s dashboard under Settings → API Keys'),
                    ]),

                Section::make('Import Settings')
                    ->description('Control what content is synced from the media server')
                    ->schema([
                        Grid::make(2)->schema([
                            Toggle::make('import_movies')
                                ->label('Import Movies')
                                ->helperText('Sync movies as VOD channels')
                                ->default(true),

                            Toggle::make('import_series')
                                ->label('Import Series')
                                ->helperText('Sync TV series with episodes')
                                ->default(true),
                        ]),

                        Select::make('genre_handling')
                            ->label('Genre Handling')
                            ->options([
                                'primary' => 'Primary Genre Only (recommended)',
                                'all' => 'All Genres (creates duplicates)',
                            ])
                            ->default('primary')
                            ->helperText('How to handle content with multiple genres')
                            ->native(false),

                        Toggle::make('enabled')
                            ->label('Enabled')
                            ->helperText('Disable to pause syncing without deleting the integration')
                            ->default(true),
                    ]),

                Section::make('Sync Status')
                    ->description('Information about the last sync operation')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('last_synced_at')
                                ->label('Last Synced')
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(function ($state) {
                                    if (!$state) {
                                        return 'Never';
                                    }
                                    if (is_string($state)) {
                                        $state = \Carbon\Carbon::parse($state);
                                    }
                                    return $state->diffForHumans();
                                }),

                            TextInput::make('sync_stats_summary')
                                ->label('Last Sync Stats')
                                ->disabled()
                                ->dehydrated(false)
                                ->formatStateUsing(function ($record) {
                                    if (!$record || !$record->sync_stats) {
                                        return 'No sync data';
                                    }
                                    $stats = $record->sync_stats;
                                    return sprintf(
                                        '%d movies, %d series, %d episodes',
                                        $stats['movies_synced'] ?? 0,
                                        $stats['series_synced'] ?? 0,
                                        $stats['episodes_synced'] ?? 0
                                    );
                                }),
                        ]),
                    ])
                    ->visibleOn('edit'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => ucfirst($state))
                    ->color(fn(string $state): string => match ($state) {
                        'emby' => 'success',
                        'jellyfin' => 'info',
                        default => 'gray',
                    }),

                TextColumn::make('host')
                    ->label('Server')
                    ->formatStateUsing(fn($record): string => "{$record->host}:{$record->port}")
                    ->copyable(),

                ToggleColumn::make('enabled')
                    ->label('Enabled'),

                TextColumn::make('last_synced_at')
                    ->label('Last Synced')
                    ->dateTime()
                    ->since()
                    ->sortable(),

                TextColumn::make('playlist.name')
                    ->label('Playlist')
                    ->url(fn($record) => $record->playlist_id
                        ? route('filament.admin.resources.playlists.edit', $record->playlist_id)
                        : null
                    )
                    ->placeholder('Not synced yet'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'emby' => 'Emby',
                        'jellyfin' => 'Jellyfin',
                    ]),
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('Enabled'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('test')
                        ->label('Test Connection')
                        ->icon('heroicon-o-signal')
                        ->color('info')
                        ->action(function (MediaServerIntegration $record) {
                            $service = MediaServerService::make($record);
                            $result = $service->testConnection();

                            if ($result['success']) {
                                Notification::make()
                                    ->success()
                                    ->title('Connection Successful')
                                    ->body("Connected to {$result['server_name']} (v{$result['version']})")
                                    ->send();
                            } else {
                                Notification::make()
                                    ->danger()
                                    ->title('Connection Failed')
                                    ->body($result['message'])
                                    ->send();
                            }
                        }),

                    Action::make('sync')
                        ->label('Sync Now')
                        ->icon('heroicon-o-arrow-path')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Sync Media Server')
                        ->modalDescription('This will sync all content from the media server. For large libraries, this may take several minutes.')
                        ->action(function (MediaServerIntegration $record) {
                            dispatch(new SyncMediaServer($record->id));

                            Notification::make()
                                ->success()
                                ->title('Sync Started')
                                ->body("Syncing content from {$record->name}. You'll be notified when complete.")
                                ->send();
                        }),

                    EditAction::make(),

                    Action::make('viewPlaylist')
                        ->label('View Playlist')
                        ->icon('heroicon-o-queue-list')
                        ->url(fn ($record) => $record->playlist_id
                            ? route('filament.admin.resources.playlists.edit', $record->playlist_id)
                            : null
                        )
                        ->visible(fn ($record) => $record->playlist_id !== null),

                    DeleteAction::make()
                        ->before(function (MediaServerIntegration $record) {
                            // Optionally delete the associated playlist
                            // For now, we leave the playlist intact (sidecar philosophy)
                        }),
                ])->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('syncAll')
                        ->label('Sync Selected')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                dispatch(new SyncMediaServer($record->id));
                            }

                            Notification::make()
                                ->success()
                                ->title('Sync Started')
                                ->body('Syncing ' . $records->count() . ' media servers.')
                                ->send();
                        }),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMediaServerIntegrations::route('/'),
            'create' => CreateMediaServerIntegration::route('/create'),
            'edit' => EditMediaServerIntegration::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', Auth::id());
    }
}
