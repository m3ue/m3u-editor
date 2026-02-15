<?php

namespace App\Filament\Resources\Groups;

use App\Facades\SortFacade;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\RelationManagers\ChannelsRelationManager;
use App\Jobs\MergeChannels;
use App\Jobs\UnmergeChannels;
use App\Models\Group;
use App\Models\Playlist;
use App\Services\PlaylistService;
use App\Traits\HasUserFiltering;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Group as ComponentsGroup;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class GroupResource extends Resource
{
    use HasUserFiltering;

    protected static ?string $model = Group::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $label = 'Live Group';

    protected static ?string $pluralLabel = 'Groups';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'name_internal'];
    }

    protected static string|\UnitEnum|null $navigationGroup = 'Live Channels';

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
                $query->withCount('live_channels')
                    ->withCount('enabled_live_channels')
                    ->where('type', 'live');
            })
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label('Filters');
            })
            ->reorderRecordsTriggerAction(function ($action) {
                return $action->button()->label('Sort');
            })
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('sort_order', 'asc')
            ->reorderable('sort_order')
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
                TextInputColumn::make('sort_order')
                    ->label('Sort Order')
                    ->rules(['min:0'])
                    ->type('number')
                    ->placeholder('Sort Order')
                    ->sortable()
                    ->tooltip(fn ($record) => $record->playlist->auto_sort ? 'Playlist auto-sort enabled; any changes will be overwritten on next sync' : 'Group sort order')
                    ->toggleable(),
                ToggleColumn::make('enabled')
                    ->label('Auto Enable')
                    ->toggleable()
                    ->tooltip('Auto enable newly added group channels')
                    ->tooltip(fn ($record) => $record->playlist?->enable_channels ? 'Playlist auto-enable new channels is enabled, all group channels will automatically be enabled on next sync.' : 'Auto enable newly added group channels')
                    ->disabled(fn ($record) => $record->playlist?->enable_channels)
                    ->getStateUsing(fn ($record) => $record->playlist?->enable_channels ? true : $record->enabled)
                    ->sortable(),
                TextColumn::make('name_internal')
                    ->label('Default name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('live_channels_count')
                    ->label('Live Channels')
                    ->description(fn (Group $record): string => "Enabled: {$record->enabled_live_channels_count}")
                    ->toggleable()
                    ->sortable(),
                IconColumn::make('custom')
                    ->label('Custom')
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
                ActionGroup::make([
                    PlaylistService::getAddToPlaylistAction('add', 'channel', fn ($record) => $record->channels())
                        ->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Group channels added to custom playlist')
                                ->body('The groups channels have been added to the chosen custom playlist.')
                                ->send();
                        }),
                    Action::make('move')
                        ->label('Move Channels to Group')
                        ->schema([
                            Select::make('group')
                                ->required()
                                ->live()
                                ->label('Group')
                                ->helperText('Select the group you would like to move the channels to.')
                                ->options(fn (Get $get, $record) => Group::where([
                                    'type' => 'live',
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
                                ->title('Channels moved to group')
                                ->body('The group channels have been moved to the chosen group.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription('Move the group channels to the another group.')
                        ->modalSubmitActionLabel('Move now'),

                    Action::make('recount')
                        ->label('Recount Channels')
                        ->icon('heroicon-o-hashtag')
                        ->schema([
                            TextInput::make('start')
                                ->label('Start Number')
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
                                ->title('Channels Recounted')
                                ->body('The channels in this group have been recounted.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-hashtag')
                        ->modalDescription('Recount all channels in this group sequentially? Channel numbers will be assigned based on the current sort order.'),
                    Action::make('sort_alpha')
                        ->label('Sort Alpha')
                        ->icon('heroicon-o-bars-arrow-down')
                        ->schema([
                            Select::make('column')
                                ->label('Sort By')
                                ->options([
                                    'title' => 'Title (or override if set)',
                                    'name' => 'Name (or override if set)',
                                    'stream_id' => 'ID (or override if set)',
                                    'channel' => 'Channel No.',
                                ])
                                ->default('title')
                                ->required(),
                            Select::make('sort')
                                ->label('Sort Order')
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
                                ->title('Channels Sorted')
                                ->body('The channels in this group have been sorted alphabetically.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-bars-arrow-down')
                        ->modalDescription('Sort all channels in this group alphabetically? This will update the sort order.'),

                    Action::make('merge')
                        ->label('Merge Same ID')
                        ->schema([
                            Fieldset::make('Merge source configuration')
                                ->schema([
                                    Select::make('playlist_id')
                                        ->required()
                                        ->label('Preferred Playlist')
                                        ->options(Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                                        ->live()
                                        ->searchable()
                                        ->helperText('Select a playlist to prioritize as the master during the merge process.'),
                                    Repeater::make('failover_playlists')
                                        ->label('')
                                        ->helperText('Select one or more playlists use as failover source(s).')
                                        ->reorderable()
                                        ->reorderableWithButtons()
                                        ->orderColumn('sort')
                                        ->simple(
                                            Select::make('playlist_failover_id')
                                                ->label('Failover Playlists')
                                                ->options(Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                                                ->searchable()
                                                ->required()
                                        )
                                        ->distinct()
                                        ->columns(1)
                                        ->addActionLabel('Add failover playlist')
                                        ->columnSpanFull()
                                        ->minItems(1)
                                        ->defaultItems(1),
                                ])
                                ->columnSpanFull(),
                            Fieldset::make('Merge behavior')
                                ->schema([
                                    Toggle::make('by_resolution')
                                        ->label('Order by Resolution')
                                        ->live()
                                        ->helperText('⚠️ IPTV WARNING: This will analyze each stream to determine resolution, which may cause rate limiting or blocking with IPTV providers. Only enable if your provider allows stream analysis.')
                                        ->default(false),
                                    Toggle::make('deactivate_failover_channels')
                                        ->label('Deactivate Failover Channels')
                                        ->helperText('When enabled, channels that become failovers will be automatically disabled.')
                                        ->default(false),
                                    Toggle::make('prefer_catchup_as_primary')
                                        ->label('Prefer catch-up channels as primary')
                                        ->helperText('When enabled, catch-up channels will be selected as the master when available.')
                                        ->default(false),
                                    Toggle::make('exclude_disabled_groups')
                                        ->label('Exclude disabled groups from master selection')
                                        ->helperText('Channels from disabled groups will never be selected as master.')
                                        ->default(false),
                                    Toggle::make('force_complete_remerge')
                                        ->label('Force complete re-merge')
                                        ->helperText('Re-evaluate ALL existing failover relationships, not just unmerged channels.')
                                        ->default(false),
                                ])
                                ->columns(2)
                                ->columnSpanFull(),
                            Fieldset::make('Advanced Priority Scoring (optional)')
                                ->schema([
                                    Select::make('prefer_codec')
                                        ->label('Preferred Codec')
                                        ->options([
                                            'hevc' => 'HEVC / H.265 (smaller file size)',
                                            'h264' => 'H.264 / AVC (better compatibility)',
                                        ])
                                        ->placeholder('No preference')
                                        ->helperText('Prioritize channels with a specific video codec.'),
                                    TagsInput::make('priority_keywords')
                                        ->label('Priority Keywords')
                                        ->placeholder('Add keyword...')
                                        ->helperText('Channels with these keywords in their name will be prioritized (e.g., "RAW", "LOCAL", "HD").')
                                        ->splitKeys(['Tab', 'Return']),
                                    Repeater::make('group_priorities')
                                        ->label('Group Priority Weights')
                                        ->helperText('Assign priority weights to specific groups. Higher weight = more preferred as master. Leave empty for default behavior.')
                                        ->columnSpanFull()
                                        ->columns(2)
                                        ->schema([
                                            Select::make('group_id')
                                                ->label('Group')
                                                ->options(fn () => Group::query()
                                                    ->with(['playlist'])
                                                    ->where(['user_id' => auth()->id(), 'type' => 'live'])
                                                    ->get(['name', 'id', 'playlist_id'])
                                                    ->transform(fn ($group) => [
                                                        'id' => $group->id,
                                                        'name' => $group->name.' ('.$group->playlist->name.')',
                                                    ])->pluck('name', 'id')
                                                )
                                                ->searchable()
                                                ->required(),
                                            TextInput::make('weight')
                                                ->label('Weight')
                                                ->numeric()
                                                ->default(100)
                                                ->minValue(1)
                                                ->maxValue(1000)
                                                ->helperText('1-1000, higher = more preferred')
                                                ->required(),
                                        ])
                                        ->reorderable()
                                        ->reorderableWithButtons()
                                        ->addActionLabel('Add group priority')
                                        ->defaultItems(0)
                                        ->dehydrateStateUsing(function ($state) {
                                            if (is_array($state) && ! empty($state)) {
                                                $formatted = [];
                                                foreach ($state as $item) {
                                                    if (is_array($item) && isset($item['weight'])) {
                                                        $groupId = $item['group_id'] ?? null;
                                                        if (! $groupId) {
                                                            continue;
                                                        }
                                                        $formatted[] = [
                                                            'group_id' => $groupId,
                                                            'weight' => (int) $item['weight'],
                                                        ];
                                                    }
                                                }

                                                return $formatted;
                                            }

                                            return [];
                                        }),
                                    Repeater::make('priority_attributes')
                                        ->label('Priority Order')
                                        ->helperText('Drag to reorder priority attributes. First attribute has highest priority. Leave empty for default order.')
                                        ->columnSpanFull()
                                        ->simple(
                                            Select::make('attribute')
                                                ->options([
                                                    'playlist_priority' => '📋 Playlist Priority (from failover list order)',
                                                    'group_priority' => '📁 Group Priority (from weights above)',
                                                    'catchup_support' => '⏪ Catch-up/Replay Support',
                                                    'resolution' => '📺 Resolution (requires stream analysis)',
                                                    'codec' => '🎬 Codec Preference (HEVC/H264)',
                                                    'keyword_match' => '🏷️ Keyword Match',
                                                ])
                                                ->required()
                                        )
                                        ->reorderable()
                                        ->reorderableWithDragAndDrop()
                                        ->distinct()
                                        ->addActionLabel('Add priority attribute')
                                        ->defaultItems(0)
                                        ->afterStateHydrated(function ($component, $state) {
                                            if (is_array($state) && ! empty($state)) {
                                                $formatted = [];
                                                foreach ($state as $item) {
                                                    if (is_string($item)) {
                                                        $formatted[] = ['attribute' => $item];
                                                    } elseif (is_array($item) && isset($item['attribute'])) {
                                                        $formatted[] = $item;
                                                    }
                                                }
                                                $component->state($formatted);
                                            }
                                        }),
                                ])
                                ->columns(2)
                                ->columnSpanFull(),
                        ])
                        ->action(function (Group $record, array $data): void {
                            $weightedConfig = null;
                            $groupPriorities = $data['group_priorities'] ?? [];
                            $priorityAttributes = collect($data['priority_attributes'] ?? [])
                                ->pluck('attribute')
                                ->filter()
                                ->values()
                                ->toArray();

                            if (! empty($data['priority_keywords']) || ! empty($data['prefer_codec']) || ($data['exclude_disabled_groups'] ?? false) || ! empty($groupPriorities) || ! empty($priorityAttributes)) {
                                $weightedConfig = [
                                    'priority_keywords' => $data['priority_keywords'] ?? [],
                                    'prefer_codec' => $data['prefer_codec'] ?? null,
                                    'exclude_disabled_groups' => $data['exclude_disabled_groups'] ?? false,
                                    'group_priorities' => $groupPriorities,
                                    'priority_attributes' => $priorityAttributes,
                                ];
                            }

                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new MergeChannels(
                                    user: auth()->user(),
                                    playlists: collect($data['failover_playlists']),
                                    playlistId: $data['playlist_id'],
                                    checkResolution: $data['by_resolution'] ?? false,
                                    deactivateFailoverChannels: $data['deactivate_failover_channels'] ?? false,
                                    forceCompleteRemerge: $data['force_complete_remerge'] ?? false,
                                    preferCatchupAsPrimary: $data['prefer_catchup_as_primary'] ?? false,
                                    groupId: $record->id,
                                    weightedConfig: $weightedConfig,
                                ));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Channel merge started')
                                ->body('Merging channels in the background for this group only. You will be notified once the process is complete.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-pointing-in')
                        ->modalIcon('heroicon-o-arrows-pointing-in')
                        ->modalDescription('Merge all channels with the same ID in this group into a single channel with failover.')
                        ->modalWidth(Width::FourExtraLarge)
                        ->modalSubmitActionLabel('Merge now'),

                    Action::make('unmerge')
                        ->label('Unmerge Same ID')
                        ->schema([
                            Toggle::make('reactivate_channels')
                                ->label('Reactivate disabled channels')
                                ->helperText('Enable channels that were previously disabled during merge.')
                                ->default(false),
                        ])
                        ->action(function (Group $record, array $data): void {
                            app('Illuminate\Contracts\Bus\Dispatcher')
                                ->dispatch(new UnmergeChannels(
                                    user: auth()->user(),
                                    groupId: $record->id,
                                    reactivateChannels: $data['reactivate_channels'] ?? false,
                                ));
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Channel unmerge started')
                                ->body('Unmerging channels for this group in the background. You will be notified once the process is complete.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-pointing-out')
                        ->color('warning')
                        ->modalIcon('heroicon-o-arrows-pointing-out')
                        ->modalDescription('Unmerge all channels with the same ID in this group, removing all failover relationships.')
                        ->modalSubmitActionLabel('Unmerge now'),

                    Action::make('enable')
                        ->label('Enable group channels')
                        ->action(function ($record): void {
                            $record->channels()->update([
                                'enabled' => true,
                            ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Group channels enabled')
                                ->body('The group channels have been enabled.')
                                ->send();
                        })
                        ->color('success')
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable group channels now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    Action::make('disable')
                        ->label('Disable group channels')
                        ->action(function ($record): void {
                            $record->channels()->update([
                                'enabled' => false,
                            ]);
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Group channels disabled')
                                ->body('The groups channels have been disabled.')
                                ->send();
                        })
                        ->color('warning')
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable group channels now?')
                        ->modalSubmitActionLabel('Yes, disable now'),
                    DeleteAction::make()
                        ->hidden(fn ($record) => ! $record->custom),
                ])->button()->hiddenLabel()->size('sm'),
                EditAction::make()
                    ->button()->hiddenLabel()->size('sm'),
            ], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    PlaylistService::getAddToPlaylistBulkAction('add', 'channel', function (Collection $records) {
                        return $records->flatMap->channels;
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Group channels added to custom playlist')
                            ->body('The groups channels have been added to the chosen custom playlist.')
                            ->send();
                    }),
                    BulkAction::make('move')
                        ->label('Move Channels to Group')
                        ->schema([
                            Select::make('group')
                                ->required()
                                ->live()
                                ->label('Group')
                                ->helperText('Select the group you would like to move the channels to.')
                                ->options(
                                    fn () => Group::query()
                                        ->with(['playlist'])
                                        ->where(['user_id' => auth()->id(), 'type' => 'live'])
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
                                        ->title('Warning')
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
                                ->title('Channels moved to group')
                                ->body('The group channels have been moved to the chosen group.')
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription('Move the group channels to the another group.')
                        ->modalSubmitActionLabel('Move now'),
                    BulkAction::make('enable')
                        ->label('Enable Group Channels')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->channels()->update([
                                    'enabled' => true,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected group channels enabled')
                                ->body('The selected group channels have been enabled.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable the selected group(s) channels now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    BulkAction::make('disable')
                        ->label('Disable Group Channels')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->channels()->update([
                                    'enabled' => false,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected group channels disabled')
                                ->body('The selected groups channels have been disabled.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected group(s) channels now?')
                        ->modalSubmitActionLabel('Yes, disable now'),
                    BulkAction::make('enable_groups')
                        ->label('Enable Groups')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => true,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected groups enabled')
                                ->body('The selected groups have been enabled.')
                                ->send();
                        })
                        ->color('success')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-check-circle')
                        ->modalIcon('heroicon-o-check-circle')
                        ->modalDescription('Enable the selected group(s) now?')
                        ->modalSubmitActionLabel('Yes, enable now'),
                    BulkAction::make('disable_groups')
                        ->label('Disable Groups')
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => false,
                                ]);
                            }
                        })->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Selected groups disabled')
                                ->body('The selected groups have been disabled.')
                                ->send();
                        })
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription('Disable the selected group(s) now?')
                        ->modalSubmitActionLabel('Yes, disable now'),
                    BulkAction::make('recount_channels')
                        ->label('Recount Channels')
                        ->icon('heroicon-o-hashtag')
                        ->form([
                            TextInput::make('start')
                                ->label('Start Number')
                                ->numeric()
                                ->default(1)
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            // Sort the selected groups by their sort_order to ensure sequential processing
                            // that matches the visual order in the table (assuming table is sorted by sort_order)
                            $sortedRecords = $records->sortBy('sort_order');
                            $start = (int) $data['start'];

                            foreach ($sortedRecords as $record) {
                                // Get channels for this group ordered by their current sort
                                $channels = $record->channels()->orderBy('sort')->get();
                                foreach ($channels as $channel) {
                                    $channel->update(['channel' => $start++]);
                                }
                            }
                        })
                        ->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Channels Recounted')
                                ->body('The channels in the selected groups have been recounted sequentially.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-hashtag')
                        ->modalDescription('Recount channels across selected groups? This will renumber channels sequentially starting from the top-most selected group down to the bottom-most.'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ChannelsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroups::route('/'),
            // 'create' => Pages\CreateGroup::route('/create'),
            'edit' => EditGroup::route('/{record}/edit'),
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
                ->label('Auto Enable New Channels')
                ->helperText('Automatically enable newly added channels to this group.')
                ->default(true),
            Select::make('playlist_id')
                ->required()
                ->label('Playlist')
                ->relationship(name: 'playlist', titleAttribute: 'name')
                ->helperText('Select the playlist you would like to add the group to.')
                ->preload()
                ->hiddenOn(['edit'])
                ->searchable(),
            TextInput::make('sort_order')
                ->label('Sort Order')
                ->numeric()
                ->default(9999)
                ->helperText('Enter a number to define the sort order (e.g., 1, 2, 3). Lower numbers appear first.')
                ->rules(['integer', 'min:0']),
        ];

        return [
            Section::make('Group Settings')
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
