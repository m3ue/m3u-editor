<?php

namespace App\Filament\Resources;

use App\Enums\Status;
use App\Filament\Resources\PlaylistResource\Pages;
use App\Filament\Resources\PlaylistResource\RelationManagers;
use App\Forms\Components\PlaylistEpgUrl;
use App\Forms\Components\PlaylistM3uUrl;
use App\Models\Playlist;
use App\Rules\CheckIfUrlOrLocalPath;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use RyanChandler\FilamentProgressColumn\ProgressColumn;
use App\Facades\PlaylistUrlFacade;
use App\Filament\Resources\PlaylistSyncStatusResource\Pages\CreatePlaylistSyncStatus;
use App\Filament\Resources\PlaylistSyncStatusResource\Pages\EditPlaylistSyncStatus;
use App\Filament\Resources\PlaylistSyncStatusResource\Pages\ListPlaylistSyncStatuses;
use App\Filament\Resources\PlaylistSyncStatusResource\Pages\ViewPlaylistSyncStatus;
use App\Forms\Components\MediaFlowProxyUrl;
use App\Models\PlaylistSyncStatus;
use App\Models\SourceGroup;
use App\Services\XtreamService;
use Filament\Facades\Filament;
use Filament\Tables\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use PDO;

class PlaylistResource extends Resource
{
    protected static ?string $model = Playlist::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getRecordTitle(?Model $record): string|null|Htmlable
    {
        return $record->name;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'url'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static ?string $navigationIcon = 'heroicon-o-play';

    protected static ?string $navigationGroup = 'Playlist';

    public static function getNavigationSort(): ?int
    {
        return 0;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return self::setupTable($table);
    }

    public static function setupTable(Table $table, $relationId = null): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount('enabled_channels');
            })
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('url')
                    ->label('Playlist URL')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                Tables\Columns\TextColumn::make('available_streams')
                    ->label('Streams')
                    ->toggleable()
                    ->formatStateUsing(fn(int $state): string => $state === 0 ? '∞' : (string)$state)
                    ->tooltip('Total streams available for this playlist (∞ indicates no limit)')
                    ->description(fn(Playlist $record): string => "Active: " . (int) Redis::get("active_streams:{$record->id}") ?? 0)
                    ->sortable(),
                Tables\Columns\TextColumn::make('groups_count')
                    ->label('Groups')
                    ->counts('groups')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('channels_count')
                    ->label('Channels')
                    ->counts('channels')
                    ->description(fn(Playlist $record): string => "Enabled: {$record->enabled_channels_count}")
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->toggleable()
                    ->color(fn(Status $state) => $state->getColor()),
                ProgressColumn::make('progress')
                    ->label('Sync Progress')
                    ->sortable()
                    ->poll(fn($record) => $record->status === Status::Processing || $record->status === Status::Pending ? '3s' : null)
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('enable_proxy')
                    ->label('Proxy')
                    ->toggleable()
                    ->tooltip('Toggle proxy status')
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('auto_sync')
                    ->label('Auto Sync')
                    ->toggleable()
                    ->tooltip('Toggle auto-sync status')
                    ->sortable(),
                Tables\Columns\TextColumn::make('synced')
                    ->label('Last Synced')
                    ->since()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sync_interval')
                    ->label('Interval')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sync_time')
                    ->label('Sync Time')
                    ->formatStateUsing(fn(string $state): string => gmdate('H:i:s', (int)$state))
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('exp_date')
                    ->label('Expiry Date')
                    ->getStateUsing(function ($record) {
                        if ($record->xtream_status) {
                            try {
                                if ($record->xtream_status['user_info']['exp_date'] ?? false) {
                                    $expires = Carbon::createFromTimestamp($record->xtream_status['user_info']['exp_date']);
                                    return $expires->toDayDateTimeString();
                                }
                            } catch (\Exception $e) {
                            }
                        }
                        return 'N/A';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    Tables\Actions\Action::make('process')
                        ->label(fn($record): string => $record->xtream ? 'Process All' : 'Process')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Processing,
                                'progress' => 0,
                            ]);
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ProcessM3uImport($record, force: true));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist is processing')
                                ->body('Playlist is being processed in the background. Depending on the size of your playlist, this may take a while. You will be notified on completion.')
                                ->duration(10000)
                                ->send();
                        })
                        ->disabled(fn($record): bool => $record->status === Status::Processing)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process playlist now?')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\Action::make('process_series')
                        ->label('Process Series Only')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Processing,
                                'series_progress' => 0,
                            ]);
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ProcessM3uImportSeries($record, force: true));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist is processing series')
                                ->body('Playlist series are being processed in the background. Depending on the number of series and seasons being imported, this may take a while. You will be notified on completion.')
                                ->duration(10000)
                                ->send();
                        })
                        ->disabled(fn($record): bool => $record->status === Status::Processing)
                        ->hidden(fn($record): bool => !$record->xtream)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process playlist series now?')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\Action::make('Download M3U')
                        ->label('Download M3U')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => PlaylistUrlFacade::getUrls($record)['m3u'])
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('Download EPG')
                        ->label('Download EPG')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalHeading('Download EPG')
                        ->modalIcon('heroicon-o-arrow-down-tray')
                        ->modalDescription('Select the EPG format to download and your download will begin immediately.')
                        ->modalWidth('md')
                        ->modalFooterActions([
                            Tables\Actions\Action::make('uncompressed')
                                ->requiresConfirmation()
                                ->label('Download uncompressed EPG')
                                ->action(fn($record) => redirect(PlaylistUrlFacade::getUrls($record)['epg'])),
                            Tables\Actions\Action::make('compressed')
                                ->requiresConfirmation()
                                ->label('Download gzip EPG')
                                ->action(fn($record) => redirect(PlaylistUrlFacade::getUrls($record)['epg_zip']))
                        ])
                        ->modalSubmitActionLabel('Download EPG'),
                    Tables\Actions\Action::make('HDHomeRun URL')
                        ->label('HDHomeRun URL')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn($record) => PlaylistUrlFacade::getUrls($record)['hdhr'])
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('Duplicate')
                        ->label('Duplicate')
                        ->form([
                            Forms\Components\TextInput::make('name')
                                ->label('Playlist name')
                                ->required()
                                ->helperText('This will be the name of the duplicated playlist.'),
                        ])
                        ->action(function ($record, $data) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\DuplicatePlaylist($record, $data['name']));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist is being duplicated')
                                ->body('Playlist is being duplicated in the background. You will be notified on completion.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-document-duplicate')
                        ->modalIcon('heroicon-o-document-duplicate')
                        ->modalDescription('Duplicate playlist now?')
                        ->modalSubmitActionLabel('Yes, duplicate now'),
                    Tables\Actions\Action::make('Sync Logs')
                        ->label('View Sync Logs')
                        ->color('gray')
                        ->icon('heroicon-m-arrows-right-left')
                        ->url(
                            fn(Playlist $record): string => PlaylistResource::getUrl(
                                name: 'playlist-sync-statuses.index',
                                parameters: [
                                    'parent' => $record->id,
                                ]
                            )
                        ),
                    Tables\Actions\Action::make('reset')
                        ->label('Reset status')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Pending,
                                'processing' => false,
                                'progress' => 0,
                                'series_progress' => 0,
                                'channels' => 0,
                                'synced' => null,
                                'errors' => null,
                            ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist status reset')
                                ->body('Playlist status has been reset.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription('Reset playlist status so it can be processed again. Only perform this action if you are having problems with the playlist syncing.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    Tables\Actions\Action::make('reset_active_count')
                        ->label('Reset active count')
                        ->icon('heroicon-o-numbered-list')
                        ->color('warning')
                        ->action(fn($record) => Redis::set("active_streams:{$record->id}", 0))->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Active stream count reset')
                                ->body('Playlist active stream count has been reset.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-numbered-list')
                        ->modalDescription('Reset playlist active streams count. Proceed with caution as this could lead to an incorrect count if there are streams currently running.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    Tables\Actions\DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm'),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('process')
                        ->label('Process selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'status' => Status::Processing,
                                    'progress' => 0,
                                ]);
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new \App\Jobs\ProcessM3uImport($record, force: true));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected playlists are processing')
                                ->body('The selected playlists are being processed in the background. Depending on the size of your playlist, this may take a while.')
                                ->duration(10000)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process the selected playlist(s) now?')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\BulkAction::make('reset')
                        ->label('Reset status')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                $record->update([
                                    'status' => Status::Pending,
                                    'processing' => false,
                                    'progress' => 0,
                                    'series_progress' => 0,
                                    'channels' => 0,
                                    'synced' => null,
                                    'errors' => null,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Playlist status reset')
                                ->body('Status has been reset for the selected Playlists.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription('Reset status for the selected Playlists so they can be processed again. Only perform this action if you are having problems with the playlist syncing.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    Tables\Actions\BulkAction::make('reset_active_count')
                        ->label('Reset active count')
                        ->icon('heroicon-o-numbered-list')
                        ->color('warning')
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                Redis::set("active_streams:{$record->id}", 0);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Active stream count reset')
                                ->body('Active stream count has been reset for the selected Playlists.')
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-numbered-list')
                        ->modalDescription('Reset active streams count for the selected Playlists. Proceed with caution as this could lead to an incorrect count if there are streams currently running.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])->checkIfRecordIsSelectableUsing(
                fn($record): bool => $record->status !== Status::Processing,
            );
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            // Playlists
            'index' => Pages\ListPlaylists::route('/'),
            'create' => Pages\CreatePlaylist::route('/create'),
            'edit' => Pages\EditPlaylist::route('/{record}/edit'),

            // Playlist Sync Statuses
            'playlist-sync-statuses.index' => ListPlaylistSyncStatuses::route('/{parent}/syncs'),
            //'playlist-sync-statuses.create' => CreatePlaylistSyncStatus::route('/{parent}/syncs/create'),
            'playlist-sync-statuses.view' => ViewPlaylistSyncStatus::route('/{parent}/syncs/{record}'),
            //'playlist-sync-statuses.edit' => EditPlaylistSyncStatus::route('/{parent}/syncs/{record}/edit'),
        ];
    }

    public static function getFormSections($creating = false): array
    {
        // Define the form fields for each section
        $nameFields = [
            Forms\Components\TextInput::make('name')
                ->helperText('Enter the name of the playlist. Internal use only.')
                ->required(),
        ];

        // See if MediaFlow Proxy is set up
        if (PlaylistUrlFacade::mediaFlowProxyEnabled()) {
            $nameFields[] = Forms\Components\Section::make('MediaFlow Proxy')
                ->description('Your MediaFlow Proxy generated links – to disable clear the MediaFlow Proxy values from the app Settings page.')
                ->collapsible()
                ->collapsed($creating)
                ->headerActions([
                    Forms\Components\Actions\Action::make('mfproxy_git')
                        ->label('GitHub')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->iconPosition('after')
                        ->color('gray')
                        ->size('sm')
                        ->url('https://github.com/mhdzumair/mediaflow-proxy')
                        ->openUrlInNewTab(true),
                    Forms\Components\Actions\Action::make('mfproxy_docs')
                        ->label('Docs')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->iconPosition('after')
                        ->size('sm')
                        ->url(fn($record) => PlaylistUrlFacade::getMediaFlowProxyServerUrl($record) . '/docs')
                        ->openUrlInNewTab(true),
                ])
                ->schema([
                    MediaFlowProxyUrl::make('mediaflow_proxy_url')
                        ->label('Proxied M3U URL')
                        ->columnSpan(2)
                        ->dehydrated(false) // don't save the value in the database
                ])->hiddenOn(['create']);
        }

        $typeFields = [
            Forms\Components\Grid::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\ToggleButtons::make('xtream')
                        ->label('Playlist type')
                        ->grouped()
                        ->options([
                            false => 'm3u8 url or local file',
                            true => 'Xtream API',
                        ])
                        ->icons([
                            false => 'heroicon-s-link',
                            true => 'heroicon-s-bolt',
                        ])
                        ->default(false)
                        ->live(),
                    Forms\Components\TextInput::make('xtream_config.url')
                        ->label('Xtream API URL')
                        ->live()
                        ->helperText('Enter the full url, using <url>:<port> format - without trailing slash (/).')
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->maxLength(255)
                        ->url()
                        ->columnSpan(2)
                        ->required()
                        ->hidden(fn(Get $get): bool => !$get('xtream')),
                    Forms\Components\Grid::make()
                        ->columnSpanFull()
                        ->schema([
                            Forms\Components\Fieldset::make('Config')
                                ->columns(3)
                                ->schema([
                                    Forms\Components\TextInput::make('xtream_config.username')
                                        ->label('Xtream API Username')
                                        ->live()
                                        ->required()
                                        ->columnSpan(1),
                                    Forms\Components\TextInput::make('xtream_config.password')
                                        ->label('Xtream API Password')
                                        ->live()
                                        ->required()
                                        ->columnSpan(1)
                                        ->password()
                                        ->revealable(),
                                    Forms\Components\Select::make('xtream_config.output')
                                        ->label('Output')
                                        ->required()
                                        ->columnSpan(1)
                                        ->options([
                                            'ts' => 'MPEG-TS (.ts)',
                                            'm3u8' => 'HLS (.m3u8)',
                                        ])->default('ts'),
                                    Forms\Components\CheckboxList::make('xtream_config.import_options')
                                        ->label('Groups and Streams to Import')
                                        ->columnSpan(2)
                                        ->live()
                                        ->options([
                                            'live' => 'Live',
                                            'vod' => 'VOD',
                                        ])->helperText('NOTE: Playlist series can be imported in the Series section.'),
                                    Forms\Components\Toggle::make('xtream_config.import_epg')
                                        ->label('Import EPG')
                                        ->helperText('If your provider supports EPG, you can import it automatically.')
                                        ->columnSpan(1)
                                        ->inline(false)
                                        ->default(true),
                                ]),
                        ])->hidden(fn(Get $get): bool => !$get('xtream')),
                    Forms\Components\TextInput::make('url')
                        ->label('URL or Local file path')
                        ->columnSpan(2)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->helperText('Enter the URL of the playlist file. If this is a local file, you can enter a full or relative path. If changing URL, the playlist will be re-imported. Use with caution as this could lead to data loss if the new playlist differs from the old one.')
                        ->requiredWithout('uploads')
                        ->rules([new CheckIfUrlOrLocalPath()])
                        ->maxLength(255)
                        ->hidden(fn(Get $get): bool => !!$get('xtream')),
                    Forms\Components\FileUpload::make('uploads')
                        ->label('File')
                        ->columnSpan(2)
                        ->disk('local')
                        ->directory('playlist')
                        ->helperText('Upload the playlist file. This will be used to import groups and channels.')
                        ->rules(['file'])
                        ->requiredWithout('url')
                        ->hidden(fn(Get $get): bool => !!$get('xtream')),
                ]),

            Forms\Components\Grid::make()
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\TextInput::make('user_agent')
                        ->helperText('User agent string to use for fetching the playlist.')
                        ->default('Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13')
                        ->columnSpan(2)
                        ->required(),
                    Forms\Components\Toggle::make('disable_ssl_verification')
                        ->label('Disable SSL verification')
                        ->helperText('Only disable this if you are having issues.')
                        ->columnSpan(1)
                        ->onColor('danger')
                        ->inline(false)
                        ->default(false),
                ])
        ];

        $schedulingFields = [
            Forms\Components\Grid::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Grid::make()
                        ->columns(3)
                        ->columnSpanFull()
                        ->schema([
                            Forms\Components\Toggle::make('auto_sync')
                                ->label('Automatically sync playlist')
                                ->helperText('When enabled, the playlist will be automatically re-synced at the specified interval.')
                                ->live()
                                ->columnSpan(2)
                                ->inline(false)
                                ->default(true),
                            Forms\Components\Select::make('sync_interval')
                                ->label('Sync Every')
                                ->helperText('Default is every 24hr if left empty.')
                                ->columnSpan(1)
                                ->options([
                                    '15 minutes' => '15 minutes',
                                    '30 minutes' => '30 minutes',
                                    '45 minutes' => '45 minutes',
                                    '1 hour' => '1 hour',
                                    '2 hours' => '2 hours',
                                    '3 hours' => '3 hours',
                                    '4 hours' => '4 hours',
                                    '5 hours' => '5 hours',
                                    '6 hours' => '6 hours',
                                    '7 hours' => '7 hours',
                                    '8 hours' => '8 hours',
                                    '12 hours' => '12 hours',
                                    '24 hours' => '24 hours',
                                    '2 days' => '2 days',
                                    '3 days' => '3 days',
                                    '1 week' => '1 week',
                                    '2 weeks' => '2 weeks',
                                    '1 month' => '1 month',
                                ])->hidden(fn(Get $get): bool => !$get('auto_sync')),
                        ]),

                    Forms\Components\DateTimePicker::make('synced')
                        ->columnSpan(2)
                        ->suffix('UTC')
                        ->native(false)
                        ->label('Last Synced')
                        ->hidden(fn(Get $get, string $operation): bool => !$get('auto_sync') || $operation === 'create')
                        ->helperText('Playlist will be synced at the specified interval. Timestamp is automatically updated after each sync. Set to any time in the past (or future) and the next sync will run when the defined interval has passed since the time set.'),
                ])
        ];

        $processingFields = [
            Forms\Components\Grid::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Forms\Components\Toggle::make('import_prefs.preprocess')
                        ->label('Preprocess playlist')
                        ->columnSpan(1)
                        ->live()
                        ->inline(true)
                        ->default(false)
                        ->helperText('When enabled, the playlist will be preprocessed before importing. You can then select which groups you would like to import.'),
                    Forms\Components\Toggle::make('enable_channels')
                        ->label('Enable new channels')
                        ->columnSpan(1)
                        ->inline(true)
                        ->default(false)
                        ->helperText('When enabled, newly added channels will be enabled by default.'),
                    Forms\Components\Toggle::make('import_prefs.use_regex')
                        ->label('Use regex for filtering')
                        ->columnSpan(2)
                        ->inline(true)
                        ->live()
                        ->default(false)
                        ->helperText('When enabled, groups will be included based on regex pattern match instead of prefix.')
                        ->hidden(fn(Get $get): bool => !$get('import_prefs.preprocess') || !$get('status')),
                    Forms\Components\Select::make('import_prefs.selected_groups')
                        ->label('Groups to import')
                        ->columnSpan(1)
                        ->searchable()
                        ->multiple()
                        ->helperText('NOTE: If the list is empty, sync the playlist and check again once complete.')
                        ->options(
                            fn($record): array =>
                            SourceGroup::where('playlist_id', $record->id)
                                ->get()->pluck('name', 'name')->toArray()
                        )
                        ->hidden(fn(Get $get): bool => !$get('import_prefs.preprocess') || !$get('status')),
                    Forms\Components\TagsInput::make('import_prefs.included_group_prefixes')
                        ->label(fn(Get $get) => !$get('import_prefs.use_regex') ? 'Group prefixes to import' : 'Regex patterns to import')
                        ->helperText('Press [tab] or [return] to add item.')
                        ->columnSpan(1)
                        ->suggestions([
                            'US -',
                            'UK -',
                            'CA -',
                            '^(US|UK|CA)',
                            'Sports.*HD$',
                            '\[.*\]'
                        ])
                        ->splitKeys(['Tab', 'Return', ','])
                        ->hidden(fn(Get $get): bool => !$get('import_prefs.preprocess') || !$get('status')),
                    Forms\Components\TagsInput::make('import_prefs.ignored_file_types')
                        ->label('Ignored file types')
                        ->helperText('Press [tab] or [return] to add item. You can ignore certain file types from being imported (.e.g.: ".mkv", ".mp4", etc.) This is useful for ignoring VOD or other unwanted content.')
                        ->columnSpan(2)
                        ->suggestions([
                            '.avi',
                            '.mkv',
                            '.mp4',
                        ])->splitKeys(['Tab', 'Return', ',', ' ']),
                ]),
        ];

        $outputFields = [
            Forms\Components\Section::make('Playlist Output')
                ->description('Determines how the playlist is output')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('auto_sort')
                        ->label('Automatically assign sort number based on playlist order')
                        ->columnSpan(1)
                        ->inline(false)
                        ->default(true)
                        ->helperText('NOTE: You will need to re-sync your playlist, or wait for the next scheduled sync, if changing this. This will overwrite any existing channel sort order customization for this playlist.'),
                    Forms\Components\Toggle::make('auto_channel_increment')
                        ->label('Auto channel number increment')
                        ->columnSpan(1)
                        ->inline(false)
                        ->live()
                        ->default(false)
                        ->helperText('If no channel number is set, output an automatically incrementing number.'),
                    Forms\Components\TextInput::make('channel_start')
                        ->helperText('The starting channel number.')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->hidden(fn(Get $get): bool => !$get('auto_channel_increment'))
                        ->required(),
                ]),
            Forms\Components\Section::make('Streaming Output')
                ->description('Output processing options')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('enable_proxy')
                        ->label('Enable Proxy')
                        ->hint(fn(Get $get): string => $get('enable_proxy') ? 'Proxied' : 'Not proxied')
                        ->hintIcon(fn(Get $get): string => !$get('enable_proxy') ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                        ->columnSpanFull()
                        ->live()
                        ->inline(false)
                        ->default(false)
                        ->helperText('When enabled, channel urls will be proxied through m3u editor and streamed via ffmpeg (m3u editor will act as your client, playing the channels directly and sending the content to your client).'),
                    Forms\Components\TextInput::make('available_streams')
                        ->label('Available Streams')
                        ->hint('Set to 0 for unlimited streams.')
                        ->helperText('Number of streams available for this provider. If set to a value other than 0, will prevent any streams from starting if the number of active streams exceeds this value.')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->default(0) // Default to 0 streams (for unlimted)
                        ->required()
                        ->hidden(fn(Get $get): bool => !$get('enable_proxy')),
                    Forms\Components\TextInput::make('streams')
                        ->label('HDHR Streams')
                        ->helperText('Number of streams available for HDHR service (if using).')
                        ->columnSpan(1)
                        ->rules(['min:0'])
                        ->type('number')
                        ->default(1) // Default to 1 stream
                        ->required()
                        ->hidden(fn(Get $get): bool => !$get('enable_proxy')),
                    Forms\Components\Select::make('proxy_options.output')
                        ->label('Proxy Output Format')
                        ->required()
                        ->columnSpanFull()
                        ->options([
                            'ts' => 'MPEG-TS (.ts)',
                            'hls' => 'HLS (.m3u8)',
                        ])
                        ->default('ts')->helperText('NOTE: Only HLS streaming supports multiple connections per stream. MPEG-TS creates a new stream for each connection.')
                        ->hidden(fn(Get $get): bool => !$get('enable_proxy')),
                ]),
            Forms\Components\Section::make('EPG Output')
                ->description('EPG output options')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed($creating)
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('dummy_epg')
                        ->label('Enable dummy EPG')
                        ->columnSpan(1)
                        ->live()
                        ->inline(false)
                        ->default(false)
                        ->helperText('When enabled, dummy EPG data will be generated for the next 5 days. Thus, it is possible to assign channels for which no EPG data is available. As program information, the channel title and the set program length are used.'),
                    Forms\Components\Select::make('id_channel_by')
                        ->label('Preferred TVG ID output')
                        ->helperText('How you would like to ID your channels in the EPG.')
                        ->options([
                            'stream_id' => 'TVG ID/Stream ID (default)',
                            'channel_id' => 'Channel Number (recommended for HDHR)',
                            'name' => 'Channel Name',
                            'title' => 'Channel Title',
                        ])
                        ->required()
                        ->default('stream_id') // Default to stream_id
                        ->columnSpan(1),
                    Forms\Components\Toggle::make('dummy_epg_category')
                        ->label('Channel group as category')
                        ->columnSpan(1)
                        ->inline(false)
                        ->default(false)
                        ->helperText('When enabled, the channel group will be assigned to the dummy EPG as a <category> tag.')
                        ->hidden(fn(Get $get): bool => !$get('dummy_epg')),
                    Forms\Components\TextInput::make('dummy_epg_length')
                        ->label('Dummy program length (in minutes)')
                        ->columnSpan(1)
                        ->rules(['min:1'])
                        ->type('number')
                        ->default(120)
                        ->hidden(fn(Get $get): bool => !$get('dummy_epg')),
                ]),
        ];

        // Return sections and fields
        return [
            'Name' => $nameFields,
            'Type' => $typeFields,
            'Scheduling' => $schedulingFields,
            'Processing' => $processingFields,
            'Output' => $outputFields,
        ];
    }

    public static function getForm(): array
    {
        $tabs = [];
        foreach (self::getFormSections(creating: false) as $section => $fields) {
            if ($section === 'Name') {
                $section = 'General';
            }
            $tabs[] = Forms\Components\Tabs\Tab::make($section)
                ->schema($fields);
        }
        return [
            Forms\Components\Grid::make()
                ->columns(5)
                ->schema([
                    Forms\Components\Tabs::make()
                        ->tabs($tabs)
                        ->columnSpan(3)
                        ->persistTabInQueryString(),
                    Forms\Components\Grid::make()
                        ->columns(2)
                        ->columnSpan(2)
                        ->schema([
                            Forms\Components\Section::make('Auth')
                                ->description('Add authentication to your playlist.')
                                ->icon('heroicon-m-key')
                                ->collapsible()
                                ->collapsed(true)
                                ->columnSpan(2)
                                ->schema([
                                    Forms\Components\Select::make('auth')
                                        ->relationship('playlistAuths', 'playlist_auths.name')
                                        ->label('Assigned Auth(s)')
                                        ->multiple()
                                        ->searchable()
                                        ->preload()
                                        ->helperText('NOTE: only the first enabled auth will be used if multiple assigned.'),
                                ]),
                            Forms\Components\Section::make('Links')
                                ->icon('heroicon-m-link')
                                ->collapsible()
                                ->columnSpan(2)
                                ->collapsed(false)
                                ->schema([
                                    Forms\Components\Toggle::make('short_urls_enabled')
                                        ->label('Use Short URLs')
                                        ->helperText('When enabled, short URLs will be used for the playlist links. Save changes to generate the short URLs (or remove them).')
                                        ->columnSpan(2)
                                        ->inline(false)
                                        ->default(false),
                                    PlaylistM3uUrl::make('m3u_url')
                                        ->label('M3U URLs')
                                        ->columnSpan(2)
                                        ->dehydrated(false), // don't save the value in the database
                                    PlaylistEpgUrl::make('epg_url')
                                        ->label('EPG URLs')
                                        ->columnSpan(2)
                                        ->dehydrated(false) // don't save the value in the database
                                ]),
                        ])
                ])->columnSpanFull(),

        ];
    }

    public static function getFormSteps(): array
    {
        $wizard = [];
        foreach (self::getFormSections(creating: true) as $step => $fields) {
            $wizard[] = Forms\Components\Wizard\Step::make($step)
                ->schema($fields);
        }
        return $wizard;
    }
}
