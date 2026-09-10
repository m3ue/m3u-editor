<?php

namespace App\Livewire\EasyEditor;

use App\Facades\SortFacade;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\VodGroups\VodGroupResource;
use App\Livewire\EasyEditor\Concerns\ReordersVisiblePage;
use App\Models\Channel;
use App\Models\Group;
use App\Services\GroupChannelStateService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Left pane of the Easy Editor: the groups (Live or VOD) for the chosen
 * playlist, with inline rename, drag sort, the common per-group channel
 * actions, and a drop target so a channel dragged from the right pane can be
 * reassigned into the group.
 */
class GroupsPane extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable, ReordersVisiblePage {
        ReordersVisiblePage::reorderTable insteadof InteractsWithTable;
    }

    /** Table query-string identifier; the paginator page name is this + "Page". */
    private const QUERY_STRING_IDENTIFIER = 'easyEditorGroups';

    #[Locked]
    public ?int $playlistId = null;

    /** "live" or "vod". */
    #[Locked]
    public string $contentType = 'live';

    public ?int $selectedGroupId = null;

    public function mount(?int $playlistId = null, string $contentType = 'live', ?int $selectedGroupId = null): void
    {
        $this->playlistId = $playlistId;
        $this->contentType = in_array($contentType, ['live', 'vod'], true) ? $contentType : 'live';
        $this->selectedGroupId = $selectedGroupId;

        // The parent keys this component by playlist + content type, so either
        // switch remounts it - force page 1 so a stale ?easyEditorGroupsPage=N in
        // the URL can't land the new list on a page that doesn't exist.
        $this->paginators[self::QUERY_STRING_IDENTIFIER.'Page'] = 1;
    }

    public function render(): View
    {
        return view('livewire.easy-editor.groups-pane');
    }

    #[On('easy-editor-group-selected')]
    public function onGroupSelected(?int $groupId = null): void
    {
        $this->selectedGroupId = $groupId;
    }

    /**
     * Keep this table's pagination out of the page URL - see the matching note
     * on ChannelsPane. The two embedded tables must not share a `page` param,
     * and stale pagination must not survive a playlist / content-type switch.
     */
    public function queryStringHandlesPagination(): array
    {
        return [];
    }

    protected function isVod(): bool
    {
        return $this->contentType === 'vod';
    }

    protected function baseQuery(): Builder
    {
        $isVod = $this->isVod();

        return Group::query()
            ->where('groups.user_id', auth()->id())
            ->where('type', $this->contentType)
            ->where('is_merged', false)
            ->when(
                $this->playlistId,
                fn (Builder $query): Builder => $query->where('playlist_id', $this->playlistId),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            )
            ->withCount([
                'channels as channels_count' => fn (Builder $query) => $query->where('is_vod', $isVod),
                'channels as enabled_channels_count' => fn (Builder $query) => $query->where('is_vod', $isVod)->where('enabled', true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->baseQuery())
            // Distinct identifier so this table's page/search/sort/filter query-string
            // keys don't collide with the channels pane's table on the same page.
            ->queryStringIdentifier(self::QUERY_STRING_IDENTIFIER)
            ->reorderable('sort_order')
            ->paginatedWhileReordering()
            ->defaultSort('sort_order', 'asc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordAction('select')
            ->reorderRecordsTriggerAction(fn (Action $action): Action => $action->button()->label(__('Sort')))
            ->emptyStateHeading(__('No groups'))
            ->emptyStateDescription(__('Pick a playlist, or add a custom group to get started.'))
            ->columns([
                ViewColumn::make('easy_editor_drop')
                    ->label(__('Drop'))
                    ->alignCenter()
                    ->view('filament.easy-editor.group-drop-target'),
                TextInputColumn::make('name')
                    ->label(__('Name'))
                    ->rules(['max:255'])
                    ->placeholder(fn (Group $record): ?string => $record->name_internal)
                    ->searchable(),
                TextColumn::make('channels_count')
                    ->label(__('Channels'))
                    ->badge()
                    ->color('gray')
                    ->description(fn (Group $record): string => __(':count enabled', ['count' => (int) $record->enabled_channels_count])),
                ToggleColumn::make('enabled')
                    ->label(__('Auto Enable'))
                    ->tooltip(__('Automatically enable newly added channels in this group'))
                    ->alignCenter(),
                IconColumn::make('custom')
                    ->label(__('Custom'))
                    ->boolean()
                    ->trueIcon('heroicon-o-sparkles')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray')
                    ->alignCenter(),
            ])
            ->recordActions([
                Action::make('select')
                    ->label(__('Show channels'))
                    ->tooltip(__('Show channels in this group'))
                    ->icon(fn (Group $record): string => $this->selectedGroupId === $record->id ? 'heroicon-o-eye' : 'heroicon-o-eye-slash')
                    ->button()
                    ->hiddenLabel()
                    ->color(fn (Group $record): string => $this->selectedGroupId === $record->id ? 'primary' : 'gray')
                    ->action(fn (Group $record) => $this->selectGroup($record->id)),
                ActionGroup::make([
                    EditAction::make()
                        ->slideOver()
                        ->schema(fn (): array => $this->isVod() ? VodGroupResource::getForm() : GroupResource::getForm())
                        ->after(fn () => $this->dispatch('$refresh')),
                    Action::make('enableChannels')
                        ->label(__('Enable all channels'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(function (Group $record): void {
                            // Matches GroupResource/VodGroupResource: also renumbers
                            // and triggers a Plex/HDHR resync for live groups.
                            app(GroupChannelStateService::class)->enable($record);
                            $this->afterGroupChannelsChanged(__('Channels enabled'));
                        }),
                    Action::make('disableChannels')
                        ->label(__('Disable all channels'))
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function (Group $record): void {
                            app(GroupChannelStateService::class)->disable($record);
                            $this->afterGroupChannelsChanged(__('Channels disabled'));
                        }),
                    Action::make('sortAlpha')
                        ->label(__('Sort channels A-Z'))
                        ->icon('heroicon-o-bars-arrow-down')
                        ->schema([
                            Select::make('column')
                                ->label(__('Sort by'))
                                ->options([
                                    'title' => __('Title (or override if set)'),
                                    'name' => __('Name (or override if set)'),
                                    'stream_id' => __('ID (or override if set)'),
                                    'channel' => __('Channel No.'),
                                ])
                                ->default('title')
                                ->required(),
                            Select::make('direction')
                                ->label(__('Direction'))
                                ->options([
                                    'ASC' => __('A to Z or 0 to 9'),
                                    'DESC' => __('Z to A or 9 to 0'),
                                ])
                                ->default('ASC')
                                ->required(),
                        ])
                        ->action(function (Group $record, array $data): void {
                            SortFacade::bulkSortGroupChannels($record, $data['direction'] ?? 'ASC', $data['column'] ?? 'title');
                            $this->afterGroupChannelsChanged(__('Channels sorted'));
                        }),
                    DeleteAction::make()
                        ->visible(fn (Group $record): bool => (bool) $record->custom)
                        ->using(fn (Group $record) => $record->forceDelete())
                        ->after(function (Group $record): void {
                            // Only clear the selection if the deleted group was the one
                            // being viewed - deleting an unrelated group shouldn't close
                            // the channels pane out from under the user.
                            if ($record->id === $this->selectedGroupId) {
                                $this->selectGroup(null);
                            }
                        }),
                ])->label(__('Actions'))->icon('heroicon-m-ellipsis-vertical')
                    ->button()
                    ->size('sm')
                    ->hiddenLabel(),
            ], position: RecordActionsPosition::BeforeCells)
            ->headerActions([
                Action::make('createCustomGroup')
                    ->label(__('New Group'))
                    ->icon('heroicon-o-plus')
                    ->button()
                    ->visible(fn (): bool => (bool) $this->playlistId)
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        Group::create([
                            'name' => $data['name'],
                            'user_id' => auth()->id(),
                            'playlist_id' => $this->playlistId,
                            'custom' => true,
                            'type' => $this->contentType,
                            'enabled' => true,
                            'sort_order' => (int) $this->baseQuery()->max('sort_order') + 1,
                        ]);

                        Notification::make()->success()->title(__('Group created'))->send();
                        $this->dispatch('$refresh');
                    }),
                Action::make('renumberAllGroups')
                    ->label(__('Renumber all groups'))
                    ->icon('heroicon-o-hashtag')
                    ->color('gray')
                    ->visible(fn (): bool => (bool) $this->playlistId)
                    ->requiresConfirmation()
                    ->modalDescription(__('Renumber every channel across all groups in this playlist, in the current group and channel sort order.'))
                    ->schema([
                        TextInput::make('start')
                            ->label(__('Start Number'))
                            ->numeric()
                            ->default(1)
                            ->required(),
                        Toggle::make('active_only')
                            ->label(__('Active channels only'))
                            ->helperText(__('When enabled, only active channels are renumbered; disabled channels keep their current numbers.'))
                            ->default(false),
                    ])
                    ->action(function (array $data): void {
                        $groups = $this->baseQuery()->reorder()->orderBy('sort_order')->get();
                        SortFacade::bulkRecountGroupsByOrder($groups, (int) $data['start'], (bool) ($data['active_only'] ?? false));

                        Notification::make()->success()->title(__('Channels renumbered'))->send();
                        $this->dispatch('easy-editor-channels-changed');
                    }),
            ]);
    }

    public function selectGroup(?int $groupId): void
    {
        $this->selectedGroupId = $groupId;
        $this->dispatch('easy-editor-group-selected', groupId: $groupId);
    }

    /**
     * Drop handler for the cross-pane drag: move a channel dragged from the
     * channels pane into the given group. Ownership, content type and playlist
     * are all re-checked here so a tampered request can't reassign arbitrary
     * records.
     */
    public function moveChannelToGroup(int $channelId, int $groupId): void
    {
        $channel = Channel::query()
            ->where('user_id', auth()->id())
            ->where('is_vod', $this->isVod())
            ->find($channelId);

        $group = Group::query()
            ->where('user_id', auth()->id())
            ->where('type', $this->contentType)
            ->where('is_merged', false)
            ->find($groupId);

        if (! $channel || ! $group || $channel->group_id === $group->id) {
            return;
        }

        if ($channel->playlist_id !== $group->playlist_id) {
            Notification::make()->warning()->title(__('Cannot move a channel to a group in a different playlist'))->send();

            return;
        }

        $channel->update(['group' => $group->name, 'group_id' => $group->id]);

        Notification::make()
            ->success()
            ->title(__('Channel moved'))
            ->body(__(':channel moved to :group', [
                'channel' => $channel->title_custom ?: $channel->title ?: $channel->name_custom ?: $channel->name,
                'group' => $group->name,
            ]))
            ->send();

        $this->dispatch('$refresh');
        $this->dispatch('easy-editor-channels-changed');
    }

    protected function afterGroupChannelsChanged(string $title): void
    {
        Notification::make()->success()->title($title)->send();
        $this->dispatch('$refresh');
        $this->dispatch('easy-editor-channels-changed');
    }
}
