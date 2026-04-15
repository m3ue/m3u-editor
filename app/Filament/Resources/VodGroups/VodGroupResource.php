<?php

namespace App\Filament\Resources\VodGroups;

use App\Facades\SortFacade;
use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\VodGroups\Pages\EditVodGroup;
use App\Filament\Resources\VodGroups\Pages\ListVodGroups;
use App\Filament\Resources\VodGroups\RelationManagers\VodRelationManager;
use App\Jobs\GroupFindAndReplace;
use App\Jobs\GroupFindAndReplaceReset;
use App\Jobs\ProcessVodChannels;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Group;
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
use Filament\Actions\DeleteAction;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class VodGroupResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
    use HasUserFiltering;

    protected static ?string $model = Group::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $label = 'VOD Group';

    protected static ?string $pluralLabel = 'Groups';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'name_internal'];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('VOD Channels');
    }

    public static function getModelLabel(): string
    {
        return __('VOD Group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Groups');
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->modifyQueryUsing(function (Builder $query) {
                $query->withCount('vod_channels')
                    ->withCount('enabled_vod_channels')
                    ->where('type', 'vod');
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
                    ->tooltip(fn ($record) => $record->playlist->auto_sort ? 'Playlist auto-sort enabled; any changes will be overwritten on next sync' : 'Group sort order')
                    ->toggleable(),
                ToggleColumn::make('enabled')
                    ->label(__('Auto Enable'))
                    ->toggleable()
                    ->tooltip(__('Auto enable newly added group channels'))
                    ->tooltip(fn ($record) => $record->playlist?->enable_channels ? 'Playlist auto-enable new channels is enabled, all group channels will automatically be enabled on next sync.' : 'Auto enable newly added group channels')
                    ->disabled(fn ($record) => $record->playlist?->enable_channels)
                    ->getStateUsing(fn ($record) => $record->playlist?->enable_channels ? true : $record->enabled)
                    ->sortable(),
                TextColumn::make('name_internal')
                    ->label(__('Default name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('vod_channels_count')
                    ->label(__('VOD Channels'))
                    ->description(fn (Group $record): string => "Enabled: {$record->enabled_vod_channels_count}")
                    ->toggleable()
                    ->sortable(),
                IconColumn::make('custom')
                    ->label(__('Custom'))
                    ->icon(fn (string $state): string => match ($state) {
                        '1' => 'heroicon-o-check-circle',
                        '0' => 'heroicon-o-minus-circle',
                        '' => 'heroicon-o-minus-circle',
                    })->color(fn (string $state): string => match ($state) {
                        '1' => 'success',
                        '0' => 'danger',
                        '' => 'danger',
                    })->toggleable()->sortable(),
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
                    PlaylistService::getAddGroupsToPlaylistAction('add', 'channel'),
                    Action::make('move')
                        ->label(__('Move Channels to Group'))
                        ->schema([
                            Select::make('group')
                                ->required()
                                ->live()
                                ->label(__('Group'))
                                ->helperText(__('Select the group you would like to move the channels to.'))
                                ->options(fn (Get $get, $record) => Group::where([
                                    'type' => 'vod',
                                    'user_id' => auth()->id(),
                                    'playlist_id' => $record->playlist_id,
                                ])->get(['name', 'id'])->pluck('name', 'id'))
                                ->searchable(),
                        ])
                        ->action(function ($record, array $data): void {
                            $group = Group::findOrFail($data['group']);
                            $record->channels()->update([
                                'group' => $group->name,
                                'group_id' => $group->id,
                            ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Channels moved to group'))
                                ->body(__('The group channels have been moved to the chosen group.'))
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription(__('Move the group channels to the another group.'))
                        ->modalSubmitActionLabel(__('Move now')),

                    Action::make('recount')
                        ->label(__('Recount Channels'))
                        ->icon('heroicon-o-hashtag')
                        ->schema([
                            TextInput::make('start')
                                ->label(__('Start Number'))
                                ->numeric()
                                ->default(1)
                                ->required(),
                        ])
                        ->action(function (Group $record, array $data): void {
                            $start = (int) $data['start'];
                            SortFacade::bulkRecountGroupChannels($record, $start);
                        })
                        ->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Channels Recounted'))
                                ->body(__('The channels in this group have been recounted.'))
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-hashtag')
                        ->modalDescription(__('Recount all channels in this group sequentially?')),
                    Action::make('sort_alpha')
                        ->label(__('Sort Alpha'))
                        ->icon('heroicon-o-bars-arrow-down')
                        ->schema([
                            Select::make('column')
                                ->label(__('Sort By'))
                                ->options([
                                    'title' => 'Title (or override if set)',
                                    'name' => 'Name (or override if set)',
                                    'stream_id' => 'ID (or override if set)',
                                    'channel' => 'Channel No.',
                                ])
                                ->default('title')
                                ->required(),
                            Select::make('sort')
                                ->label(__('Sort Order'))
                                ->options([
                                    'ASC' => 'A to Z or 0 to 9',
                                    'DESC' => 'Z to A or 9 to 0',
                                ])
                                ->default('ASC')
                                ->required(),
                        ])
                        ->action(function (Group $record, array $data): void {
                            $order = $data['sort'] ?? 'ASC';
                            $column = $data['column'] ?? 'title';
                            SortFacade::bulkSortGroupChannels($record, $order, $column);
                        })
                        ->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Channels Sorted'))
                                ->body(__('The channels in this group have been sorted alphabetically.'))
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-bars-arrow-down')
                        ->modalDescription(__('Sort all channels in this group alphabetically? This will update the sort order.')),

                    Action::make('process_vod')
                        ->label(__('Fetch Metadata'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->schema([
                            Toggle::make('overwrite_existing')
                                ->label(__('Overwrite Existing Metadata'))
                                ->helperText(__('Overwrite existing metadata? If disabled, it will only fetch and process metadata if it does not already exist.'))
                                ->default(false),
                        ])
                        ->action(function ($record, array $data) {
                            foreach ($record->enabled_channels as $channel) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new ProcessVodChannels(
                                        channel: $channel,
                                        force: $data['overwrite_existing'] ?? false,
                                    ));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Fetching VOD metadata for channel'))
                                ->body(__('The VOD metadata fetching and processing has been started for the group channels. Only enabled channels will be processed. You will be notified when it is complete.'))
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalIcon('heroicon-o-arrow-down-tray')
                        ->modalDescription(__('Fetch and process VOD metadata for the group channels.'))
                        ->modalSubmitActionLabel(__('Yes, process now')),

                    Action::make('sync_vod')
                        ->label(__('Sync VOD .strm file'))
                        ->action(function ($record) {
                            foreach ($record->enabled_channels as $channel) {
                                app('Illuminate\Contracts\Bus\Dispatcher')
                                    ->dispatch(new SyncVodStrmFiles(
                                        channel: $channel,
                                    ));
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('.strm files are being synced for the group channels. Only enabled channels will be synced.'))
                                ->body(__('You will be notified once complete.'))
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-document-arrow-down')
                        ->modalIcon('heroicon-o-document-arrow-down')
                        ->modalDescription(__('Sync group VOD channels .strm files now? This will generate .strm files for the group channels.'))
                        ->modalSubmitActionLabel(__('Yes, sync now')),

                    Action::make('enable')
                        ->label(__('Enable group channels'))
                        ->action(function ($record): void {
                            $record->channels()->update([
                                'enabled' => true,
                            ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Group channels enabled'))
                                ->body(__('The group channels have been enabled.'))
                                ->send();
                        })
                        ->color('success')
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription(__('Enable group channels now?'))
                        ->modalSubmitActionLabel(__('Yes, enable now')),
                    Action::make('disable')
                        ->label(__('Disable group channels'))
                        ->action(function ($record): void {
                            $record->channels()->update([
                                'enabled' => false,
                            ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Group channels disabled'))
                                ->body(__('The group channels have been disabled.'))
                                ->send();
                        })
                        ->color('warning')
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription(__('Disable group channels now?'))
                        ->modalSubmitActionLabel(__('Yes, disable now')),

                    DeleteAction::make()
                        ->hidden(fn ($record) => ! $record->custom)
                        ->using(fn ($record) => $record->forceDelete()),
                ])->button()->hiddenLabel()->size('sm'),
                EditAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    PlaylistService::getAddGroupsToPlaylistBulkAction('add', 'channel'),
                    BulkAction::make('move')
                        ->label(__('Move Channels to Group'))
                        ->schema([
                            Select::make('group')
                                ->required()
                                ->live()
                                ->label(__('Group'))
                                ->helperText(__('Select the group you would like to move the channels to.'))
                                ->options(
                                    fn () => Group::query()
                                        ->with(['playlist'])
                                        ->where(['user_id' => auth()->id(), 'type' => 'vod'])
                                        ->get(['name', 'id', 'playlist_id'])
                                        ->transform(fn ($group) => [
                                            'id' => $group->id,
                                            'name' => $group->name.' ('.$group->playlist->name.')',
                                        ])->pluck('name', 'id')
                                )->searchable(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $group = Group::findOrFail($data['group']);
                            foreach ($records as $record) {
                                // Update the channels to the new group
                                // This will change the group and group_id for the channels in the database
                                // to reflect the new group
                                if ($group->playlist_id !== $record->playlist_id) {
                                    Notification::make()
                                        ->warning()
                                        ->title(__('Warning'))
                                        ->body("Cannot move \"{$group->name}\" to \"{$record->name}\" as they belong to different playlists.")
                                        ->persistent()
                                        ->send();

                                    continue;
                                }
                                $record->channels()->update([
                                    'group' => $group->name,
                                    'group_id' => $group->id,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Channels moved to group'))
                                ->body(__('The group channels have been moved to the chosen group.'))
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription(__('Move the group channels to the another group.'))
                        ->modalSubmitActionLabel(__('Move now')),
                    BulkAction::make('enable')
                        ->label(__('Enable Group Channels'))
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->channels()->update([
                                    'enabled' => true,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Selected group channels enabled'))
                                ->body(__('The selected group channels have been enabled.'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription(__('Enable the selected group(s) channels now?'))
                        ->modalSubmitActionLabel(__('Yes, enable now')),
                    BulkAction::make('disable')
                        ->label(__('Disable Group Channels'))
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->channels()->update([
                                    'enabled' => false,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Selected group channels disabled'))
                                ->body(__('The selected group channels have been disabled.'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription(__('Disable the selected group(s) channels now?'))
                        ->modalSubmitActionLabel(__('Yes, disable now')),

                    BulkAction::make('process_bulk_vod')
                        ->label(__('Fetch Metadata'))
                        ->icon('heroicon-o-arrow-down-tray')
                        ->schema([
                            Toggle::make('overwrite_existing')
                                ->label(__('Overwrite Existing Metadata'))
                                ->helperText(__('Overwrite existing metadata? If disabled, it will only fetch and process metadata if it does not already exist.'))
                                ->default(false),
                        ])
                        ->action(function (Collection $records, array $data) {
                            foreach ($records as $record) {
                                foreach ($record->enabled_channels as $channel) {
                                    app('Illuminate\Contracts\Bus\Dispatcher')
                                        ->dispatch(new ProcessVodChannels(
                                            channel: $channel,
                                            force: $data['overwrite_existing'] ?? false,
                                        ));
                                }
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Fetching VOD metadata for selected group channels'))
                                ->body(__('The VOD metadata fetching and processing has been started for the selected group channels. Only enabled channels will be processed. You will be notified when it is complete.'))
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrow-down-tray')
                        ->modalIcon('heroicon-o-arrow-down-tray')
                        ->modalDescription(__('Fetch and process VOD metadata for the selected group channels.'))
                        ->modalSubmitActionLabel(__('Yes, process now')),

                    BulkAction::make('sync_bulk_vod')
                        ->label(__('Sync VOD .strm file'))
                        ->action(function (Collection $records) {
                            foreach ($records as $record) {
                                foreach ($record->enabled_channels as $channel) {
                                    app('Illuminate\Contracts\Bus\Dispatcher')
                                        ->dispatch(new SyncVodStrmFiles(
                                            channel: $channel,
                                        ));
                                }
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('.strm files are being synced for the selected group channels. Only enabled channels will be synced.'))
                                ->body(__('You will be notified once complete.'))
                                ->duration(10000)
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-document-arrow-down')
                        ->modalIcon('heroicon-o-document-arrow-down')
                        ->modalDescription(__('Sync selected group VOD channels .strm files now? This will generate .strm files for the group channels.'))
                        ->modalSubmitActionLabel(__('Yes, sync now')),

                    BulkAction::make('enable_groups')
                        ->label(__('Enable Groups'))
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => true,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Selected groups enabled'))
                                ->body(__('The selected groups have been enabled.'))
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription(__('Enable the selected group(s) now?'))
                        ->modalSubmitActionLabel(__('Yes, enable now')),
                    BulkAction::make('disable_groups')
                        ->label(__('Disable Groups'))
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => false,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Selected groups disabled'))
                                ->body(__('The selected groups have been disabled.'))
                                ->send();
                        })
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription(__('Disable the selected group(s) now?'))
                        ->modalSubmitActionLabel(__('Yes, disable now')),
                    BulkAction::make('find-replace')
                        ->label(__('Find & Replace'))
                        ->schema(fn () => FindReplaceService::getBulkActionSchema('vod_groups'))
                        ->action(function (Collection $records, array $data): void {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new GroupFindAndReplace(
                                    user_id: auth()->id(),
                                    use_regex: $data['use_regex'] ?? true,
                                    find_replace: $data['find_replace'] ?? '',
                                    replace_with: $data['replace_with'] ?? '',
                                    groups: $records,
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
                        ->modalDescription(__('Select what you would like to find and replace in the selected group names.'))
                        ->modalSubmitActionLabel(__('Replace now')),
                    BulkAction::make('find-replace-reset')
                        ->label(__('Undo Find & Replace'))
                        ->action(function (Collection $records): void {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new GroupFindAndReplaceReset(
                                    user_id: auth()->id(),
                                    groups: $records,
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
                        ->modalDescription(__('Reset group names back to their original imported values? This will undo any find & replace changes.'))
                        ->modalSubmitActionLabel(__('Reset now')),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            VodRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVodGroups::route('/'),
            // 'create' => Pages\CreateVodGroup::route('/create'),
            'edit' => EditVodGroup::route('/{record}/edit'),
        ];
    }

    public static function getForm(): array
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
            Select::make('playlist_id')
                ->required()
                ->label(__('Playlist'))
                ->relationship(name: 'playlist', titleAttribute: 'name')
                ->helperText(__('Select the playlist you would like to add the group to.'))
                ->preload()
                ->hiddenOn(['edit'])
                ->searchable(),
            TextInput::make('sort_order')
                ->label(__('Sort Order'))
                ->numeric()
                ->default(9999)
                ->helperText(__('Enter a number to define the sort order (e.g., 1, 2, 3). Lower numbers appear first.'))
                ->rules(['integer', 'min:0']),
            Select::make('stream_file_setting_id')
                ->label(__('Stream File Setting'))
                ->searchable()
                ->relationship('streamFileSetting', 'name', fn ($query) => $query->forVod()->where('user_id', auth()->id())
                )
                ->nullable()
                ->helperText(__('Select a Stream File Setting profile for all VOD channels in this group. VOD-level settings take priority. Leave empty to use global settings.')),
        ];

        return [
            Section::make(__('Group Settings'))
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
        ];
    }
}
