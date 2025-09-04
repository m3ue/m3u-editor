<?php

namespace App\Filament\Resources\Series;

use App\Facades\LogoFacade;
use App\Filament\Resources\Playlists\PlaylistResource;
use Filament\Schemas\Schema;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\Filter;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Jobs\SyncSeriesStrmFiles;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\BulkAction;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\Series\RelationManagers\EpisodesRelationManager;
use App\Filament\Resources\Series\Pages\ListSeries;
use App\Filament\Resources\Series\Pages\CreateSeries;
use App\Filament\Resources\Series\Pages\EditSeries;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DatePicker;
use Carbon\Carbon;
use Exception;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Forms\Components\CheckboxList;
use App\Filament\Resources\SeriesResource\Pages;
use App\Filament\Resources\SeriesResource\RelationManagers;
use App\Models\Category;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\Series;
use App\Rules\CheckIfUrlOrLocalPath;
use App\Services\XtreamService;
use Filament\Forms;
use Filament\Infolists;
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

    protected static string | \UnitEnum | null $navigationGroup = 'Series';

    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm());
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
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['playlist']);
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns(self::getTableColumns(showCategory: !$relationId, showPlaylist: !$relationId))
            ->filters(self::getTableFilters(showPlaylist: !$relationId))
            ->recordActions(self::getTableActions(), position: RecordActionsPosition::BeforeCells)
            ->toolbarActions(self::getTableBulkActions());
    }

    public static function getTableColumns($showCategory = true, $showPlaylist = true): array
    {
        return [
            ImageColumn::make('cover')
                ->width(80)
                ->height(120)
                ->checkFileExistence(false)
                ->getStateUsing(fn($record) => LogoFacade::getSeriesLogoUrl($record))
                ->searchable(),
            TextColumn::make('name')
                ->description((fn($record) => Str::limit($record->plot, 200)))
                ->wrap()
                ->extraAttributes(['style' => 'min-width: 350px;'])
                ->searchable()
                ->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->orWhereRaw('LOWER(series.name) LIKE ?', ['%' . strtolower($search) . '%']);
                }),
            ToggleColumn::make('enabled')
                ->toggleable()
                ->tooltip('Toggle series status')
                ->sortable(),
            TextColumn::make('seasons_count')
                ->label('Seasons')
                ->counts('seasons')
                ->badge()
                ->toggleable()
                ->sortable(),
            TextColumn::make('episodes_count')
                ->label('Episodes')
                ->counts('episodes')
                ->badge()
                ->toggleable()
                ->sortable(),
            TextColumn::make('category.name')
                ->hidden(fn() => !$showCategory)
                ->badge()
                ->numeric()
                ->sortable(),
            TextColumn::make('genre')
                ->searchable(),
            TextColumn::make('youtube_trailer')
                ->label('YouTube Trailer')
                ->placeholder('No trailer ID set.')
                ->url(fn($record): string => 'https://www.youtube.com/watch?v=' . $record->youtube_trailer)
                ->openUrlInNewTab()
                ->icon('heroicon-s-play'),
            TextColumn::make('release_date')
                ->searchable(),
            TextColumn::make('rating')
                ->badge()
                ->color('success')
                ->icon('heroicon-m-star')
                ->searchable(),
            TextColumn::make('rating_5based')
                ->badge()
                ->color('success')
                ->icon('heroicon-m-star')
                ->sortable(),
            TextColumn::make('playlist.name')
                ->numeric()
                ->sortable()
                ->hidden(fn() => !$showPlaylist),
            TextColumn::make('created_at')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('updated_at')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function getTableFilters($showPlaylist = true): array
    {
        return [
            SelectFilter::make('playlist')
                ->relationship('playlist', 'name')
                ->multiple()
                ->preload()
                ->searchable()
                ->hidden(fn() => !$showPlaylist),
            Filter::make('enabled')
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
            ActionGroup::make([
                EditAction::make()
                    ->slideOver(),
                Action::make('move')
                    ->label('Move Series to Category')
                    ->schema([
                        Select::make('category')
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
                Action::make('process')
                    ->label('Fetch Series Metadata')
                    ->action(function ($record) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ProcessM3uImportSeriesEpisodes(
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
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription('Process series now? This will fetch all episodes and seasons for this series.')
                    ->modalSubmitActionLabel('Yes, process now'),
                Action::make('sync')
                    ->label('Sync Series .strm files')
                    ->action(function ($record) {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new SyncSeriesStrmFiles(
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
                    ->modalSubmitActionLabel('Yes, sync now'),
                DeleteAction::make()
                    ->modalIcon('heroicon-o-trash')
                    ->modalDescription('Are you sure you want to delete this series? This will delete all episodes and seasons for this series. This action cannot be undone.')
                    ->modalSubmitActionLabel('Yes, delete series'),
            ])->button()->hiddenLabel()->size('sm'),
        ];
    }

    public static function getTableBulkActions($addToCustom = true): array
    {
        return [
            BulkActionGroup::make([
                BulkAction::make('add')
                    ->label('Add to Custom Playlist')
                    ->schema([
                        Select::make('playlist')
                            ->required()
                            ->live()
                            ->label('Custom Playlist')
                            ->helperText('Select the custom playlist you would like to add the selected series to.')
                            ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->afterStateUpdated(function (Set $set, $state) {
                                if ($state) {
                                    $set('category', null);
                                }
                            })
                            ->searchable(),
                        Select::make('category')
                            ->label('Custom Category')
                            ->disabled(fn(Get $get) => !$get('playlist'))
                            ->helperText(fn(Get $get) => !$get('playlist') ? 'Select a custom playlist first.' : 'Select the category you would like to assign to the selected series to.')
                            ->options(function ($get) {
                                $customList = CustomPlaylist::find($get('playlist'));
                                return $customList ? $customList->tags()
                                    ->where('type', $customList->uuid . '-category')
                                    ->get()
                                    ->mapWithKeys(fn($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                                    ->toArray() : [];
                            })
                            ->searchable(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $playlist = CustomPlaylist::findOrFail($data['playlist']);
                        $playlist->series()->syncWithoutDetaching($records->pluck('id'));
                        if ($data['category']) {
                            $playlist->syncTagsWithType([$data['category']], $playlist->uuid);
                        }
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
                BulkAction::make('move')
                    ->label('Move Series to Category')
                    ->schema([
                        Select::make('category')
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
                BulkAction::make('process')
                    ->label('Fetch Series Metadata')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function ($records) {
                        foreach ($records as $record) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new ProcessM3uImportSeriesEpisodes(
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
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalIcon('heroicon-o-arrow-down-tray')
                    ->modalDescription('Process selected series now? This will fetch all episodes and seasons for this series. This may take a while depending on the number of series selected.')
                    ->modalSubmitActionLabel('Yes, process now'),
                BulkAction::make('sync')
                    ->label('Sync Series .strm files')
                    ->action(function ($records) {
                        foreach ($records as $record) {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new SyncSeriesStrmFiles(
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
                BulkAction::make('enable')
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
                BulkAction::make('disable')
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
                DeleteBulkAction::make(),
            ]),
        ];
    }

    public static function getRelations(): array
    {
        return [
            EpisodesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSeries::route('/'),
            'create' => CreateSeries::route('/create'),
            'edit' => EditSeries::route('/{record}/edit'),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Group Details')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name')
                            ->badge(),
                        TextEntry::make('playlist.name')
                            ->label('Playlist')
                            //->badge(),
                            ->url(fn($record) => PlaylistResource::getUrl('edit', ['record' => $record->playlist_id])),
                    ])
            ]);
    }

    public static function getForm($customPlaylist = null): array
    {
        return [
            Grid::make()
                ->columns(4)
                ->schema([
                    Section::make('Series Details')
                        ->columnSpan(2)
                        ->icon('heroicon-o-pencil')
                        ->description('Edit or add the series details')
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    TextInput::make('name')
                                        ->maxLength(255),
                                    Toggle::make('enabled')
                                        ->inline(false)
                                        ->required(),
                                    Select::make('category_id')
                                        ->relationship('category', 'name'),
                                    TextInput::make('cover')
                                        ->maxLength(255),
                                    Textarea::make('plot')
                                        ->columnSpanFull(),
                                    TextInput::make('genre')
                                        ->maxLength(255),
                                    DatePicker::make('release_date')
                                        ->label('Release Date')
                                        ->dehydrateStateUsing(function ($state) {
                                            // Ensure we store a properly formatted date
                                            if ($state) {
                                                try {
                                                    return Carbon::parse($state)->format('Y-m-d');
                                                } catch (Exception $e) {
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
                                                    return Carbon::parse($state)->format('Y-m-d');
                                                } catch (Exception $e) {
                                                    return null;
                                                }
                                            }
                                            return null;
                                        }),
                                    TextInput::make('rating')
                                        ->maxLength(255),
                                    TextInput::make('rating_5based')
                                        ->label('Rating (5 based)')
                                        ->numeric(),
                                    Textarea::make('cast')
                                        ->columnSpanFull(),
                                    TextInput::make('director')
                                        ->maxLength(255),
                                    TextInput::make('backdrop_path'),
                                    TextInput::make('youtube_trailer')
                                        ->label('YouTube Trailer ID')
                                        ->maxLength(255),
                                ]),
                        ]),
                    Section::make('Stream location file settings')
                        ->columnSpan(2)
                        ->icon('heroicon-o-cog')
                        ->description('Generate .strm files and sync them to a local file path')
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            Grid::make(1)
                                ->schema([
                                    Toggle::make('sync_settings.enabled')
                                        ->live()
                                        ->label('Enable .strm file generation'),
                                    Toggle::make('sync_settings.include_series')
                                        ->label('Create series folder')
                                        ->live()
                                        ->default(true)
                                        ->hidden(fn($get) => !$get('sync_settings.enabled')),
                                    Toggle::make('sync_settings.include_season')
                                        ->label('Create season folders')
                                        ->live()
                                        ->default(true)
                                        ->hidden(fn($get) => !$get('sync_settings.enabled')),
                                    TextInput::make('sync_location')
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
            Step::make('Playlist')
                ->schema([
                    Select::make('playlist')
                        ->required()
                        ->label('Playlist')
                        ->helperText('Select the playlist you would like to import series from.')
                        ->options(Playlist::where([
                            ['user_id', auth()->id()],
                            ['xtream', true]
                        ])->get(['name', 'id'])->pluck('name', 'id'))
                        ->searchable(),
                ]),
            Step::make('Category')
                ->schema([
                    Select::make('category')
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
                    TextInput::make('category_name')
                        ->label('Category Name')
                        ->helperText('Automatically set when selecting a category.')
                        ->required()
                        ->disabled()
                        ->dehydrated(fn(): bool => true),
                ]),
            Step::make('Series to Import')
                ->schema([
                    Toggle::make('import_all')
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
                    CheckboxList::make('series')
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
