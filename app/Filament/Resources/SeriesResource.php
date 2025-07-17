<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SeriesResource\Pages;
use App\Filament\Resources\SeriesResource\RelationManagers;
use App\Models\Category;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\Series;
use App\Rules\CheckIfUrlOrLocalPath;
use App\Services\XtreamService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SeriesResource extends Resource
{
    protected static ?string $model = Series::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'plot', 'genre', 'release_date', 'director'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?string $navigationGroup = 'Playlist';

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
        return self::setupTable($table);
    }

    public static function setupTable(Table $table, $relationId = null): Table
    {
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns(self::getTableColumns(showCategory: !$relationId, showPlaylist: !$relationId))
            ->filters(self::getTableFilters(showPlaylist: !$relationId))
            ->actions(self::getTableActions(), position: Tables\Enums\ActionsPosition::BeforeCells)
            ->bulkActions(self::getTableBulkActions());
    }

    public static function getTableColumns($showCategory = true, $showPlaylist = true): array
    {
        return [
            Tables\Columns\ImageColumn::make('cover')
                ->width(80)
                ->height(120)
                ->searchable(),
            Tables\Columns\TextColumn::make('name')
                ->description((fn($record) => Str::limit($record->plot, 200)))
                ->wrap()
                ->extraAttributes(['style' => 'min-width: 400px;'])
                ->searchable()
                ->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->orWhereRaw('LOWER(series.name) LIKE ?', ['%' . strtolower($search) . '%']);
                }),
            Tables\Columns\ToggleColumn::make('enabled')
                ->toggleable()
                ->tooltip('Toggle series status')
                ->sortable(),
            Tables\Columns\TextColumn::make('seasons_count')
                ->label('Seasons')
                ->counts('seasons')
                ->toggleable()
                ->sortable(),
            Tables\Columns\TextColumn::make('episodes_count')
                ->label('Episodes')
                ->counts('episodes')
                ->toggleable()
                ->sortable(),
            Tables\Columns\TextColumn::make('category.name')
                ->hidden(fn() => !$showCategory)
                ->numeric()
                ->sortable(),
            Tables\Columns\TextColumn::make('genre')
                ->searchable(),
            Tables\Columns\TextColumn::make('youtube_trailer')
                ->label('YouTube Trailer')
                ->placeholder('No trailer ID set.')
                ->url(fn($record): string => 'https://www.youtube.com/watch?v=' . $record->youtube_trailer)
                ->openUrlInNewTab()
                ->icon('heroicon-s-play'),
            Tables\Columns\TextColumn::make('release_date')
                ->searchable()
                ->formatStateUsing(function ($state, $record) {
                    try {
                        // Try to parse the release_date from the raw attributes to avoid casting issues
                        $rawDate = $record->getAttributes()['release_date'] ?? null;
                        if (!$rawDate) {
                            return null;
                        }

                        // Extract just the date part (remove any text after the date)
                        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $rawDate, $matches)) {
                            return \Carbon\Carbon::parse($matches[1])->format('Y-m-d');
                        }

                        return $rawDate; // Return as-is if no date pattern found
                    } catch (\Exception $e) {
                        // If parsing fails, return the raw value
                        return $record->getAttributes()['release_date'] ?? null;
                    }
                }),
            Tables\Columns\TextColumn::make('rating')
                ->searchable(),
            Tables\Columns\TextColumn::make('rating_5based')
                ->numeric()
                ->sortable(),
            Tables\Columns\TextColumn::make('playlist.name')
                ->numeric()
                ->sortable()
                ->hidden(fn() => !$showPlaylist),
            Tables\Columns\TextColumn::make('created_at')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('updated_at')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function getTableFilters($showPlaylist = true): array
    {
        return [
            Tables\Filters\SelectFilter::make('playlist')
                ->relationship('playlist', 'name')
                ->multiple()
                ->preload()
                ->searchable()
                ->hidden(fn() => !$showPlaylist),
            Tables\Filters\Filter::make('enabled')
                ->label('Series is enabled')
                ->toggle()
                ->query(function ($query) {
                    return $query->where('enabled', true);
                }),
        ];
    }

    public static function getTableActions(): array
    {
        return [
            Tables\Actions\ActionGroup::make([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('move')
                    ->label('Move series to category')
                    ->form([
                        Forms\Components\Select::make('category')
                            ->required()
                            ->live()
                            ->label('Category')
                            ->helperText('Select the category you would like to move the series to.')
                            ->options(fn(Get $get, $record) => Category::where(['user_id' => auth()->id(), 'playlist_id' => $record->playlist_id])->get(['name', 'id'])->pluck('name', 'id'))
                            ->searchable(),
                    ])
                    ->action(function ($record, array $data): void {
                        $category = Category::findOrFail($data['category']);
                        $record->update([
                            'category_id' => $category->id,
                        ]);
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Series moved to category')
                            ->body('The series has been moved to the chosen category.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalIcon('heroicon-o-arrows-right-left')
                    ->modalDescription('Move the series to another category.')
                    ->modalSubmitActionLabel('Move now'),
                Tables\Actions\Action::make('process')
                    ->label('Process Series')
                    ->action(function ($record) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\ProcessM3uImportSeriesEpisodes(
                                playlistSeries: $record,
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Series is being processed')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-path')
                    ->modalIcon('heroicon-o-arrow-path')
                    ->modalDescription('Process series now? This will fetch all episodes and seasons for this series.')
                    ->modalSubmitActionLabel('Yes, process now'),
                Tables\Actions\Action::make('sync')
                    ->label('Sync Series .strm files')
                    ->action(function ($record) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new \App\Jobs\SyncSeriesStrmFiles(
                                series: $record,
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Series .strm files are being synced')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription('Sync series .strm files now? This will generate .strm files for this series at the path set for this series.')
                    ->modalSubmitActionLabel('Yes, sync now')
                    ->disabled(fn($record): bool => ! $record->sync_location),
                Tables\Actions\DeleteAction::make()
                    ->modalIcon('heroicon-o-trash')
                    ->modalDescription('Are you sure you want to delete this series? This will delete all episodes and seasons for this series. This action cannot be undone.')
                    ->modalSubmitActionLabel('Yes, delete series'),
            ])->button()->hiddenLabel()->size('sm'),
        ];
    }

    public static function getTableBulkActions($addToCustom = true): array
    {
        return [
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\BulkAction::make('add')
                    ->label('Add to custom playlist')
                    ->form([
                        Forms\Components\Select::make('playlist')
                            ->required()
                            ->label('Custom Playlist')
                            ->helperText('Select the custom playlist you would like to add the selected series to.')
                            ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->searchable(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $playlist = CustomPlaylist::findOrFail($data['playlist']);
                        $playlist->series()->syncWithoutDetaching($records->pluck('id'));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Series added to custom playlist')
                            ->body('The selected series have been added to the chosen custom playlist.')
                            ->send();
                    })
                    ->hidden(fn() => !$addToCustom)
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-play')
                    ->modalIcon('heroicon-o-play')
                    ->modalDescription('Add the selected series to the chosen custom playlist.')
                    ->modalSubmitActionLabel('Add now'),
                Tables\Actions\BulkAction::make('move')
                    ->label('Move series to category')
                    ->form([
                        Forms\Components\Select::make('category')
                            ->required()
                            ->live()
                            ->label('Category')
                            ->helperText('Select the category you would like to move the series to.')
                            ->options(
                                fn() => Category::query()
                                    ->with(['playlist'])
                                    ->where(['user_id' => auth()->id()])
                                    ->get(['name', 'id', 'playlist_id'])
                                    ->transform(fn($category) => [
                                        'id' => $category->id,
                                        'name' => $category->name . ' (' . $category->playlist->name . ')',
                                    ])->pluck('name', 'id')
                            )->searchable(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $category = Category::findOrFail($data['category']);
                        foreach ($records as $record) {
                            // Update the series to the new category
                            // This will change the category_id for the series in the database
                            // to reflect the new category
                            if ($category->playlist_id !== $record->playlist_id) {
                                Notification::make()
                                    ->warning()
                                    ->title('Warning')
                                    ->body("Cannot move \"{$category->name}\" to \"{$record->name}\" as they belong to different playlists.")
                                    ->persistent()
                                    ->send();
                                continue;
                            }
                            $record->update([
                                'category_id' => $category->id,
                            ]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Series moved to category')
                            ->body('The category series have been moved to the chosen category.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalIcon('heroicon-o-arrows-right-left')
                    ->modalDescription('Move the category series to another category.')
                    ->modalSubmitActionLabel('Move now'),
                Tables\Actions\BulkAction::make('process')
                    ->label('Process Selected Series')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function ($records) {
                        foreach ($records as $record) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\ProcessM3uImportSeriesEpisodes(
                                    playlistSeries: $record,
                                ));
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Series are being processed')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-path')
                    ->modalIcon('heroicon-o-arrow-path')
                    ->modalDescription('Process selected series now? This will fetch all episodes and seasons for this series. This may take a while depending on the number of series selected.')
                    ->modalSubmitActionLabel('Yes, process now'),
                Tables\Actions\BulkAction::make('sync')
                    ->label('Sync Series .strm files')
                    ->action(function ($records) {
                        foreach ($records as $record) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new \App\Jobs\SyncSeriesStrmFiles(
                                    series: $record,
                                ));
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('.strm files are being synced for selected series')
                            ->body('You will be notified once complete.')
                            ->duration(10000)
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-document-arrow-down')
                    ->modalIcon('heroicon-o-document-arrow-down')
                    ->modalDescription('Sync selected series .strm files now? This will generate .strm files for the selected series at the path set for the series.')
                    ->modalSubmitActionLabel('Yes, sync now'),
                Tables\Actions\BulkAction::make('enable')
                    ->label('Enable selected')
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            $record->update([
                                'enabled' => true,
                            ]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Selected series enabled')
                            ->body('The selected series have been enabled.')
                            ->send();
                    })
                    ->color('success')
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-check-circle')
                    ->modalIcon('heroicon-o-check-circle')
                    ->modalDescription('Enable the selected channel(s) now?')
                    ->modalSubmitActionLabel('Yes, enable now'),
                Tables\Actions\BulkAction::make('disable')
                    ->label('Disable selected')
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            $record->update([
                                'enabled' => false,
                            ]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Selected series disabled')
                            ->body('The selected series have been disabled.')
                            ->send();
                    })
                    ->color('warning')
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->modalDescription('Disable the selected channel(s) now?')
                    ->modalSubmitActionLabel('Yes, disable now'),
                Tables\Actions\DeleteBulkAction::make(),
            ]),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\EpisodesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSeries::route('/'),
            'create' => Pages\CreateSeries::route('/create'),
            'edit' => Pages\EditSeries::route('/{record}/edit'),
        ];
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Group Details')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->badge(),
                        Infolists\Components\TextEntry::make('playlist.name')
                            ->label('Playlist')
                            //->badge(),
                            ->url(fn($record) => PlaylistResource::getUrl('edit', ['record' => $record->playlist_id])),
                    ])
            ]);
    }

    public static function getForm($customPlaylist = null): array
    {
        return [
            Forms\Components\Grid::make()
                ->columns(4)
                ->schema([
                    Forms\Components\Section::make('Series Details')
                        ->columnSpan(2)
                        ->icon('heroicon-o-pencil')
                        ->description('Edit or add the series details')
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            Forms\Components\Grid::make(2)
                                ->schema([
                                    Forms\Components\TextInput::make('name')
                                        ->disabled()
                                        ->maxLength(255),
                                    Forms\Components\Toggle::make('enabled')
                                        ->inline(false)
                                        ->required(),
                                    Forms\Components\Select::make('category_id')
                                        ->relationship('category', 'name'),
                                    Forms\Components\TextInput::make('cover')
                                        ->maxLength(255),
                                    Forms\Components\Textarea::make('plot')
                                        ->columnSpanFull(),
                                    Forms\Components\TextInput::make('genre')
                                        ->maxLength(255),
                                    Forms\Components\DatePicker::make('release_date')
                                        ->label('Release Date')
                                        ->dehydrateStateUsing(function ($state) {
                                            // Ensure we store a properly formatted date
                                            if ($state) {
                                                try {
                                                    return \Carbon\Carbon::parse($state)->format('Y-m-d');
                                                } catch (\Exception $e) {
                                                    return null;
                                                }
                                            }
                                            return null;
                                        })
                                        ->formatStateUsing(function ($state) {
                                            // Extract just the date part for display
                                            if ($state) {
                                                try {
                                                    if (preg_match('/(\d{4}-\d{2}-\d{2})/', $state, $matches)) {
                                                        return $matches[1];
                                                    }
                                                    return \Carbon\Carbon::parse($state)->format('Y-m-d');
                                                } catch (\Exception $e) {
                                                    return null;
                                                }
                                            }
                                            return null;
                                        }),
                                    Forms\Components\TextInput::make('rating')
                                        ->maxLength(255),
                                    Forms\Components\TextInput::make('rating_5based')
                                        ->label('Rating (5 based)')
                                        ->numeric(),
                                    Forms\Components\Textarea::make('cast')
                                        ->columnSpanFull(),
                                    Forms\Components\TextInput::make('director')
                                        ->maxLength(255),
                                    Forms\Components\TextInput::make('backdrop_path'),
                                    Forms\Components\TextInput::make('youtube_trailer')
                                        ->label('YouTube Trailer ID')
                                        ->maxLength(255),
                                ]),
                        ]),
                    Forms\Components\Section::make('Stream location file settings')
                        ->columnSpan(2)
                        ->icon('heroicon-o-cog')
                        ->description('Generate .strm files and sync them to a local file path')
                        ->collapsible()
                        ->schema([
                            Forms\Components\Grid::make(1)
                                ->schema([
                                    Forms\Components\Toggle::make('sync_settings.enabled')
                                        ->live()
                                        ->label('Enable .strm file generation'),
                                    Forms\Components\Toggle::make('sync_settings.include_series')
                                        ->label('Create series folder')
                                        ->live()
                                        ->default(true)
                                        ->hidden(fn($get) => !$get('sync_settings.enabled')),
                                    Forms\Components\Toggle::make('sync_settings.include_season')
                                        ->label('Create season folders')
                                        ->live()
                                        ->default(true)
                                        ->hidden(fn($get) => !$get('sync_settings.enabled')),
                                    Forms\Components\TextInput::make('sync_location')
                                        ->label('Series Sync Location')
                                        ->live()
                                        ->rules([new CheckIfUrlOrLocalPath(localOnly: true, isDirectory: true)])
                                        ->helperText(
                                            fn($get) => !$get('sync_settings.include_series')
                                                ? 'File location: ' . $get('sync_location') . ($get('sync_settings.include_season') ?? false ? '/Season 01' : '') . '/S01E01 - Episode Title.strm'
                                                : 'File location: ' . $get('sync_location') . '/Series Name' . ($get('sync_settings.include_season') ?? false ? '/Season 01' : '') . '/S01E01 - Episode Title.strm'
                                        )
                                        ->maxLength(255)
                                        ->required()
                                        ->hidden(fn($get) => !$get('sync_settings.enabled'))
                                        ->placeholder('/usr/local/bin/streamlink'),
                                ]),
                        ]),
                ]),
        ];
    }

    public static function getFormSteps(): array
    {
        return [
            Forms\Components\Wizard\Step::make('Playlist')
                ->schema([
                    Forms\Components\Select::make('playlist')
                        ->required()
                        ->label('Playlist')
                        ->helperText('Select the playlist you would like to import series from.')
                        ->options(Playlist::where([
                            ['user_id', auth()->id()],
                            ['xtream', true]
                        ])->get(['name', 'id'])->pluck('name', 'id'))
                        ->searchable(),
                ]),
            Forms\Components\Wizard\Step::make('Category')
                ->schema([
                    Forms\Components\Select::make('category')
                        ->label('Series Category')
                        ->live()
                        ->required()
                        ->searchable()
                        ->columnSpanFull()
                        ->options(function ($get) {
                            $playlist = $get('playlist');
                            if (!$playlist) {
                                return [];
                            }
                            $xtreamConfig = Playlist::find($playlist)->xtream_config ?? [];
                            $xtremeUrl = $xtreamConfig['url'] ?? '';
                            $xtreamUser = $xtreamConfig['username'] ?? '';
                            $xtreamPass = $xtreamConfig['password'] ?? '';
                            $cacheKey = 'xtream_series_categories_' . md5($xtremeUrl . $xtreamUser . $xtreamPass);
                            $cachedCategories = Cache::remember($cacheKey, 60 * 1, function () use ($xtremeUrl, $xtreamUser, $xtreamPass) {
                                $service = new XtreamService();
                                $xtream = $service->init(xtream_config: [
                                    'url' => $xtremeUrl,
                                    'username' => $xtreamUser,
                                    'password' => $xtreamPass,
                                ]);
                                $userInfo = $xtream->authenticate();
                                if (! ($userInfo['auth'] ?? false)) {
                                    return [];
                                }
                                $seriesCategories = $xtream->getSeriesCategories();
                                return collect($seriesCategories)
                                    ->map(function ($category) {
                                        return [
                                            'label' => $category['category_name'],
                                            'value' => $category['category_id'],
                                        ];
                                    })->pluck('label', 'value')->toArray();
                            });
                            return $cachedCategories;
                        })
                        ->helperText(
                            fn(Get $get): string => $get('playlist')
                                ? 'Which category would you like to add a series from.'
                                : 'You must select a playlist first.'
                        )
                        ->disabled(fn(Get $get): bool => ! $get('playlist'))
                        ->hidden(fn(Get $get): bool => ! $get('playlist'))
                        ->afterStateUpdated(function ($get, $set, $state) {
                            if ($state) {
                                $playlist = $get('playlist');
                                if (!$playlist) {
                                    return;
                                }
                                $xtreamConfig = Playlist::find($playlist)->xtream_config ?? [];
                                $xtremeUrl = $xtreamConfig['url'] ?? '';
                                $xtreamUser = $xtreamConfig['username'] ?? '';
                                $xtreamPass = $xtreamConfig['password'] ?? '';
                                $cacheKey = 'xtream_series_categories_' . md5($xtremeUrl . $xtreamUser . $xtreamPass);
                                $cachedCategories = Cache::get($cacheKey);

                                if ($cachedCategories) {
                                    $category = $cachedCategories[$state] ?? null;
                                    if ($category) {
                                        $set('category_name', $category);
                                    }
                                }
                            }
                        }),
                    Forms\Components\TextInput::make('category_name')
                        ->label('Category Name')
                        ->helperText('Automatically set when selecting a category.')
                        ->required()
                        ->disabled()
                        ->dehydrated(fn(): bool => true),
                ]),
            Forms\Components\Wizard\Step::make('Series to Import')
                ->schema([
                    Forms\Components\Toggle::make('import_all')
                        ->label('Import All Series')
                        ->onColor('warning')
                        ->hint('Use with caution')
                        ->live()
                        ->helperText('If enabled, all series in the selected category will be imported. Use with caution as this will make a lot of requests to your provider to fetch metadata and episodes. It is recomended to import only the series you want to watch. You can also enable the series option on your playlist under the "Groups and Streams to Import" to import all the base data for all available series.')
                        ->default(false)
                        ->columnSpanFull()
                        ->afterStateUpdated(function (Get $get, $set) {
                            if ($get('import_all')) {
                                $set('series', []);
                            }
                        }),
                    Forms\Components\CheckboxList::make('series')
                        ->label('Series to Import')
                        ->required()
                        ->searchable()
                        ->columnSpanFull()
                        ->columns(4)
                        ->options(function ($get) {
                            $playlist = $get('playlist');
                            $category = $get('category');
                            if (!$playlist) {
                                return [];
                            }
                            $xtreamConfig = Playlist::find($playlist)->xtream_config ?? [];
                            $xtremeUrl = $xtreamConfig['url'] ?? '';
                            $xtreamUser = $xtreamConfig['username'] ?? '';
                            $xtreamPass = $xtreamConfig['password'] ?? '';
                            $cacheKey = 'xtream_category_series' . md5($xtremeUrl . $xtreamUser . $xtreamPass . $category);
                            $cachedCategories = Cache::remember($cacheKey, 60 * 1, function () use ($xtremeUrl, $xtreamUser, $xtreamPass, $category) {
                                $xtream = XtreamService::make(xtream_config: [
                                    'url' => $xtremeUrl,
                                    'username' => $xtreamUser,
                                    'password' => $xtreamPass,
                                ]);
                                $userInfo = $xtream->authenticate();
                                if (! ($userInfo['auth'] ?? false)) {
                                    return [];
                                }
                                $series = $xtream->getSeries($category);
                                return collect($series)
                                    ->map(function ($s) {
                                        return [
                                            'label' => $s['name'],
                                            'value' => $s['series_id'],
                                        ];
                                    })->pluck('label', 'value')->toArray();
                            });
                            return $cachedCategories;
                        })
                        ->helperText(
                            fn(Get $get): string => $get('playlist') && $get('category')
                                ? 'Which series would you like to import.'
                                : 'You must select a playlist and category first.'
                        )
                        ->disabled(fn(Get $get): bool => ! $get('playlist') || ! $get('category') || $get('import_all'))
                        ->hidden(fn(Get $get): bool => ! $get('playlist') || ! $get('category') || $get('import_all')),
                ])
        ];
    }
}
