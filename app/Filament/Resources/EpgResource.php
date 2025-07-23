<?php

namespace App\Filament\Resources;

use App\Enums\Status;
use App\Filament\Resources\EpgResource\Pages;
use App\Filament\Resources\EpgResource\RelationManagers;
use App\Models\Epg;
use App\Rules\CheckIfUrlOrLocalPath;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
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
            ->where('user_id', auth()->id());
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
                ProgressColumn::make('progress')
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
                    Tables\Actions\EditAction::make()->slideOver(),
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
                // Tables\Actions\ViewAction::make()
                //     ->button()->hiddenLabel()->size('sm'),
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
            // 'view' => Pages\ViewEpg::route('/{record}'),
            // 'create' => Pages\CreateEpg::route('/create'),
            // 'edit' => Pages\EditEpg::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->columnSpan('full')
                ->required()
                ->helperText('Enter the name of the EPG. Internal use only.')
                ->maxLength(255),

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

            Forms\Components\Section::make('XMLTV file or URL/file path')
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
                ->schema([
                    Forms\Components\TextInput::make('url')
                        ->label('URL or Local file path')
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->helperText('Enter the URL of the XMLTV guide data. If this is a local file, you can enter a full or relative path. If changing URL, the guide data will be re-imported. Use with caution as this could lead to data loss if the new guide differs from the old one.')
                        ->requiredWithout('uploads')
                        ->rules([new CheckIfUrlOrLocalPath()])
                        ->maxLength(255),
                    Forms\Components\FileUpload::make('uploads')
                        ->label('File')
                        ->disk('local')
                        ->directory('epg')
                        ->helperText('Upload the XMLTV file for the EPG. This will be used to import the guide data.')
                        ->rules(['file'])
                        ->requiredWithout('url'),

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
