<?php

namespace App\Filament\Resources\Groups;

use App\Facades\SortFacade;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\RelationManagers\ChannelsRelationManager;
use App\Models\Group;
use App\Services\PlaylistService;
use App\Traits\HasUserFiltering;
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

                    PlaylistService::getMergeAction(groupScoped: true)
                        ->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Channel merge started')
                                ->body('Merging channels in the background for this group only. You will be notified once the process is complete.')
                                ->send();
                        }),
                    PlaylistService::getUnmergeAction(groupScoped: true)
                        ->after(function () {
                            Notification::make()
                                ->success()
                                ->title('Channel unmerge started')
                                ->body('Unmerging channels for this group in the background. You will be notified once the process is complete.')
                                ->send();
                        }),

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
