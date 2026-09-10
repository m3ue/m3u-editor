<?php

namespace App\Livewire\EasyEditor;

use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\Vods\VodResource;
use App\Livewire\EasyEditor\Concerns\ReordersVisiblePage;
use App\Models\Channel;
use App\Models\Group;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
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
 * Right pane of the Easy Editor: the channels for the selected group, scoped to
 * Live or VOD. Columns, filters, row actions and bulk actions are the exact
 * ones from ChannelResource / VodResource so edits behave identically to the
 * full screens. Only ever renders for a single group, keeping the list (and the
 * drag-sort) to a manageable size.
 */
class ChannelsPane extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable, ReordersVisiblePage {
        ReordersVisiblePage::reorderTable insteadof InteractsWithTable;
    }

    /** Table query-string identifier; the paginator page name is this + "Page". */
    private const QUERY_STRING_IDENTIFIER = 'easyEditorChannels';

    #[Locked]
    public ?int $playlistId = null;

    /** "live" or "vod". */
    #[Locked]
    public string $contentType = 'live';

    #[Locked]
    public ?int $selectedGroupId = null;

    public function mount(?int $playlistId = null, string $contentType = 'live', ?int $selectedGroupId = null): void
    {
        $this->playlistId = $playlistId;
        $this->contentType = in_array($contentType, ['live', 'vod'], true) ? $contentType : 'live';
        $this->selectedGroupId = $selectedGroupId;

        // The parent keys this component by group id, so a group switch remounts it.
        // Force page 1 here: Laravel's paginator resolver still reads a stale
        // ?easyEditorChannelsPage=N from the URL even with URL sync disabled, which
        // would otherwise land the new group's list on a page that doesn't exist.
        $this->paginators[self::QUERY_STRING_IDENTIFIER.'Page'] = 1;
    }

    public function render(): View
    {
        return view('livewire.easy-editor.channels-pane');
    }

    #[On('easy-editor-channels-changed')]
    public function onChannelsChanged(): void
    {
        $this->resetTable();
    }

    /**
     * Keep this table's pagination out of the page URL. Two embedded tables on
     * one page would otherwise both bind to the same `page` param and collide,
     * and a stale `?page=2` would survive a playlist / content-type switch and
     * land the fresh table on an empty page.
     */
    public function queryStringHandlesPagination(): array
    {
        return [];
    }

    protected function isVod(): bool
    {
        return $this->contentType === 'vod';
    }

    /**
     * @return class-string<ChannelResource|VodResource>
     */
    protected function resourceClass(): string
    {
        return $this->isVod() ? VodResource::class : ChannelResource::class;
    }

    protected function baseQuery(): Builder
    {
        return Channel::query()
            ->where('channels.user_id', auth()->id())
            ->where('is_vod', $this->isVod())
            ->when(
                $this->playlistId && $this->selectedGroupId,
                fn (Builder $query): Builder => $query
                    ->where('playlist_id', $this->playlistId)
                    ->where('group_id', $this->selectedGroupId),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            )
            ->with([
                'epgChannel' => fn ($q) => $q->select('id', 'epg_id', 'name', 'icon', 'icon_custom')->with('epg'),
                'aedProfile' => fn ($q) => $q->select('id', 'name'),
                'playlist' => fn ($q) => $q->select('id', 'name', 'uuid', 'auto_sort', 'enable_proxy', 'user_id')
                    ->with(['user' => fn ($uq) => $uq->select('id', 'is_admin', 'permissions')]),
                'customPlaylist' => fn ($q) => $q->select('id', 'name', 'uuid', 'enable_proxy', 'user_id')
                    ->with(['user' => fn ($uq) => $uq->select('id', 'is_admin', 'permissions')]),
                'streamProfile' => fn ($q) => $q->select('id', 'name'),
            ])
            ->withCount(['failovers']);
    }

    public function table(Table $table): Table
    {
        $resource = $this->resourceClass();
        $groupName = $this->selectedGroupId
            ? Group::query()->whereKey($this->selectedGroupId)->value('name')
            : null;

        // We only want the first two items
        [$editGroup, $edit] = $resource::getTableActions();

        return $table
            ->query(fn (): Builder => $this->baseQuery())
            // Distinct identifier so this table's page/search/sort/filter query-string
            // keys don't collide with the groups pane's table on the same page.
            ->queryStringIdentifier(self::QUERY_STRING_IDENTIFIER)
            ->heading($groupName)
            ->filtersTriggerAction(fn (Action $action): Action => $action->button()->label(__('Filters')))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->reorderable('sort')
            // Keep the list paginated during drag-reorder; a group can hold
            // thousands of channels and rendering them all at once hangs the tab.
            // ReordersVisiblePage rewrites only the visible page's sort slots.
            ->paginatedWhileReordering()
            ->defaultSort('sort')
            ->reorderRecordsTriggerAction(fn (Action $action): Action => $action->button()->label(__('Sort')))
            ->columns([
                ViewColumn::make('easy_editor_drag')
                    ->label(__('Move'))
                    ->alignCenter()
                    ->view('filament.easy-editor.channel-drag-handle'),
                ...$resource::getTableColumns(showGroup: false, showPlaylist: false, minimal: true),
            ])
            ->filters($resource::getTableFilters(showPlaylist: false))
            ->recordActions([$editGroup, $edit], position: RecordActionsPosition::BeforeCells)
            ->toolbarActions($resource::getTableBulkActions())
            ->headerActions([
                Action::make('createCustomChannel')
                    ->label(__('New Channel'))
                    ->icon('heroicon-o-plus')
                    ->button()
                    ->modalHeading(__('New Custom Channel'))
                    ->modalDescription(__('NOTE: Custom channels need to be associated with a Playlist or Custom Playlist.'))
                    ->slideOver()
                    ->schema(fn (): array => $resource::getForm())
                    ->action(function (array $data) use ($resource): void {
                        $resource::createCustomChannel(data: $data, model: Channel::class);

                        Notification::make()->success()->title(__('Channel created'))->send();
                        $this->resetTable();
                    }),
            ]);
    }
}
