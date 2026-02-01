<?php

namespace App\Filament\Resources\Groups\Pages;

use App\Facades\SortFacade;
use App\Filament\Resources\Groups\GroupResource;
use App\Jobs\MergeChannels;
use App\Jobs\UnmergeChannels;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class ViewGroup extends ViewRecord
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('add')
                    ->label('Add to Custom Playlist')
                    ->schema([
                        Select::make('playlist')
                            ->required()
                            ->live()
                            ->label('Custom Playlist')
                            ->helperText('Select the custom playlist you would like to add group channels to.')
                            ->options(CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->afterStateUpdated(function (Set $set, $state) {
                                if ($state) {
                                    $set('category', null);
                                }
                            })
                            ->searchable(),
                        Select::make('category')
                            ->label('Custom Group')
                            ->disabled(fn (Get $get) => ! $get('playlist'))
                            ->helperText(fn (Get $get) => ! $get('playlist') ? 'Select a custom playlist first.' : 'Select the group you would like to assign to the channels to.')
                            ->options(function ($get) {
                                $customList = CustomPlaylist::find($get('playlist'));

                                return $customList ? $customList->groupTags()->get()
                                    ->mapWithKeys(fn ($tag) => [$tag->getAttributeValue('name') => $tag->getAttributeValue('name')])
                                    ->toArray() : [];
                            })
                            ->searchable(),
                    ])
                    ->action(function ($record, array $data): void {
                        $playlist = CustomPlaylist::findOrFail($data['playlist']);
                        $playlist->channels()->syncWithoutDetaching($record->channels()->pluck('id'));
                        if ($data['category']) {
                            $tags = $playlist->groupTags()->get();
                            $tag = $playlist->groupTags()->where('name->en', $data['category'])->first();
                            foreach ($record->channels()->cursor() as $channel) {
                                // Need to detach any existing tags from this playlist first
                                $channel->detachTags($tags);
                                $channel->attachTag($tag);
                            }
                        }
                    })->after(function ($livewire) {
                        $livewire->dispatch('refreshRelation');
                        Notification::make()
                            ->success()
                            ->title('Group channels added to custom playlist')
                            ->body('The groups channels have been added to the chosen custom playlist.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-play')
                    ->modalIcon('heroicon-o-play')
                    ->modalDescription('Add the group channels to the chosen custom playlist.')
                    ->modalSubmitActionLabel('Add now'),
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
                        Select::make('prefer_codec')
                            ->label('Preferred Codec (optional)')
                            ->options([
                                'hevc' => 'HEVC / H.265',
                                'h264' => 'H.264 / AVC',
                            ])
                            ->placeholder('No preference')
                            ->helperText('Prioritize channels with a specific video codec.'),
                        TagsInput::make('priority_keywords')
                            ->label('Priority Keywords (optional)')
                            ->placeholder('Add keyword...')
                            ->helperText('Channels with these keywords in their name will be prioritized.')
                            ->splitKeys(['Tab', 'Return']),
                    ])
                    ->action(function (Group $record, array $data): void {
                        // Build weighted config if advanced options are used
                        $weightedConfig = null;
                        if (! empty($data['priority_keywords']) || ! empty($data['prefer_codec']) || ($data['exclude_disabled_groups'] ?? false)) {
                            $weightedConfig = [
                                'priority_keywords' => $data['priority_keywords'] ?? [],
                                'prefer_codec' => $data['prefer_codec'] ?? null,
                                'exclude_disabled_groups' => $data['exclude_disabled_groups'] ?? false,
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
                    ->modalSubmitActionLabel('Merge now'),

                Action::make('unmerge')
                    ->label('Unmerge Same ID')
                    ->action(function (Group $record, $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new UnmergeChannels(
                                user: auth()->user(),
                                groupId: $record->id,
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
