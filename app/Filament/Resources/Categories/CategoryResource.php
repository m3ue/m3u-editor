<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\RelationManagers\SeriesRelationManager;
use App\Filament\Resources\CategoryResource\Pages;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Jobs\SyncSeriesStrmFiles;
use App\Models\Category;
use App\Models\CustomPlaylist;
use App\Traits\HasUserFiltering;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group as ComponentsGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class CategoryResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = Category::class;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'name_internal'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->where('user_id', auth()->id());
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Series';

    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    public static function form(Schema $schema): Schema
    {
        $fields = [
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            Toggle::make('enabled')
                ->inline(false)
                ->label('Auto Enable New Channels')
                ->helperText('Automatically enable newly added channels to this group.')
                ->default(true),
            Select::make('stream_file_setting_id')
                ->label('Stream File Setting Profile')
                ->searchable()
                ->relationship('streamFileSetting', 'name', fn ($query) => $query->forSeries()->where('user_id', auth()->id()))
                ->nullable()
                ->helperText('Select a Stream File Setting profile for all series in this category. Series-level settings take priority. Leave empty to use global settings.'),
        ];

        return $schema
            ->components([
                Section::make('Category Settings')
                    ->compact()
                    ->columns(2)
                    ->icon('heroicon-s-cog')
                    ->collapsed(true)
                    ->schema($fields)
                    ->hiddenOn(['create']),
                ComponentsGroup::make($fields)
                    ->columnSpanFull()
                    ->columns(2)
                    ->hiddenOn(['edit']),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount('series')
                    ->withCount('enabled_series');
            })
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                TextInputColumn::make('name')
                    ->label('Name')
                    ->rules(['min:0', 'max:255'])
                    ->placeholder(fn ($record) => $record->name_internal)
                    ->searchable()
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->orderBy('name_internal', $direction)
                            ->orderBy('name', $direction);
                    })
                    ->toggleable(),
                ToggleColumn::make('enabled')
                    ->label('Auto Enable')
                    ->toggleable()
                    ->tooltip('Auto enable newly added category series')
                    ->sortable(),
                TextColumn::make('name_internal')
                    ->label('Default name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('series_count')
                    ->label('Series')
                    ->description(fn (Category $record): string => "Enabled: {$record->enabled_series_count}")
                    ->toggleable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // SelectFilter::make('playlist')
                //     ->relationship('playlist', 'name')
                //     ->multiple()
                //     ->preload()
                //     ->searchable(),
            ])
            ->recordActions([
                BulkActionGroup::make([
                    Action::make('add')
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
                                ->disabled(fn (Get $get) => ! $get('playlist'))
                                ->helperText(fn (Get $get) => ! $get('playlist') ? 'Select a custom playlist first.' : 'Select the category you would like to assign to the selected series to.')
                                ->options(function ($get) {
                                    $customList = CustomPlaylist::find($get('playlist'));

                                    return $customList ? $customList->categoryTags()->get()
                                        ->mapWithKeys(fn ($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                                        ->toArray() : [];
                                })
                                ->searchable(),
                        ])
                        ->action(function ($record, array $data): void {
                            $playlist = CustomPlaylist::findOrFail($data['playlist']);
                            $playlist->series()->syncWithoutDetaching($record->series()->pluck('id'));
                            $tags = $playlist->categoryTags()->get();
                            $tag = $playlist->categoryTags()->where('name->en', $data['category'])->first();
                            foreach ($record->series()->cursor() as $series) {
                                // Need to detach any existing tags from this playlist first
                                $series->detachTags($tags);
                                $series->attachTag($tag);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Series added to custom playlist')
                                ->body('The selected series have been added to the chosen custom playlist.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-play')
                        ->modalIcon('heroicon-o-play')
                        ->modalDescription('Add the selected series to the chosen custom playlist.')
                        ->modalSubmitActionLabel('Add now'),
                    Action::make('move')
                        ->label('Move Series to Category')
                        ->schema([
                            Select::make('category')
                                ->required()
                                ->live()
                                ->label('Category')
                                ->helperText('Select the category you would like to move the series to.')
                                ->options(fn (Get $get, $record) => Category::where(['user_id' => auth()->id(), 'playlist_id' => $record->playlist_id])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                        ])
                        ->action(function ($record, array $data): void {
                            $category = Category::findOrFail($data['category']);
                            $record->series()->update([
                                'category_id' => $category->id,
                            ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Series moved to category')
                                ->body('The series have been moved to the chosen category.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription('Move the series to another category.')
                        ->modalSubmitActionLabel('Move now'),
                    Action::make('process')
                        ->label('Fetch Series Metadata')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->action(function ($record) {
                            foreach ($record->enabled_series as $series) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new ProcessM3uImportSeriesEpisodes(
                                        playlistSeries: $series,
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
                        ->modalDescription('Process series for selected category now? Only enabled series will be processed. This will fetch all episodes and seasons for the category series. This may take a while depending on the number of series in the category.')
                        ->modalSubmitActionLabel('Yes, process now'),
                    Action::make('sync')
                        ->label('Sync Series .strm files')
                        ->action(function ($record) {
                            foreach ($record->enabled_series as $series) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new SyncSeriesStrmFiles(
                                        series: $series,
                                    ));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('.strm files are being synced for selected category series. Only enabled series will be synced.')
                                ->body('You will be notified once complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-document-arrow-down')
                        ->modalIcon('heroicon-o-document-arrow-down')
                        ->modalDescription('Sync selected category series .strm files now? This will generate .strm files for the enabled series at the path set for the series.')
                        ->modalSubmitActionLabel('Yes, sync now'),
                    Action::make('enable')
                        ->label('Enable Category Series')
                        ->action(function ($record): void {
                            $record->series()->update(['enabled' => true]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected category series enabled')
                                ->body('The selected category series have been enabled.')
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable the selected category series now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    Action::make('disable')
                        ->label('Disable Category Series')
                        ->action(function ($record): void {
                            $record->series()->update(['enabled' => false]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected category series disabled')
                                ->body('The selected category series have been disabled.')
                                ->send();
                        })
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected category series now?')
                        ->modalSubmitActionLabel('Yes, disable now'),
                ])->color('primary')->button()->hiddenLabel()->size('sm'),
                EditAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('add')
                        ->label('Add to Custom Playlist')
                        ->schema([
                            Select::make('playlist')
                                ->required()
                                ->live()
                                ->label('Custom Playlist')
                                ->helperText('Select the custom playlist you would like to add the selected category series to.')
                                ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->afterStateUpdated(function (Set $set, $state) {
                                    if ($state) {
                                        $set('category', null);
                                    }
                                })
                                ->searchable(),
                            Select::make('category')
                                ->label('Custom Category')
                                ->disabled(fn (Get $get) => ! $get('playlist'))
                                ->helperText(fn (Get $get) => ! $get('playlist') ? 'Select a custom playlist first.' : 'Select the category you would like to assign to the selected series to.')
                                ->options(function ($get) {
                                    $customList = CustomPlaylist::find($get('playlist'));

                                    return $customList ? $customList->categoryTags()->get()
                                        ->mapWithKeys(fn ($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                                        ->toArray() : [];
                                })
                                ->searchable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $playlist = CustomPlaylist::findOrFail($data['playlist']);
                            $tags = $playlist->categoryTags()->get();
                            $tag = $data['category'] ? $playlist->categoryTags()->where('name->en', $data['category'])->first() : null;
                            foreach ($records as $record) {
                                // Sync the series to the custom playlist
                                // This will add the series to the playlist without detaching existing ones
                                // Prevents duplicates in the playlist
                                $playlist->series()->syncWithoutDetaching($record->series()->pluck('id'));
                                if ($data['category']) {
                                    foreach ($record->series()->cursor() as $series) {
                                        // Need to detach any existing tags from this playlist first
                                        $series->detachTags($tags);
                                        $series->attachTag($tag);
                                    }
                                }
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Category series added to custom playlist')
                                ->body('The selected category series have been added to the chosen custom playlist.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-play')
                        ->modalIcon('heroicon-o-play')
                        ->modalDescription('Add the selected category series to the chosen custom playlist.')
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
                                    fn () => Category::query()
                                        ->with(['playlist'])
                                        ->where(['user_id' => auth()->id()])
                                        ->get(['name', 'id', 'playlist_id'])
                                        ->transform(fn ($category) => [
                                            'id' => $category->id,
                                            'name' => $category->name.' ('.$category->playlist->name.')',
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
                                $record->series()->update([
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
                        ->action(function (Collection $records) {
                            foreach ($records as $record) {
                                foreach ($record->enabled_series as $series) {
                                    app('Illuminate\Contracts\Bus\Dispatcher')
                                        ->dispatch(new ProcessM3uImportSeriesEpisodes(
                                            playlistSeries: $series,
                                        ));
                                }
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
                        ->modalDescription('Process series for selected category now? Only enabled series will be processed. This will fetch all episodes and seasons for the category series. This may take a while depending on the number of series in the category.')
                        ->modalSubmitActionLabel('Yes, process now'),
                    BulkAction::make('sync')
                        ->label('Sync Series .strm files')
                        ->action(function (Collection $records) {
                            foreach ($records as $record) {
                                foreach ($record->enabled_series as $series) {
                                    app('Illuminate\Contracts\Bus\Dispatcher')
                                        ->dispatch(new SyncSeriesStrmFiles(
                                            series: $series,
                                        ));
                                }
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('.strm files are being synced for selected category series. Only enabled series will be synced.')
                                ->body('You will be notified once complete.')
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-document-arrow-down')
                        ->modalIcon('heroicon-o-document-arrow-down')
                        ->modalDescription('Sync selected category series .strm files now? This will generate .strm files for the selected series at the path set for the series.')
                        ->modalSubmitActionLabel('Yes, sync now'),
                    BulkAction::make('enable')
                        ->label('Enable Category Series')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->series()->update(['enabled' => true]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected category series enabled')
                                ->body('The selected category series have been enabled.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable the selected category series now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    BulkAction::make('disable')
                        ->label('Disable Category Series')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->series()->update(['enabled' => false]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected category series disabled')
                                ->body('The selected category series have been disabled.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected category series now?')
                        ->modalSubmitActionLabel('Yes, disable now'),
                    BulkAction::make('enable_categories')
                        ->label('Enable Categories')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update(['enabled' => true]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected categories enabled')
                                ->body('The selected categories have been enabled.')
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable the selected categories now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    BulkAction::make('disable_categories')
                        ->label('Disable Categories')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update(['enabled' => false]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected categories disabled')
                                ->body('The selected categories have been disabled.')
                                ->send();
                        })
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected categories now?')
                        ->modalSubmitActionLabel('Yes, disable now'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            SeriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            // 'create' => Pages\CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
