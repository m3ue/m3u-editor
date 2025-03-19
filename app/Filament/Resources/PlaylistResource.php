<?php

namespace App\Filament\Resources;

use App\Enums\PlaylistStatus;
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

class PlaylistResource extends Resource
{
    protected static ?string $model = Playlist::class;

    protected static ?string $recordTitleAttribute = 'name';

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
                Tables\Columns\IconColumn::make('enable_proxy')
                    ->label('Proxy')
                    ->icon(fn(string $state): string => match ($state) {
                        '1' => 'heroicon-o-shield-check',
                        '0' => 'heroicon-o-shield-exclamation',
                    })->color(fn(string $state): string => match ($state) {
                        '1' => 'success',
                        '0' => 'gray',
                    })->toggleable()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->badge()
                    ->toggleable()
                    ->color(fn(PlaylistStatus $state) => $state->getColor()),
                ProgressColumn::make('progress')
                    ->sortable()
                    ->poll(fn($record) => $record->status === PlaylistStatus::Processing || $record->status === PlaylistStatus::Pending ? '3s' : null)
                    ->toggleable(),
                Tables\Columns\IconColumn::make('auto_sync')
                    ->label('Auto Sync')
                    ->icon(fn(string $state): string => match ($state) {
                        '1' => 'heroicon-o-check-circle',
                        '0' => 'heroicon-o-minus-circle',
                    })->color(fn(string $state): string => match ($state) {
                        '1' => 'success',
                        '0' => 'danger',
                    })->toggleable()->sortable(),
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
                                $expires = Carbon::createFromTimestamp($record->xtream_status['user_info']['exp_date']);
                                return $expires->toDayDateTimeString();
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
                        ->label('Process')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($record) {
                            $record->update([
                                'status' => PlaylistStatus::Processing,
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
                        ->disabled(fn($record): bool => $record->status === PlaylistStatus::Processing)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process playlist now?')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\Action::make('Download M3U')
                        ->label('Download M3U')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => route('playlist.generate', ['uuid' => $record->uuid]))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('Download M3U')
                        ->label('Download EPG')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => route('epg.generate', ['uuid' => $record->uuid]))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('reset')
                        ->label('Reset status')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->action(function ($record) {
                            $record->update([
                                'status' => PlaylistStatus::Pending,
                                'processing' => false,
                                'progress' => 0,
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
                        // ->disabled(fn($record): bool => ! $record->auto_sync)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription('Reset playlist status so it can be processed again. Only perform this action if you are having problems with the playlist syncing.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    Tables\Actions\DeleteAction::make(),
                ])->button()->hiddenLabel(),
            ], position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('process')
                        ->label('Process selected')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'status' => PlaylistStatus::Processing,
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

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])->checkIfRecordIsSelectableUsing(
                fn($record): bool => $record->status !== PlaylistStatus::Processing,
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
            'index' => Pages\ListPlaylists::route('/'),
            'create' => Pages\CreatePlaylist::route('/create'),
            'edit' => Pages\EditPlaylist::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [];
    }

    public static function getFormSteps(): array
    {
        return [
            Forms\Components\Wizard\Step::make('Name')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->helperText('Enter the name of the playlist. Internal use only.')
                        ->required(),
                    Forms\Components\Section::make('Links')
                        ->description('These links are generated based on the current playlist configuration. Only enabled channels will be included.')
                        ->schema([
                            PlaylistM3uUrl::make('m3u_url')
                                ->columnSpan(2)
                                ->dehydrated(false), // don't save the value in the database
                            PlaylistEpgUrl::make('epg_url')
                                ->columnSpan(2)
                                ->dehydrated(false) // don't save the value in the database
                        ])->hiddenOn(['create'])
                ]),

            Forms\Components\Wizard\Step::make('Type')
                ->description('m3u8, Xtream, or local file')
                ->schema([
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
                                ->helperText('Enter the full url, using <url>:<port> format - without trailing slash (/).')
                                ->prefixIcon('heroicon-m-globe-alt')
                                ->maxLength(255)
                                ->url()
                                ->columnSpan(2)
                                ->required()
                                ->hidden(fn(Get $get): bool => !$get('xtream')),

                            Forms\Components\Grid::make()
                                ->columns(3)
                                ->columnSpanFull()
                                ->schema([
                                    Forms\Components\TextInput::make('xtream_config.username')
                                        ->label('Xtream API Username')
                                        ->required()
                                        ->columnSpan(1)
                                        ->hidden(fn(Get $get): bool => !$get('xtream')),
                                    Forms\Components\TextInput::make('xtream_config.password')
                                        ->label('Xtream API Password')
                                        ->required()
                                        ->columnSpan(1)
                                        ->password()
                                        ->revealable()
                                        ->hidden(fn(Get $get): bool => !$get('xtream')),
                                    Forms\Components\Select::make('xtream_config.output')
                                        ->label('Output')
                                        ->required()
                                        ->columnSpan(1)
                                        ->options([
                                            'ts' => 'MPEG-TS (.ts)',
                                            'm3u8' => 'HLS (.m3u8)',
                                        ])->default('ts')
                                        ->hidden(fn(Get $get): bool => !$get('xtream')),
                                ]),

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
                                ->inline(false)
                                ->default(false),
                        ])
                ]),

            Forms\Components\Wizard\Step::make('Scheduling')
                ->description('automatic sync settings')
                ->schema([
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
                ]),

            Forms\Components\Wizard\Step::make('Processing')
                ->schema([
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
                                ->helperText('You will need to manually run the sync if updating the groups to import. If the list is empty, process the list and check again once complete.')
                                ->options(function (Get $get): array {
                                    $options = [];
                                    foreach ($get('groups') ?? [] as $option) {
                                        $options[$option] = $option;
                                    }
                                    return $options;
                                })
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
                ]),
            Forms\Components\Wizard\Step::make('Output')
                ->schema([
                    Forms\Components\Grid::make()
                        ->columns(2)
                        ->columnSpanFull()
                        ->schema([
                            Forms\Components\Toggle::make('auto_sort')
                                ->label('Automatically assign sort number based on playlist order')
                                ->columnSpan(1)
                                ->inline(false)
                                ->default(true)
                                ->helperText('NOTE: You will need to re-sync your playlist, or wait for the next scheduled sync, if changing this. This will overwrite any existing channel sort order customization for this playlist.'),
                            Forms\Components\Toggle::make('enable_proxy')
                                ->label('Enable Proxy')
                                ->hint(fn(Get $get): string => $get('enable_proxy') ? 'Proxied' : 'Not proxied')
                                ->hintIcon(fn(Get $get): string => !$get('enable_proxy') ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                                ->columnSpan(1)
                                ->live()
                                ->inline(false)
                                ->default(false)
                                ->helperText('When enabled, playlists urls will be proxied through m3u editor and streamed via ffmpeg.'),
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
                        ])
                ]),
        ];
    }
}
