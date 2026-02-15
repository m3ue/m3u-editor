<?php

namespace App\Filament\Resources\Groups\Pages;

use App\Facades\SortFacade;
use App\Filament\Resources\Groups\GroupResource;
use App\Jobs\MergeChannels;
use App\Jobs\UnmergeChannels;
use App\Models\Group;
use App\Models\Playlist;
use App\Services\PlaylistService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;

class EditGroup extends EditRecord
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                PlaylistService::getAddToPlaylistAction('add', 'channel', fn ($record) => $record->channels())
                    ->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title('Group channels added to custom playlist')
                            ->body('The groups channels have been added to the chosen custom playlist.')
                            ->send();
                    }),
                Action::make('move')
                    ->label('Move to Group')
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
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
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
                    ->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
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
                    ->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
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
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
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
                    ->modalWidth(\Filament\Support\Enums\Width::FourExtraLarge)
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
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
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
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
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
            ])->button()->label('Actions'),
        ];
    }
}
