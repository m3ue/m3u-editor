<?php

namespace App\Filament\Resources;

use App\Enums\EpgSourceType;
use App\Enums\Status;
use App\Filament\Resources\EpgResource\Pages;
use App\Filament\Resources\EpgResource\RelationManagers;
use App\Models\Epg;
use App\Rules\CheckIfUrlOrLocalPath;
use App\Services\SchedulesDirectService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use RyanChandler\FilamentProgressColumn\ProgressColumn;

class EpgResource extends Resource
{
    protected static ?string $model = Epg::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'url'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', Auth::id());
    }

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $label = 'EPG';
    protected static ?string $pluralLabel = 'EPGs';

    protected static ?string $navigationGroup = 'EPG';

    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('url')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                Tables\Columns\TextColumn::make('channels_count')
                    ->label('Channels')
                    ->counts('channels')
                    ->toggleable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->sortable()
                    ->toggleable()
                    ->badge()
                    ->color(fn(Status $state) => $state->getColor()),
                Tables\Columns\IconColumn::make('is_cached')
                    ->label('Cached')
                    ->boolean()
                    ->toggleable()
                    ->sortable(),
                ProgressColumn::make('progress')
                    ->sortable()
                    ->poll(fn($record) => $record->status === Status::Processing || $record->status === Status::Pending ? '3s' : null)
                    ->toggleable(),
                ProgressColumn::make('sd_progress')
                    ->label('Schedules Direct')
                    ->sortable()
                    ->poll(fn($record) => $record->status === Status::Processing || $record->status === Status::Pending ? '3s' : null)
                    ->toggleable(),
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
                    Tables\Actions\Action::make('process')
                        ->label('Process')
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Processing,
                                'progress' => 0,
                            ]);
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ProcessEpgImport($record, force: true));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('EPG is processing')
                                ->body('EPG is being processed in the background. Depending on the size of the guide data, this may take a while. You will be notified on completion.')
                                ->duration(10000)
                                ->send();
                        })
                        ->disabled(fn($record): bool => $record->status === Status::Processing)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process EPG now?')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Tables\Actions\Action::make('cache')
                        ->label('Generate Cache')
                        ->icon('heroicon-o-arrows-pointing-in')
                        ->action(function ($record) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\GenerateEpgCache($record->uuid, notify: true));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('EPG Cache is being generated')
                                ->body('EPG Cache is being generated in the background. You will be notified when complete.')
                                ->duration(5000)
                                ->send();
                        })
                        ->disabled(fn($record) => $record->status === \App\Enums\Status::Processing)
                        ->requiresConfirmation()
                        ->modalDescription('Generate EPG Cache now? This will create a cache for the EPG data.')
                        ->modalSubmitActionLabel('Yes, generate cache now'),
                    Tables\Actions\Action::make('Download EPG')
                        ->label('Download EPG')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn($record) => route('epg.file', ['uuid' => $record->uuid]))
                        ->openUrlInNewTab(),
                    Tables\Actions\Action::make('reset')
                        ->label('Reset status')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Pending,
                                'processing' => false,
                                'progress' => 0,
                                'sd_progress' => 0,
                                'synced' => null,
                                'errors' => null,
                            ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('EPG status reset')
                                ->body('EPG status has been reset.')
                                ->duration(3000)
                                ->send();
                        })
                        // ->disabled(fn($record): bool => ! $record->auto_sync)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription('Reset EPG status so it can be processed again. Only perform this action if you are having problems with the EPG syncing.')
                        ->modalSubmitActionLabel('Yes, reset now'),
                    Tables\Actions\DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm'),
                Tables\Actions\EditAction::make()->slideOver()
                    ->button()->hiddenLabel()->size('sm'),
                Tables\Actions\ViewAction::make()
                    ->button()->hiddenLabel()->size('sm'),
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
                                    ->dispatch(new \App\Jobs\ProcessEpgImport($record, force: true));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected EPGs are processing')
                                ->body('The selected EPGs are being processed in the background. Depending on the size of the guide data, this may take a while.')
                                ->duration(10000)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription('Process the selected epg(s) now?')
                        ->modalSubmitActionLabel('Yes, process now'),

                    Tables\Actions\BulkAction::make('cache')
                        ->label('Generate Cache')
                        ->icon('heroicon-o-arrows-pointing-in')
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new \App\Jobs\GenerateEpgCache($record->uuid, notify: true));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('EPG Cache is being generated for selected EPGs')
                                ->body('EPG Cache is being generated in the background for the selected EPGs. You will be notified when complete.')
                                ->duration(5000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalDescription('Generate EPG Cache now? This will create a cache for the EPG data.')
                        ->modalSubmitActionLabel('Yes, generate cache now'),

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
            'index' => Pages\ListEpgs::route('/'),
            // 'create' => Pages\CreateEpg::route('/create'),
            'view' => Pages\ViewEpg::route('/{record}'),
            // 'edit' => Pages\EditEpg::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->columnSpan(1)
                ->required()
                ->helperText('Enter the name of the EPG. Internal use only.')
                ->maxLength(255),
            Forms\Components\ToggleButtons::make('source_type')
                ->label('EPG type')
                ->columnSpan(1)
                ->grouped()
                ->options([
                    'url' => 'File, URL or Path',
                    'schedules_direct' => 'Schedules Direct',
                ])
                ->icons([
                    'url' => 'heroicon-s-link',
                    'schedules_direct' => 'heroicon-s-bolt',
                ])
                ->default('url')
                ->live()
                ->hiddenOn('edit')
                ->helperText('Choose between URL/file upload or Schedules Direct integration'),

            // Schedules Direct Configuration
            Forms\Components\Section::make('Schedules Direct Configuration')
                ->description('Configure your Schedules Direct account settings')
                ->headerActions([
                    Forms\Components\Actions\Action::make('Schedules Direct')
                        ->label('Schedules Direct')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->iconPosition('after')
                        ->size('sm')
                        ->url('https://www.schedulesdirect.org/')
                        ->openUrlInNewTab(true),
                ])
                ->visible(fn(Get $get): bool => $get('source_type') === EpgSourceType::SCHEDULES_DIRECT->value)
                ->schema([
                    Forms\Components\Grid::make()
                        ->columns(2)
                        ->schema([
                            Forms\Components\TextInput::make('sd_username')
                                ->label('Username')
                                ->required(fn(Get $get): bool => $get('source_type') === EpgSourceType::SCHEDULES_DIRECT->value),
                            Forms\Components\TextInput::make('sd_password')
                                ->label('Password')
                                ->password()
                                ->revealable()
                                ->required(fn(Get $get): bool => $get('source_type') === EpgSourceType::SCHEDULES_DIRECT->value),
                        ]),

                    Forms\Components\Grid::make()
                        ->columns(2)
                        ->schema([
                            Forms\Components\Select::make('sd_country')
                                ->label('Country')
                                ->required(fn(Get $get): bool => $get('source_type') === EpgSourceType::SCHEDULES_DIRECT->value)
                                ->options([
                                    'USA' => 'United States',
                                    'CAN' => 'Canada',
                                ])
                                ->default('USA')
                                ->live(),
                            Forms\Components\TextInput::make('sd_postal_code')
                                ->label('Postal Code')
                                ->required(fn(Get $get): bool => $get('source_type') === EpgSourceType::SCHEDULES_DIRECT->value),
                        ]),

                    Forms\Components\Grid::make()
                        ->columns(2)
                        ->schema([
                            Forms\Components\Select::make('sd_lineup_id')
                                ->label('Lineup')
                                ->helperText('Select your Schedules Direct lineup')
                                ->searchable()
                                ->getSearchResultsUsing(function (string $search, Get $get, SchedulesDirectService $service) {
                                    $country = $get('sd_country');
                                    $postalCode = $get('sd_postal_code');
                                    $username = $get('sd_username');
                                    $password = $get('sd_password');

                                    if (!$country || !$postalCode || !$username || !$password) {
                                        return [];
                                    }

                                    try {
                                        $authData = $service->authenticate($username, $password);

                                        // Get account lineups first
                                        $accountLineups = [];
                                        try {
                                            $userLineups = $service->getUserLineups($authData['token']);
                                            $accountLineups = $userLineups['lineups'] ?? [];
                                        } catch (\Exception $e) {
                                            // If we can't get account lineups, fall back to headend search
                                        }

                                        $options = [];

                                        // First, add account lineups that match the search
                                        foreach ($accountLineups as $lineup) {
                                            if (stripos($lineup['name'], $search) !== false) {
                                                $options[$lineup['lineup']] = "✅ {$lineup['name']} (In Account)";
                                            }
                                        }

                                        // Then add available lineups from headends
                                        $headends = $service->getHeadends($authData['token'], $country, $postalCode);
                                        foreach ($headends as $headend) {
                                            foreach ($headend['lineups'] as $lineup) {
                                                if (stripos($lineup['name'], $search) !== false) {
                                                    // Don't duplicate if already in account
                                                    if (!isset($options[$lineup['lineup']])) {
                                                        $options[$lineup['lineup']] = "⚠️ {$lineup['name']} ({$headend['transport']}) - Not Added";
                                                    }
                                                }
                                            }
                                        }

                                        return $options;
                                    } catch (\Exception $e) {
                                        return [];
                                    }
                                })
                                ->getOptionLabelUsing(function ($value, Get $get, SchedulesDirectService $service) {
                                    try {
                                        $country = $get('sd_country');
                                        $postalCode = $get('sd_postal_code');
                                        $username = $get('sd_username');
                                        $password = $get('sd_password');

                                        if (!$country || !$postalCode || !$username || !$password) {
                                            return $value;
                                        }

                                        $authData = $service->authenticate($username, $password);

                                        // Check account lineups first
                                        try {
                                            $userLineups = $service->getUserLineups($authData['token']);
                                            foreach ($userLineups['lineups'] ?? [] as $lineup) {
                                                if ($lineup['lineup'] === $value) {
                                                    return "✅ {$lineup['name']} (In Account)";
                                                }
                                            }
                                        } catch (\Exception $e) {
                                            // Continue to headend search
                                        }

                                        // Check available lineups
                                        $headends = $service->getHeadends($authData['token'], $country, $postalCode);
                                        foreach ($headends as $headend) {
                                            foreach ($headend['lineups'] as $lineup) {
                                                if ($lineup['lineup'] === $value) {
                                                    return "⚠️ {$lineup['name']} ({$headend['transport']}) - Not Added";
                                                }
                                            }
                                        }
                                        return $value;
                                    } catch (\Exception $e) {
                                        return $value;
                                    }
                                }),
                            Forms\Components\TextInput::make('sd_days_to_import')
                                ->label('Days to Import')
                                ->numeric()
                                ->default(3)
                                ->minValue(1)
                                ->maxValue(14)
                                ->helperText('Number of days to import from Schedules Direct (1-14)')
                                ->required(fn(Get $get): bool => $get('source_type') === EpgSourceType::SCHEDULES_DIRECT->value),
                        ]),

                    Forms\Components\Grid::make()
                        ->columns(2)
                        ->schema([
                            Forms\Components\Actions::make([
                                Forms\Components\Actions\Action::make('test_connection')
                                    ->label('Test Connection')
                                    ->icon('heroicon-o-wifi')
                                    ->color('gray')
                                    ->action(function (Get $get, SchedulesDirectService $service) {
                                        $username = $get('sd_username');
                                        $password = $get('sd_password');

                                        if (!$username || !$password) {
                                            Notification::make()
                                                ->danger()
                                                ->title('Missing credentials')
                                                ->body('Please enter username and password first')
                                                ->send();
                                            return;
                                        }

                                        try {
                                            $authData = $service->authenticate($username, $password);

                                            Notification::make()
                                                ->success()
                                                ->title('Connection successful!')
                                                ->body("Token expires: " . date('Y-m-d H:i:s', $authData['expires']))
                                                ->send();
                                        } catch (\Exception $e) {
                                            Notification::make()
                                                ->danger()
                                                ->title('Connection failed')
                                                ->body($e->getMessage())
                                                ->send();
                                        }
                                    }),
                                Forms\Components\Actions\Action::make('browse_lineups')
                                    ->label('View Lineups')
                                    ->icon('heroicon-o-tv')
                                    ->color('gray')
                                    ->action(function (Get $get, SchedulesDirectService $service) {
                                        $country = $get('sd_country');
                                        $postalCode = $get('sd_postal_code');
                                        $username = $get('sd_username');
                                        $password = $get('sd_password');

                                        if (!$country || !$postalCode || !$username || !$password) {
                                            Notification::make()
                                                ->warning()
                                                ->title('Missing information')
                                                ->body('Please fill in all required fields first')
                                                ->send();
                                            return;
                                        }

                                        try {
                                            $authData = $service->authenticate($username, $password);
                                            $headends = $service->getHeadends($authData['token'], $country, $postalCode);

                                            // Get account lineups to see which are already added
                                            $accountLineups = [];
                                            try {
                                                $userLineups = $service->getUserLineups($authData['token']);
                                                $accountLineups = collect($userLineups['lineups'] ?? [])->pluck('lineup')->toArray();
                                            } catch (\Exception $e) {
                                                // Continue without account lineup info
                                            }

                                            $lineupCount = 0;
                                            $lineupList = '';
                                            foreach ($headends as $headend) {
                                                foreach ($headend['lineups'] as $lineup) {
                                                    $lineupCount++;
                                                    $status = in_array($lineup['lineup'], $accountLineups) ? ' ✅ (In Account)' : ' ⚠️ (Not Added)';
                                                    $lineupList .= "• {$lineup['name']} ({$headend['transport']}){$status}\n";
                                                }
                                            }

                                            Notification::make()
                                                ->success()
                                                ->title("Found {$lineupCount} available lineups")
                                                ->body($lineupList ?: 'No lineups found for your location')
                                                ->persistent()
                                                ->send();
                                        } catch (\Exception $e) {
                                            Notification::make()
                                                ->danger()
                                                ->title('Failed to fetch lineups')
                                                ->body($e->getMessage())
                                                ->send();
                                        }
                                    }),
                                Forms\Components\Actions\Action::make('add_lineup')
                                    ->label('Add Lineup to Account')
                                    ->icon('heroicon-o-plus')
                                    ->color('success')
                                    ->action(function (Get $get, SchedulesDirectService $service) {
                                        $username = $get('sd_username');
                                        $password = $get('sd_password');
                                        $lineupId = $get('sd_lineup_id');

                                        if (!$username || !$password || !$lineupId) {
                                            Notification::make()
                                                ->warning()
                                                ->title('Missing information')
                                                ->body('Please enter credentials and select a lineup first')
                                                ->send();
                                            return;
                                        }

                                        try {
                                            $authData = $service->authenticate($username, $password);
                                            $result = $service->addLineup($authData['token'], $lineupId);

                                            Notification::make()
                                                ->success()
                                                ->title('Lineup added successfully!')
                                                ->body("Lineup {$lineupId} has been added to your Schedules Direct account")
                                                ->send();
                                        } catch (\Exception $e) {
                                            Notification::make()
                                                ->danger()
                                                ->title('Failed to add lineup')
                                                ->body($e->getMessage())
                                                ->send();
                                        }
                                    })
                            ]),
                        ]),
                ]),

            // URL/File Configuration
            Forms\Components\Section::make('XMLTV File, URL or Path')
                ->description('You can either upload an XMLTV file or provide a URL to an XMLTV file. File should conform to the XMLTV format.')
                ->headerActions([
                    Forms\Components\Actions\Action::make('XMLTV Format')
                        ->label('XMLTV Format')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->iconPosition('after')
                        ->size('sm')
                        // ->url('https://wiki.xmltv.org/index.php/XMLTVFormat')
                        ->url('https://github.com/XMLTV/xmltv/blob/master/xmltv.dtd')
                        ->openUrlInNewTab(true),
                ])
                ->visible(fn(Get $get): bool => $get('source_type') === EpgSourceType::URL->value || !$get('source_type'))
                ->schema([
                    Forms\Components\TextInput::make('url')
                        ->label('URL or Local file path')
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->helperText('Enter the URL of the XMLTV guide data. If this is a local file, you can enter a full or relative path. If changing URL, the guide data will be re-imported. Use with caution as this could lead to data loss if the new guide differs from the old one.')
                        ->requiredWithout('uploads')
                        ->required(fn(Get $get): bool => $get('source_type') === EpgSourceType::URL->value && !$get('uploads'))
                        ->rules([new CheckIfUrlOrLocalPath()])
                        ->maxLength(255),
                    Forms\Components\FileUpload::make('uploads')
                        ->label('File')
                        ->disk('local')
                        ->directory('epg')
                        ->helperText('Upload the XMLTV file for the EPG. This will be used to import the guide data.')
                        ->rules(['file'])
                        ->required(fn(Get $get): bool => $get('source_type') === EpgSourceType::URL->value && !$get('url')),

                    Forms\Components\Grid::make()
                        ->columns(3)
                        ->columnSpanFull()
                        ->schema([
                            Forms\Components\TextInput::make('user_agent')
                                ->helperText('User agent string to use for fetching the EPG.')
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


            Forms\Components\Section::make('Scheduling')
                ->description('Auto sync and scheduling options')
                ->columns(3)
                ->schema([
                    Forms\Components\Toggle::make('auto_sync')
                        ->label('Automatically sync EPG')
                        ->helperText('When enabled, the EPG will be automatically re-synced at the specified interval.')
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
                        ])->hidden(fn(Get $get): bool => ! $get('auto_sync')),
                    Forms\Components\DateTimePicker::make('synced')
                        ->columnSpan(3)
                        ->suffix('UTC')
                        ->native(false)
                        ->label('Last Synced')
                        ->hidden(fn(Get $get, string $operation): bool => ! $get('auto_sync') || $operation === 'create')
                        ->helperText('EPG will be synced at the specified interval. Timestamp is automatically updated after each sync. Set to any time in the past (or future) and the next sync will run when the defined interval has passed since the time set.'),

                ]),

            Forms\Components\Section::make('Mapping')
                ->description('Settings used when mapping EPG to a Playlist.')
                ->schema([
                    Forms\Components\TextInput::make('preferred_local')
                        ->label('Preferred Locale')
                        ->prefixIcon('heroicon-m-language')
                        ->placeholder('en')
                        ->helperText('Entered your desired locale - if you\'re not sure what to put here, look at your EPG source. If you see entries like "CHANNEL.en", then "en" would be a good choice if you prefer english. This is used when mapping the EPG to a playlist. If the EPG has multiple locales, this will be used as the preferred locale when a direct match is not found.')
                        ->maxLength(10),
                ])
        ];
    }
}
