<?php

namespace App\Filament\Resources\Groups;

use App\Facades\SortFacade;
use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\RelationManagers\ChannelsRelationManager;
use App\Jobs\GroupFindAndReplace;
use App\Jobs\GroupFindAndReplaceReset;
use App\Jobs\SyncPlexDvrJob;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\StreamProfile;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class GroupResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
    use HasUserFiltering;

    protected static ?string $model = Group::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $label = 'Live Group';

    protected static ?string $pluralLabel = 'Groups';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'name_internal'];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Live Channels');
    }

    public static function getModelLabel(): string
    {
        return __('Group');
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
                $query->withCount('live_channels')
                    ->withCount('enabled_live_channels')
                    ->where('type', 'live');
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
                TextColumn::make('live_channels_count')
                    ->label(__('Live Channels'))
                    ->description(fn (Group $record): string => "Enabled: {$record->enabled_live_channels_count}")
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
                                ->title(__('Channels moved to group'))
                                ->body(__('The group channels have been moved to the chosen group.'))
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalIcon('heroicon-o-arrows-right-left')
                        ->modalDescription(__('Move the group channels to the another group.'))
                        ->modalSubmitActionLabel(__('Move now')),

                    Action::make('set-stream-profile')
                        ->label(__('Set Stream Profile'))
                        ->schema([
                            Select::make('stream_profile_id')
                                ->label(__('Stream Profile'))
                                ->options(fn () => StreamProfile::where('user_id', auth()->id())->pluck('name', 'id'))
                                ->searchable()
                                ->preload()
                                ->nullable()
                                ->placeholder(__('None (clear profile)')),
                            Toggle::make('overwrite_existing')
                                ->label(__('Overwrite existing channel assignments'))
                                ->helperText(__('When off, only channels without a stream profile will be updated. When on, all live channels in this group will be overwritten.'))
                                ->default(false),
                            Toggle::make('apply_to_new_channels')
                                ->label(__('Apply to channels added later'))
                                ->helperText(__('Save this profile on the group so future channels added to it inherit the assignment automatically. Disable to leave the saved group default unchanged.'))
                                ->default(false),
                        ])
                        ->action(function (Group $record, array $data): void {
                            $profileId = ! empty($data['stream_profile_id']) ? (int) $data['stream_profile_id'] : null;
                            $overwrite = (bool) ($data['overwrite_existing'] ?? false);
                            $persist = (bool) ($data['apply_to_new_channels'] ?? false);

                            $query = $record->live_channels();
                            if (! $overwrite) {
                                $query->whereNull('stream_profile_id');
                            }
                            $updated = $query->update(['stream_profile_id' => $profileId]);

                            if ($persist) {
                                $record->update(['stream_profile_id' => $profileId]);
                            }

                            Notification::make()
                                ->success()
                                ->title(__('Stream profile updated'))
                                ->body(trans_choice(':count channel updated|:count channels updated', $updated, ['count' => $updated]))
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-cog-6-tooth')
                        ->modalIcon('heroicon-o-cog-6-tooth')
                        ->modalDescription(__('Assign a stream profile to all live channels in this group.'))
                        ->modalSubmitActionLabel(__('Apply')),

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
                        ->modalDescription(__('Recount all channels in this group sequentially? Channel numbers will be assigned based on the current sort order.')),
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

                    PlaylistService::getMergeAction(groupScoped: true)
                        ->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Channel merge started'))
                                ->body(__('Merging channels in the background for this group only. You will be notified once the process is complete.'))
                                ->send();
                        }),
                    PlaylistService::getUnmergeAction(groupScoped: true)
                        ->after(function () {
                            Notification::make()
                                ->success()
                                ->title(__('Channel unmerge started'))
                                ->body(__('Unmerging channels for this group in the background. You will be notified once the process is complete.'))
                                ->send();
                        }),

                    Action::make('enable')
                        ->label(__('Enable group channels'))
                        ->action(function (Group $record): void {
                            $record->channels()->update([
                                'enabled' => true,
                            ]);

                            $maxChannel = Channel::query()
                                ->where('playlist_id', $record->playlist_id)
                                ->where('group_id', '!=', $record->id)
                                ->where('enabled', true)
                                ->max('channel') ?? 0;

                            SortFacade::bulkRecountGroupChannels($record, $maxChannel + 1);
                        })->after(function () {
                            dispatch(new SyncPlexDvrJob(trigger: 'group_enable'));
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
                            dispatch(new SyncPlexDvrJob(trigger: 'group_disable'));
                            Notification::make()
                                ->success()
                                ->title(__('Group channels disabled'))
                                ->body(__('The groups channels have been disabled.'))
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
                    BulkAction::make('set-stream-profile')
                        ->label(__('Set Stream Profile'))
                        ->schema([
                            Select::make('stream_profile_id')
                                ->label(__('Stream Profile'))
                                ->options(fn () => StreamProfile::where('user_id', auth()->id())->pluck('name', 'id'))
                                ->searchable()
                                ->preload()
                                ->nullable()
                                ->placeholder(__('None (clear profile)')),
                            Toggle::make('overwrite_existing')
                                ->label(__('Overwrite existing channel assignments'))
                                ->helperText(__('When off, only channels without a stream profile will be updated. When on, all live channels in the selected groups will be overwritten.'))
                                ->default(false),
                            Toggle::make('apply_to_new_channels')
                                ->label(__('Apply to channels added later'))
                                ->helperText(__('Save this profile on the group so future channels added to it inherit the assignment automatically. Disable to leave the saved group default unchanged.'))
                                ->default(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $profileId = ! empty($data['stream_profile_id']) ? (int) $data['stream_profile_id'] : null;
                            $overwrite = (bool) ($data['overwrite_existing'] ?? false);
                            $persist = (bool) ($data['apply_to_new_channels'] ?? false);
                            $updated = 0;

                            foreach ($records as $group) {
                                $query = $group->live_channels();
                                if (! $overwrite) {
                                    $query->whereNull('stream_profile_id');
                                }
                                $updated += $query->update(['stream_profile_id' => $profileId]);

                                if ($persist) {
                                    $group->update(['stream_profile_id' => $profileId]);
                                }
                            }

                            Notification::make()
                                ->success()
                                ->title(__('Stream profile updated'))
                                ->body(trans_choice(':count channel updated|:count channels updated', $updated, ['count' => $updated]))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-cog-6-tooth')
                        ->modalIcon('heroicon-o-cog-6-tooth')
                        ->modalDescription(__('Assign a stream profile to all live channels in the selected group(s).'))
                        ->modalSubmitActionLabel(__('Apply')),
                    BulkAction::make('enable')
                        ->label(__('Enable Group Channels'))
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->channels()->update([
                                    'enabled' => true,
                                ]);

                                $maxChannel = Channel::query()
                                    ->where('playlist_id', $record->playlist_id)
                                    ->where('group_id', '!=', $record->id)
                                    ->where('enabled', true)
                                    ->max('channel') ?? 0;

                                SortFacade::bulkRecountGroupChannels($record, $maxChannel + 1);
                            }
                        })->after(function () {
                            dispatch(new SyncPlexDvrJob(trigger: 'group_bulk_enable'));
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
                            dispatch(new SyncPlexDvrJob(trigger: 'group_bulk_disable'));
                            Notification::make()
                                ->success()
                                ->title(__('Selected group channels disabled'))
                                ->body(__('The selected groups channels have been disabled.'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->icon('heroicon-o-x-circle')
                        ->modalIcon('heroicon-o-x-circle')
                        ->modalDescription(__('Disable the selected group(s) channels now?'))
                        ->modalSubmitActionLabel(__('Yes, disable now')),
                    BulkAction::make('enable_groups')
                        ->label(__('Enable Groups'))
                        ->action(function (Collection $records): void {
                            foreach ($records as $record) {
                                $record->update([
                                    'enabled' => true,
                                ]);
                            }
                        })->after(function () {
                            dispatch(new SyncPlexDvrJob(trigger: 'group_bulk_enable_groups'));
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
                            dispatch(new SyncPlexDvrJob(trigger: 'group_bulk_disable_groups'));
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
                    BulkAction::make('recount_channels')
                        ->label(__('Recount Channels'))
                        ->icon('heroicon-o-hashtag')
                        ->form([
                            TextInput::make('start')
                                ->label(__('Start Number'))
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
                                ->title(__('Channels Recounted'))
                                ->body(__('The channels in the selected groups have been recounted sequentially.'))
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion()
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-hashtag')
                        ->modalDescription(__('Recount channels across selected groups? This will renumber channels sequentially starting from the top-most selected group down to the bottom-most.')),
                    BulkAction::make('find-replace')
                        ->label(__('Find & Replace'))
                        ->schema(fn () => FindReplaceService::getBulkActionSchema('groups'))
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
