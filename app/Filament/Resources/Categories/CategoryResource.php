<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Categories\RelationManagers\SeriesRelationManager;
use App\Filament\Resources\CategoryResource\Pages;
use App\Jobs\CategoryFindAndReplace;
use App\Jobs\CategoryFindAndReplaceReset;
use App\Jobs\ProcessM3uImportSeriesEpisodes;
use App\Jobs\SyncSeriesStrmFiles;
use App\Models\Category;
use App\Models\Playlist;
use App\Services\DateFormatService;
use App\Services\FindReplaceService;
use App\Services\PlaylistService;
use App\Traits\HasUserFiltering;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class CategoryResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
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

    public static function getNavigationGroup(): ?string
    {
        return __('Series');
    }

    public static function getModelLabel(): string
    {
        return __('Category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Categories');
    }

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
                ->label(__('Auto Enable New Channels'))
                ->helperText(__('Automatically enable newly added channels to this group.'))
                ->default(true),
            TextInput::make('sort_order')
                ->label(__('Sort Order'))
                ->numeric()
                ->default(9999)
                ->helperText(__('Enter a number to define the sort order (e.g., 1, 2, 3). Lower numbers appear first.'))
                ->rules(['integer', 'min:0']),
            Select::make('stream_file_setting_id')
                ->label(__('Stream File Setting Profile'))
                ->searchable()
                ->relationship('streamFileSetting', 'name', fn ($query) => $query->forSeries()->where('user_id', auth()->id()))
                ->nullable()
                ->helperText(__('Select a Stream File Setting profile for all series in this category. Series-level settings take priority. Leave empty to use global settings.')),
        ];

        return $schema
            ->components([
                Section::make(__('Category Settings'))
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
                return $action->button()->label(__('Filters'));
            })
            ->reorderRecordsTriggerAction(function ($action) {
                return $action->button()->label(__('Sort'));
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('sort_order', 'asc')
            ->reorderable('sort_order')
            ->columns([
                TextInputColumn::make('name')
                    ->label(__('Name'))
                    ->rules(['min:0', 'max:255'])
                    ->placeholder(fn ($record) => $record->name_internal)
                    ->searchable()
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->orderBy('name_internal', $direction)
                            ->orderBy('name', $direction);
                    })
                    ->toggleable(),
                TextInputColumn::make('sort_order')
                    ->label(__('Sort Order'))
                    ->rules(['min:0'])
                    ->type('number')
                    ->placeholder(__('Sort Order'))
                    ->sortable()
                    ->tooltip(__('Category sort order'))
                    ->toggleable(),
                ToggleColumn::make('enabled')
                    ->label(__('Auto Enable'))
                    ->toggleable()
                    ->tooltip(__('Auto enable newly added category series'))
                    ->sortable(),
                TextColumn::make('name_internal')
                    ->label(__('Default name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('series_count')
                    ->label(__('Series'))
                    ->description(fn (Category $record): string => "Enabled: {$record->enabled_series_count}")
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
                // SelectFilter::make('playlist')
                //     ->relationship('playlist', 'name')
                //     ->multiple()
                //     ->preload()
                //     ->searchable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    PlaylistService::getAddGroupsToPlaylistAction('add', 'series'),
                    Action::make('move')
                        ->label(__('Move Series to Category'))
                        ->schema([
                            Select::make('category')
                                ->required()
                                ->live()
                                ->label(__('Category'))
                                ->helperText(__('Select the category you would like to move the series to.'))
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
                                ->title(__('Series moved to category'))
                                ->body(__('The series have been moved to the chosen category.'))
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription(__('Move the series to another category.'))
                        ->modalSubmitActionLabel(__('Move now')),
                    Action::make('process')
                        ->label(__('Fetch Series Metadata'))
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
                                ->title(__('Series are being processed'))
                                ->body(__('You will be notified once complete.'))
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalIcon('heroicon-o-arrow-down-tray')
                        ->modalDescription(__('Process series for selected category now? Only enabled series will be processed. This will fetch all episodes and seasons for the category series. This may take a while depending on the number of series in the category.'))
                        ->modalSubmitActionLabel(__('Yes, process now')),
                    Action::make('sync')
                        ->label(__('Sync Series .strm files'))
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
                                ->title(__('.strm files are being synced for selected category series. Only enabled series will be synced.'))
                                ->body(__('You will be notified once complete.'))
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-document-arrow-down')
                        ->modalIcon('heroicon-o-document-arrow-down')
                        ->modalDescription(__('Sync selected category series .strm files now? This will generate .strm files for the enabled series at the path set for the series.'))
                        ->modalSubmitActionLabel(__('Yes, sync now')),
                    Action::make('enable')
                        ->label(__('Enable Category Series'))
                        ->action(function ($record): void {
                            $record->series()->update(['enabled' => true]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Selected category series enabled'))
                                ->body(__('The selected category series have been enabled.'))
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription(__('Enable the selected category series now?'))
                        ->modalSubmitActionLabel(__('Yes, enable now')),
                    Action::make('disable')
                        ->label(__('Disable Category Series'))
                        ->action(function ($record): void {
                            $record->series()->update(['enabled' => false]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Selected category series disabled'))
                                ->body(__('The selected category series have been disabled.'))
                                ->send();
                        })
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription(__('Disable the selected category series now?'))
                        ->modalSubmitActionLabel(__('Yes, disable now')),
                ])->color('primary')->button()->hiddenLabel()->size('sm'),
                EditAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    PlaylistService::getAddGroupsToPlaylistBulkAction('add', 'series'),
                    BulkAction::make('move')
                        ->label(__('Move Series to Category'))
                        ->schema([
                            Select::make('category')
                                ->required()
                                ->live()
                                ->label(__('Category'))
                                ->helperText(__('Select the category you would like to move the series to.'))
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
                                        ->title(__('Warning'))
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
                                ->title(__('Series moved to category'))
                                ->body(__('The category series have been moved to the chosen category.'))
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription(__('Move the category series to another category.'))
                        ->modalSubmitActionLabel(__('Move now')),
                    BulkAction::make('process')
                        ->label(__('Fetch Series Metadata'))
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
                                ->title(__('Series are being processed'))
                                ->body(__('You will be notified once complete.'))
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalIcon('heroicon-o-arrow-down-tray')
                        ->modalDescription(__('Process series for selected category now? Only enabled series will be processed. This will fetch all episodes and seasons for the category series. This may take a while depending on the number of series in the category.'))
                        ->modalSubmitActionLabel(__('Yes, process now')),
                    BulkAction::make('sync')
                        ->label(__('Sync Series .strm files'))
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
                                ->title(__('.strm files are being synced for selected category series. Only enabled series will be synced.'))
                                ->body(__('You will be notified once complete.'))
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-document-arrow-down')
                        ->modalIcon('heroicon-o-document-arrow-down')
                        ->modalDescription(__('Sync selected category series .strm files now? This will generate .strm files for the selected series at the path set for the series.'))
                        ->modalSubmitActionLabel(__('Yes, sync now')),
                    BulkAction::make('enable')
                        ->label(__('Enable Category Series'))
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->series()->update(['enabled' => true]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Selected category series enabled'))
                                ->body(__('The selected category series have been enabled.'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription(__('Enable the selected category series now?'))
                        ->modalSubmitActionLabel(__('Yes, enable now')),
                    BulkAction::make('disable')
                        ->label(__('Disable Category Series'))
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->series()->update(['enabled' => false]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Selected category series disabled'))
                                ->body(__('The selected category series have been disabled.'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription(__('Disable the selected category series now?'))
                        ->modalSubmitActionLabel(__('Yes, disable now')),
                    BulkAction::make('enable_categories')
                        ->label(__('Enable Categories'))
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update(['enabled' => true]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Selected categories enabled'))
                                ->body(__('The selected categories have been enabled.'))
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription(__('Enable the selected categories now?'))
                        ->modalSubmitActionLabel(__('Yes, enable now')),
                    BulkAction::make('disable_categories')
                        ->label(__('Disable Categories'))
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update(['enabled' => false]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Selected categories disabled'))
                                ->body(__('The selected categories have been disabled.'))
                                ->send();
                        })
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription(__('Disable the selected categories now?'))
                        ->modalSubmitActionLabel(__('Yes, disable now')),
                    BulkAction::make('find-replace')
                        ->label(__('Find & Replace'))
                        ->schema(fn () => FindReplaceService::getBulkActionSchema('categories'))
                        ->action(function (Collection $records, array $data): void {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new CategoryFindAndReplace(
                                    user_id: auth()->id(),
                                    use_regex: $data['use_regex'] ?? true,
                                    find_replace: $data['find_replace'] ?? '',
                                    replace_with: $data['replace_with'] ?? '',
                                    categories: $records,
                                ));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Find & Replace started'))
                                ->body(__('Find & Replace working in the background. You will be notified once the process is complete.'))
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-magnifying-glass')
                        ->color('gray')
                        ->modalIcon('heroicon-o-magnifying-glass')
                        ->modalDescription(__('Select what you would like to find and replace in the selected category names.'))
                        ->modalSubmitActionLabel(__('Replace now')),
                    BulkAction::make('find-replace-reset')
                        ->label(__('Undo Find & Replace'))
                        ->action(function (Collection $records): void {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new CategoryFindAndReplaceReset(
                                    user_id: auth()->id(),
                                    categories: $records,
                                ));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Find & Replace reset started'))
                                ->body(__('Find & Replace reset working in the background. You will be notified once the process is complete.'))
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('warning')
                        ->modalIcon('heroicon-o-arrow-uturn-left')
                        ->modalDescription(__('Reset category names back to their original imported values? This will undo any find & replace changes.'))
                        ->modalSubmitActionLabel(__('Reset now')),
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
