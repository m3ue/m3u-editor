<?php

namespace App\Filament\Resources\Epgs;

use App\Enums\EpgSourceType;
use App\Enums\Status;
use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\EpgResource\Pages;
use App\Filament\Resources\Epgs\Pages\ListEpgs;
use App\Filament\Resources\Epgs\Pages\ViewEpg;
use App\Jobs\GenerateEpgCache;
use App\Jobs\ProcessEpgImport;
use App\Models\Epg;
use App\Rules\CheckIfUrlOrLocalPath;
use App\Rules\Cron;
use App\Services\DateFormatService;
use App\Services\SchedulesDirectService;
use App\Tables\Columns\ProgressColumn;
use App\Traits\HasUserFiltering;
use Cron\CronExpression;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class EpgResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
    use HasUserFiltering;

    protected static ?string $model = Epg::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'url'];
    }

    protected static ?string $label = 'EPG';

    protected static ?string $pluralLabel = 'EPGs';

    public static function getNavigationGroup(): ?string
    {
        return __('EPG');
    }

    public static function getModelLabel(): string
    {
        return __('EPG');
    }

    public static function getPluralModelLabel(): string
    {
        return __('EPGs');
    }

    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('is_merged', false);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('is_merged', false);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table->persistSortInSession()
            ->reorderableColumns()
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount([
                    'channels',
                ]);
            })
            ->deferLoading()
            ->columns([
                TextColumn::make('id')
                    ->searchable()
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('url')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                TextColumn::make('channels_count')
                    ->label(__('Channels'))
                    ->counts('channels')
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable()
                    ->toggleable()
                    ->badge()
                    ->color(fn (Status $state) => $state->getColor()),
                ProgressColumn::make('progress')
                    ->label(__('Sync Progress'))
                    ->tooltip(__('Progress of EPG import/sync'))
                    ->sortable()
                    ->poll(fn ($record) => $record->status === Status::Processing || $record->status === Status::Pending ? '3s' : null)
                    ->toggleable(),
                ProgressColumn::make('cache_progress')
                    ->label(__('Cache Progress'))
                    ->tooltip(__('Progress of EPG cache generation'))
                    ->sortable()
                    ->poll(fn ($record) => $record->status === Status::Processing || $record->status === Status::Pending ? '3s' : null)
                    ->toggleable(),
                ProgressColumn::make('sd_progress')
                    ->label(__('SD Progress'))
                    ->tooltip(__('Progress of SchedulesDirect import (if using)'))
                    ->sortable()
                    ->poll(fn ($record) => $record->status === Status::Processing || $record->status === Status::Pending ? '3s' : null)
                    ->toggleable(),
                IconColumn::make('is_cached')
                    ->label(__('Cached'))
                    ->boolean()
                    ->toggleable()
                    ->sortable(),
                ToggleColumn::make('auto_sync')
                    ->label(__('Auto Sync'))
                    ->toggleable()
                    ->tooltip(__('Toggle auto-sync status'))
                    ->sortable(),
                TextColumn::make('synced')
                    ->label(__('Last Synced'))
                    ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('sync_interval')
                    ->label(__('Next Sync'))
                    ->toggleable()
                    ->formatStateUsing(function ($state, $record) {
                        if ($record->auto_sync && $record->sync_interval && CronExpression::isValidExpression($record->sync_interval)) {
                            return (new CronExpression($record->sync_interval))->getNextRunDate()->format(app(DateFormatService::class)->getFormat());
                        }

                        return 'N/A';
                    })
                    ->sortable(),
                TextColumn::make('sync_time')
                    ->label(__('Sync Time'))
                    ->formatStateUsing(fn (string $state): string => gmdate('H:i:s', (int) $state))
                    ->toggleable()
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
                    Action::make('process')
                        ->label(__('Process'))
                        ->icon('heroicon-o-arrow-path')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Processing,
                                'progress' => 0,
                                'sd_progress' => 0,
                                'cache_progress' => 0,
                            ]);
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new ProcessEpgImport($record, force: true));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('EPG is processing'))
                                ->body(__('EPG is being processed in the background. Depending on the size of the guide data, this may take a while. You will be notified on completion.'))
                                ->duration(10000)
                                ->send();
                        })
                        ->disabled(fn ($record): bool => $record->status === Status::Processing)
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription(__('Process EPG now?'))
                        ->modalSubmitActionLabel(__('Yes, process now')),
                    Action::make('cache')
                        ->label(__('Generate Cache'))
                        ->icon('heroicon-o-arrows-pointing-in')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Processing,
                                'cache_progress' => 0,
                            ]);
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new GenerateEpgCache($record->uuid, notify: true));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('EPG Cache is being generated'))
                                ->body(__('EPG Cache is being generated in the background. You will be notified when complete.'))
                                ->duration(5000)
                                ->send();
                        })
                        ->disabled(fn ($record) => $record->status === Status::Processing)
                        ->requiresConfirmation()
                        ->modalDescription(__('Generate EPG Cache now? This will create a cache for the EPG data.'))
                        ->modalSubmitActionLabel(__('Yes, generate cache now')),
                    Action::make('Download EPG')
                        ->label(__('Download EPG'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(fn ($record) => route('epg.file', ['uuid' => $record->uuid]))
                        ->openUrlInNewTab(),
                    Action::make('reset')
                        ->label(__('Reset status'))
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->action(function ($record) {
                            $record->update([
                                'status' => Status::Pending,
                                'processing' => false,
                                'progress' => 0,
                                'sd_progress' => 0,
                                'cache_progress' => 0,
                                'synced' => null,
                                'errors' => null,
                            ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('EPG status reset'))
                                ->body(__('EPG status has been reset.'))
                                ->duration(3000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription(__('Reset EPG status so it can be processed again. Only perform this action if you are having problems with the EPG syncing.'))
                        ->modalSubmitActionLabel(__('Yes, reset now')),
                    DeleteAction::make(),
                ])->button()->hiddenLabel()->size('sm'),
                EditAction::make()->slideOver()
                    ->button()->hiddenLabel()->size('sm'),
                ViewAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('process')
                        ->label(__('Process selected'))
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'status' => Status::Processing,
                                    'progress' => 0,
                                    'sd_progress' => 0,
                                    'cache_progress' => 0,
                                ]);
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new ProcessEpgImport($record, force: true));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Selected EPGs are processing'))
                                ->body(__('The selected EPGs are being processed in the background. Depending on the size of the guide data, this may take a while.'))
                                ->duration(10000)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-path')
                        ->modalIcon('heroicon-o-arrow-path')
                        ->modalDescription(__('Process the selected epg(s) now?'))
                        ->modalSubmitActionLabel(__('Yes, process now')),

                    BulkAction::make('cache')
                        ->label(__('Generate Cache'))
                        ->icon('heroicon-o-arrows-pointing-in')
                        ->action(function ($records) {
                            foreach ($records as $record) {
                                $record->update([
                                    'status' => Status::Processing,
                                    'cache_progress' => 0,
                                ]);
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new GenerateEpgCache($record->uuid, notify: true));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('EPG Cache is being generated for selected EPGs'))
                                ->body(__('EPG Cache is being generated in the background for the selected EPGs. You will be notified when complete.'))
                                ->duration(5000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalDescription(__('Generate EPG Cache now? This will create a cache for the EPG data.'))
                        ->modalSubmitActionLabel(__('Yes, generate cache now')),

                    DeleteBulkAction::make(),
                ]),
            ])->checkIfRecordIsSelectableUsing(
                fn ($record): bool => $record->status !== Status::Processing,
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
            'index' => ListEpgs::route('/'),
            // 'create' => Pages\CreateEpg::route('/create'),
            'view' => ViewEpg::route('/{record}'),
            // 'edit' => Pages\EditEpg::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            TextInput::make('name')
                ->columnSpan(1)
                ->required()
                ->helperText(__('Enter the name of the EPG. Internal use only.'))
                ->maxLength(255),
            ToggleButtons::make('source_type')
                ->label(__('EPG type'))
                ->columnSpan(1)
                ->grouped()
                ->options([
                    'url' => 'File, URL or Path',
                    'schedules_direct' => 'SchedulesDirect',
                ])
                ->icons([
                    'url' => 'heroicon-s-link',
                    'schedules_direct' => 'heroicon-s-bolt',
                ])
                ->default('url')
                ->live()
                ->hiddenOn('edit')
                ->helperText(__('Choose between URL/file upload or SchedulesDirect integration')),

            // SchedulesDirect Configuration
            Section::make(__('SchedulesDirect Configuration'))
                ->description(__('Configure your SchedulesDirect account settings'))
                ->headerActions([
                    Action::make('SchedulesDirect')
                        ->label(__('SchedulesDirect'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->iconPosition('after')
                        ->size('sm')
                        ->url('https://www.schedulesdirect.org/')
                        ->openUrlInNewTab(true),
                ])
                ->visible(fn (Get $get): bool => $get('source_type') === EpgSourceType::SCHEDULES_DIRECT->value)
                ->schema([
                    Grid::make()
                        ->columns(2)
                        ->schema([
                            TextInput::make('sd_username')
                                ->label(__('Username'))
                                ->required(fn (Get $get): bool => $get('source_type') === EpgSourceType::SCHEDULES_DIRECT->value),
                            TextInput::make('sd_password')
                                ->label(__('Password'))
                                ->password()
                                ->revealable()
                                ->required(fn (Get $get): bool => $get('source_type') === EpgSourceType::SCHEDULES_DIRECT->value),
                        ]),

                    Grid::make()
                        ->columns(2)
                        ->schema([
                            Select::make('sd_country')
                                ->label(__('Country'))
                                ->required(fn (Get $get): bool => $get('source_type') === EpgSourceType::SCHEDULES_DIRECT->value)
                                ->searchable()
                                ->preload()
                                ->options(function (SchedulesDirectService $service) {
                                    try {
                                        $countries = $service->getCountries();
                                        $options = [];

                                        // Process each region
                                        foreach ($countries as $region => $regionCountries) {
                                            foreach ($regionCountries as $country) {
                                                $options[$country['shortName']] = $country['fullName'];
                                            }
                                        }

                                        return $options;
                                    } catch (Exception $e) {
                                        // Fallback to a basic list if API fails
                                        return [
                                            'USA' => 'United States',
                                            'CAN' => 'Canada',
                                        ];
                                    }
                                })
                                ->default('USA')
                                ->live(),
                            TextInput::make('sd_postal_code')
                                ->label(__('Postal Code'))
                                ->required(fn (Get $get): bool => $get('source_type') === EpgSourceType::SCHEDULES_DIRECT->value),
                        ]),

                    Grid::make()
                        ->columns(2)
                        ->schema([
                            Select::make('sd_lineup_id')
                                ->label(__('Lineup'))
                                ->helperText(__('Select your SchedulesDirect lineup'))
                                ->searchable()
                                ->getSearchResultsUsing(function (string $search, Get $get, SchedulesDirectService $service) {
                                    $country = $get('sd_country');
                                    $postalCode = $get('sd_postal_code');
                                    $username = $get('sd_username');
                                    $password = $get('sd_password');

                                    if (! $country || ! $postalCode || ! $username || ! $password) {
                                        return [];
                                    }

                                    try {
                                        // Authenticate to get fresh token
                                        $authData = $service->authenticate($username, $password);

                                        // // Get account lineups first
                                        // $accountLineups = [];
                                        // try {
                                        //     $userLineups = $service->getUserLineups($authData['token']);
                                        //     $accountLineups = $userLineups['lineups'] ?? [];
                                        // } catch (\Exception $e) {
                                        //     // If we can't get account lineups, fall back to headend search
                                        // }

                                        $options = [];

                                        // // First, add account lineups that match the search
                                        // foreach ($accountLineups as $lineup) {
                                        //     if (stripos($lineup['name'], $search) !== false) {
                                        //         $options[$lineup['lineup']] = "{$lineup['name']}";
                                        //     }
                                        // }

                                        // Then add available lineups from headends
                                        $headends = $service->getHeadends($authData['token'], $country, $postalCode);
                                        foreach ($headends as $headend) {
                                            foreach ($headend['lineups'] as $lineup) {
                                                if (stripos($lineup['name'], $search) !== false) {
                                                    // Don't duplicate if already in account
                                                    if (! isset($options[$lineup['lineup']])) {
                                                        $options[$lineup['lineup']] = "{$lineup['name']} ({$headend['transport']})";
                                                    }
                                                }
                                            }
                                        }

                                        return $options;
                                    } catch (Exception $e) {
                                        return [];
                                    }
                                })
                                ->getOptionLabelUsing(function ($value, Get $get, SchedulesDirectService $service) {
                                    try {
                                        $country = $get('sd_country');
                                        $postalCode = $get('sd_postal_code');
                                        $username = $get('sd_username');
                                        $password = $get('sd_password');

                                        if (! $country || ! $postalCode || ! $username || ! $password) {
                                            return $value;
                                        }

                                        // Authenticate to get fresh token
                                        $authData = $service->authenticate($username, $password);

                                        // Check available lineups
                                        $headends = $service->getHeadends($authData['token'], $country, $postalCode);
                                        foreach ($headends as $headend) {
                                            foreach ($headend['lineups'] as $lineup) {
                                                if ($lineup['lineup'] === $value) {
                                                    return "{$lineup['name']} ({$headend['transport']})";
                                                }
                                            }
                                        }

                                        return $value;
                                    } catch (Exception $e) {
                                        return $value;
                                    }
                                }),
                            TextInput::make('sd_days_to_import')
                                ->label(__('Days to Import'))
                                ->numeric()
                                ->default(3)
                                ->minValue(1)
                                ->maxValue(14)
                                ->helperText(__('Number of days to import from SchedulesDirect (1-14)'))
                                ->required(fn (Get $get): bool => $get('source_type') === EpgSourceType::SCHEDULES_DIRECT->value),
                            Toggle::make('sd_metadata.enabled')
                                ->label(__('Import  Metadata'))
                                ->helperText(__('Enable to import additional program images (NOTE: this can significantly increase import time)'))
                                ->default(false)
                                ->visible(fn (Get $get): bool => $get('source_type') === EpgSourceType::SCHEDULES_DIRECT->value),

                            Toggle::make('sd_debug')
                                ->label(__('Enable Debugging'))
                                ->helperText(__('This should be disabled unless directed by SchedulesDirect support'))
                                ->default(false)
                                ->hiddenOn('create')
                                ->visible(fn (Get $get): bool => $get('source_type') === EpgSourceType::SCHEDULES_DIRECT->value),
                        ]),

                    Grid::make()
                        ->columns(2)
                        ->schema([
                            Actions::make([
                                Action::make('test_connection')
                                    ->label(__('Test Connection'))
                                    ->icon('heroicon-o-wifi')
                                    ->color('gray')
                                    ->action(function (Get $get, SchedulesDirectService $service) {
                                        $username = $get('sd_username');
                                        $password = $get('sd_password');

                                        if (! $username || ! $password) {
                                            Notification::make()
                                                ->danger()
                                                ->title(__('Missing credentials'))
                                                ->body(__('Please enter username and password first'))
                                                ->send();

                                            return;
                                        }

                                        try {
                                            $authData = $service->authenticate($username, $password);

                                            Notification::make()
                                                ->success()
                                                ->title(__('Connection successful!'))
                                                ->body('Token expires: '.date('Y-m-d H:i:s', $authData['expires']))
                                                ->send();
                                        } catch (Exception $e) {
                                            Notification::make()
                                                ->danger()
                                                ->title(__('Connection failed'))
                                                ->body($e->getMessage())
                                                ->send();
                                        }
                                    }),
                                Action::make('browse_lineups')
                                    ->label(__('View Lineups'))
                                    ->icon('heroicon-o-tv')
                                    ->color('gray')
                                    ->action(function (Get $get, SchedulesDirectService $service) {
                                        $country = $get('sd_country');
                                        $postalCode = $get('sd_postal_code');
                                        $username = $get('sd_username');
                                        $password = $get('sd_password');

                                        if (! $country || ! $postalCode || ! $username || ! $password) {
                                            Notification::make()
                                                ->warning()
                                                ->title(__('Missing information'))
                                                ->body(__('Please fill in all required fields first'))
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
                                            } catch (Exception $e) {
                                                // Continue without account lineup info
                                            }

                                            $lineupCount = 0;
                                            $lineupList = '';
                                            foreach ($headends as $headend) {
                                                foreach ($headend['lineups'] as $lineup) {
                                                    $lineupCount++;
                                                    $lineupList .= "{$lineup['name']} ({$headend['transport']}) • \n";
                                                }
                                            }

                                            Notification::make()
                                                ->success()
                                                ->title("Found {$lineupCount} available lineups")
                                                ->body($lineupList ?: 'No lineups found for your location')
                                                ->persistent()
                                                ->send();
                                        } catch (Exception $e) {
                                            Notification::make()
                                                ->danger()
                                                ->title(__('Failed to fetch lineups'))
                                                ->body($e->getMessage())
                                                ->send();
                                        }
                                    }),
                                // Forms\Components\Actions\Action::make('add_lineup')
                                //     ->label(__('Add Lineup to Account'))
                                //     ->icon('heroicon-o-plus')
                                //     ->color('success')
                                //     ->action(function (Get $get, SchedulesDirectService $service) {
                                //         $username = $get('sd_username');
                                //         $password = $get('sd_password');
                                //         $lineupId = $get('sd_lineup_id');

                                //         if (!$username || !$password || !$lineupId) {
                                //             Notification::make()
                                //                 ->warning()
                                //                 ->title(__('Missing information'))
                                //                 ->body(__('Please enter credentials and select a lineup first'))
                                //                 ->send();
                                //             return;
                                //         }

                                //         try {
                                //             $authData = $service->authenticate($username, $password);
                                //             $result = $service->addLineup($authData['token'], $lineupId);

                                //             Notification::make()
                                //                 ->success()
                                //                 ->title(__('Lineup added successfully!'))
                                //                 ->body("Lineup {$lineupId} has been added to your SchedulesDirect account")
                                //                 ->send();
                                //         } catch (\Exception $e) {
                                //             Notification::make()
                                //                 ->danger()
                                //                 ->title(__('Failed to add lineup'))
                                //                 ->body($e->getMessage())
                                //                 ->send();
                                //         }
                                //     })
                            ]),
                        ]),
                ]),

            // URL/File Configuration
            Section::make(__('XMLTV File, URL or Path'))
                ->description(__('You can either upload an XMLTV file or provide a URL to an XMLTV file. File should conform to the XMLTV format.'))
                ->headerActions([
                    Action::make('XMLTV Format')
                        ->label(__('XMLTV Format'))
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->iconPosition('after')
                        ->size('sm')
                        // ->url('https://wiki.xmltv.org/index.php/XMLTVFormat')
                        ->url('https://github.com/XMLTV/xmltv/blob/master/xmltv.dtd')
                        ->openUrlInNewTab(true),
                ])
                ->visible(fn (Get $get): bool => $get('source_type') === EpgSourceType::URL->value || ! $get('source_type'))
                ->schema([
                    TextInput::make('url')
                        ->label(__('URL or Local file path'))
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->helperText(__('Enter the URL of the XMLTV guide data. If this is a local file, you can enter a full or relative path. If changing URL, the guide data will be re-imported. Use with caution as this could lead to data loss if the new guide differs from the old one.'))
                        ->requiredWithout('uploads')
                        ->required(fn (Get $get): bool => $get('source_type') === EpgSourceType::URL->value && ! $get('uploads'))
                        ->rules([new CheckIfUrlOrLocalPath])
                        ->maxLength(255),
                    FileUpload::make('uploads')
                        ->label(__('File'))
                        ->disk('local')
                        ->directory('epg')
                        ->helperText(__('Upload the XMLTV file for the EPG. This will be used to import the guide data.'))
                        ->rules(['file'])
                        ->required(fn (Get $get): bool => $get('source_type') === EpgSourceType::URL->value && ! $get('url')),

                    Grid::make()
                        ->columns(3)
                        ->columnSpanFull()
                        ->schema([
                            TextInput::make('user_agent')
                                ->helperText(__('User agent string to use for fetching the EPG.'))
                                ->default('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36')
                                ->columnSpan(2)
                                ->required(),
                            Toggle::make('disable_ssl_verification')
                                ->label(__('Disable SSL verification'))
                                ->helperText(__('Only disable this if you are having issues.'))
                                ->columnSpan(1)
                                ->onColor('danger')
                                ->inline(false)
                                ->default(false),
                        ]),
                ]),

            Section::make(__('Scheduling'))
                ->description(__('Auto sync and scheduling options'))
                ->columns(2)
                ->schema([
                    Toggle::make('auto_sync')
                        ->label(__('Automatically sync EPG'))
                        ->helperText(__('When enabled, the EPG will be automatically re-synced at the specified interval.'))
                        ->live()
                        ->inline(false)
                        ->default(true),
                    TextInput::make('sync_interval')
                        ->label(__('Sync Schedule'))
                        ->suffix(config('app.timezone'))
                        ->rules([new Cron])
                        ->live()
                        ->placeholder(__('0 */6 * * *'))
                        ->hintAction(
                            Action::make('view_cron_example')
                                ->label(__('CRON Example'))
                                ->icon('heroicon-o-arrow-top-right-on-square')
                                ->iconPosition('after')
                                ->size('sm')
                                ->url('https://crontab.guru')
                                ->openUrlInNewTab(true)
                        )
                        ->helperText(fn ($get) => $get('sync_interval') && CronExpression::isValidExpression($get('sync_interval'))
                            ? 'Next scheduled sync: '.(new CronExpression($get('sync_interval')))->getNextRunDate()->format(app(DateFormatService::class)->getFormat())
                            : 'Specify the CRON schedule for automatic sync, e.g. "0 */6 * * *".')
                        ->hidden(fn (Get $get): bool => ! $get('auto_sync')),
                    Placeholder::make('synced')
                        ->columnSpanFull()
                        ->label(__('Last Synced'))
                        ->content(fn ($record) => app(DateFormatService::class)->format($record?->synced)),
                ]),

            Section::make(__('Mapping'))
                ->description(__('Settings used when mapping EPG to a Playlist.'))
                ->schema([
                    TextInput::make('preferred_local')
                        ->label(__('Preferred Locale'))
                        ->prefixIcon('heroicon-m-language')
                        ->placeholder(__('en'))
                        ->helperText(__('Entered your desired locale - if you\\\'re not sure what to put here, look at your EPG source. If you see entries like "CHANNEL.en", then "en" would be a good choice if you prefer english. This is used when mapping the EPG to a playlist. If the EPG has multiple locales, this will be used as the preferred locale when a direct match is not found.'))
                        ->maxLength(10),
                ]),
        ];
    }
}
