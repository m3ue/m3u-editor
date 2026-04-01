<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Exports\ChannelExporter;
use App\Filament\Imports\ChannelImporter;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\EpgMaps\EpgMapResource;
use App\Jobs\ChannelFindAndReplace;
use App\Jobs\ChannelFindAndReplaceReset;
use App\Jobs\MapPlaylistChannelsToEpg;
use App\Models\Channel;
use App\Models\Playlist;
use App\Services\EpgCacheService;
use App\Services\PlaylistService;
use App\Traits\RenderlessColumnUpdates;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ImportAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Hydrat\TableLayoutToggle\Concerns\HasToggleableTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;

class ListChannels extends ListRecords
{
    use RenderlessColumnUpdates;

    // use HasToggleableTable;

    protected static string $resource = ChannelResource::class;

    protected ?string $subheading = 'NOTE: Playlist channel output order is based on: 1 Sort order, 2 Channel no. and 3 Channel title - in that order. You can edit your Playlist output to auto sort as well, which will define the sort order based on the playlist order.';

    #[Url(as: 'status')]
    public ?string $statusFilter = 'all';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Create Custom Channel')
                ->modalHeading('New Custom Channel')
                ->modalDescription('NOTE: Custom channels need to be associated with a Playlist or Custom Playlist.')
                ->using(fn (array $data, string $model): Model => ChannelResource::createCustomChannel(
                    data: $data,
                    model: $model,
                ))
                ->slideOver(),
            ActionGroup::make([
                PlaylistService::getMergeAction()
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Channel merge started')
                            ->body('Merging channels in the background. You will be notified once the process is complete.')
                            ->send();
                    }),
                PlaylistService::getUnmergeAction()
                    ->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Channel unmerge started')
                            ->body('Unmerging channels in the background. You will be notified once the process is complete.')
                            ->send();
                    }),
                Action::make('map')
                    ->label('Map EPG to Playlist')
                    ->schema(EpgMapResource::getForm())
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new MapPlaylistChannelsToEpg(
                                epg: (int) $data['epg_id'],
                                playlist: $data['playlist_id'],
                                force: $data['override'],
                                recurring: $data['recurring'],
                                settings: $data['settings'] ?? [],
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('EPG to Channel mapping')
                            ->body('Channel mapping started, you will be notified when the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->modalIcon('heroicon-o-link')
                    ->modalWidth(Width::FourExtraLarge)
                    ->modalDescription('Map the selected EPG to the selected Playlist channels.')
                    ->modalSubmitActionLabel('Map now'),
                Action::make('unmap')
                    ->label('Undo EPG Map')
                    ->schema([
                        Select::make('playlist_id')
                            ->label('Playlist')
                            ->options(Playlist::where('user_id', auth()->id())->pluck('name', 'id'))
                            ->live()
                            ->required()
                            ->searchable()
                            ->helperText(text: 'Playlist to clear EPG mappings for.'),
                    ])
                    ->action(function (array $data): void {
                        $playlist = Playlist::find($data['playlist_id']);
                        $playlist->live_channels()->update(['epg_channel_id' => null]);

                        // Invalidate cached EPG XML files for the playlist to reflect unmapped channels immediately
                        EpgCacheService::clearPlaylistEpgCacheFile($playlist);
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('EPG Channel mapping removed')
                            ->body('Channel mapping removed for the selected Playlist.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->modalIcon('heroicon-o-arrow-uturn-left')
                    ->modalDescription('Clear EPG mappings for all channels of the selected playlist.')
                    ->modalSubmitActionLabel('Reset now'),
                Action::make('find-replace')
                    ->label('Find & Replace')
                    ->schema(function (): array {
                        $savedPatterns = [];
                        $savedPatternRules = [];
                        $counter = 0;
                        foreach (Playlist::where('user_id', auth()->id())->get() as $playlist) {
                            foreach ($playlist->find_replace_rules ?? [] as $rule) {
                                if (is_array($rule) && ($rule['target'] ?? 'channels') === 'channels') {
                                    $savedPatterns[$counter] = "{$playlist->name} - ".($rule['name'] ?? 'Unnamed');
                                    $savedPatternRules[$counter] = $rule;
                                    $counter++;
                                }
                            }
                        }

                        return [
                            Select::make('saved_pattern')
                                ->label('Load saved pattern')
                                ->searchable()
                                ->placeholder('Select a saved pattern...')
                                ->options($savedPatterns)
                                ->hidden(empty($savedPatterns))
                                ->live()
                                ->afterStateUpdated(function (?string $state, Set $set) use ($savedPatternRules): void {
                                    if ($state === null || $state === '') {
                                        return;
                                    }
                                    $rule = $savedPatternRules[(int) $state] ?? null;
                                    if (! $rule) {
                                        return;
                                    }
                                    $set('use_regex', $rule['use_regex'] ?? true);
                                    $set('column', $rule['column'] ?? 'title');
                                    $set('find_replace', $rule['find_replace'] ?? '');
                                    $set('replace_with', $rule['replace_with'] ?? '');
                                })
                                ->dehydrated(false),
                            Toggle::make('all_playlists')
                                ->label('All Playlists')
                                ->live()
                                ->helperText('Apply find and replace to all playlists? If disabled, it will only apply to the selected playlist.')
                                ->default(true),
                            Select::make('playlist')
                                ->label('Playlist')
                                ->required()
                                ->helperText('Select the playlist you would like to apply changes to.')
                                ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                                ->hidden(fn (Get $get) => $get('all_playlists') === true)
                                ->searchable(),
                            Toggle::make('use_regex')
                                ->label('Use Regex')
                                ->live()
                                ->helperText('Use regex patterns to find and replace. If disabled, will use direct string comparison.')
                                ->default(true),
                            Select::make('column')
                                ->label('Column to modify')
                                ->options([
                                    'title' => 'Channel Title',
                                    'name' => 'Channel Name (tvg-name)',
                                ])
                                ->default('title')
                                ->required()
                                ->columnSpan(1),
                            TextInput::make('find_replace')
                                ->label(fn (Get $get) => ! $get('use_regex') ? 'String to replace' : 'Pattern to replace')
                                ->required()
                                ->placeholder(
                                    fn (Get $get) => $get('use_regex')
                                        ? '^(US- |UK- |CA- )'
                                        : 'US -'
                                )->helperText(
                                    fn (Get $get) => ! $get('use_regex')
                                        ? 'This is the string you want to find and replace.'
                                        : 'This is the regex pattern you want to find. Make sure to use valid regex syntax.'
                                ),
                            TextInput::make('replace_with')
                                ->label('Replace with (optional)')
                                ->placeholder('Leave empty to remove'),
                        ];
                    })
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ChannelFindAndReplace(
                                user_id: auth()->id(), // The ID of the user who owns the content
                                all_playlists: $data['all_playlists'] ?? false,
                                playlist_id: $data['playlist'] ?? null,
                                use_regex: $data['use_regex'] ?? true,
                                column: $data['column'] ?? 'title',
                                find_replace: $data['find_replace'] ?? null,
                                replace_with: $data['replace_with'] ?? ''
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Find & Replace started')
                            ->body('Find & Replace working in the background. You will be notified once the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->modalIcon('heroicon-o-magnifying-glass')
                    ->modalDescription('Select what you would like to find and replace in your channels list.')
                    ->modalSubmitActionLabel('Replace now'),

                Action::make('find-replace-reset')
                    ->label('Undo Find & Replace')
                    ->schema([
                        Toggle::make('all_playlists')
                            ->label('All Playlists')
                            ->live()
                            ->helperText('Apply reset to all playlists? If disabled, it will only apply to the selected playlist.')
                            ->default(false),
                        Select::make('playlist')
                            ->required()
                            ->label('Playlist')
                            ->helperText('Select the playlist you would like to apply the reset to.')
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->hidden(fn (Get $get) => $get('all_playlists') === true)
                            ->searchable(),
                        Select::make('column')
                            ->label('Column to reset')
                            ->options([
                                'title' => 'Channel Title',
                                'name' => 'Channel Name (tvg-name)',
                                'logo' => 'Channel Logo (tvg-logo)',
                                'url' => 'Custom URL (tvg-url)',
                            ])
                            ->default('title')
                            ->required()
                            ->columnSpan(1),
                    ])
                    ->action(function (array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ChannelFindAndReplaceReset(
                                user_id: auth()->id(), // The ID of the user who owns the content
                                all_playlists: $data['all_playlists'] ?? false,
                                playlist_id: $data['playlist'] ?? null,
                                column: $data['column'] ?? 'title',
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title('Find & Replace reset started')
                            ->body('Find & Replace reset working in the background. You will be notified once the process is complete.')
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->modalIcon('heroicon-o-arrow-uturn-left')
                    ->modalDescription('Reset Find & Replace results back to playlist defaults. This will remove any custom values set in the selected column.')
                    ->modalSubmitActionLabel('Reset now'),

                ImportAction::make()
                    ->importer(ChannelImporter::class)
                    ->label('Import Channels')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('primary')
                    ->modalDescription('Import channels from a CSV or XLSX file.'),
                ExportAction::make()
                    ->exporter(ChannelExporter::class)
                    ->label('Export Channels')
                    ->icon('heroicon-m-arrow-up-tray')
                    ->color('primary')
                    ->modalDescription('Export channels to a CSV or XLSX file. NOTE: Only enabled channels will be exported.')
                    ->columnMapping(false)
                    ->modifyQueryUsing(function ($query, array $options) {
                        // For now, only allow exporting enabled channels
                        return $query->where([
                            ['playlist_id', $options['playlist']],
                            ['enabled', true],
                        ]);
                        // return $query->where('playlist_id', $options['playlist'])
                        //     ->when($options['enabled'], function ($query, $enabled) {
                        //         return $query->where('enabled', $enabled);
                        //     });
                    }),
            ])->button()->label('Actions'),
        ];
    }

    public function getTabs(): array
    {
        $where = [['user_id', auth()->id()], ['is_vod', false]];
        $playlists = Playlist::where('user_id', auth()->id())->orderBy('name')->get();

        $playlistCounts = Channel::where($where)
            ->whereIn('playlist_id', $playlists->pluck('id'))
            ->groupBy('playlist_id')
            ->selectRaw('playlist_id, count(*) as aggregate')
            ->pluck('aggregate', 'playlist_id');

        return [
            'all' => Tab::make('All Playlists')
                ->badge($playlistCounts->sum()),
            ...($playlists->mapWithKeys(fn (Playlist $playlist) => [
                'playlist_'.$playlist->id => Tab::make($playlist->name)
                    ->modifyQueryUsing(fn ($query) => $query->where('playlist_id', $playlist->id))
                    ->badge($playlistCounts->get($playlist->id, 0)),
            ])->toArray()),
        ];
    }

    public static function setupTabs($relationId = null): array
    {
        $where = [
            ['user_id', auth()->id()],
            ['is_vod', false],
        ];

        $totalCount = Channel::query()
            ->where($where)
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();
        $enabledCount = Channel::query()->where([...$where, ['enabled', true]])
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();
        $disabledCount = Channel::query()->where([...$where, ['enabled', false]])
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();
        $customCount = Channel::query()->where([...$where, ['is_custom', true]])
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();

        $withFailoverCount = Channel::query()->whereHas('failovers')->where($where)
            ->when($relationId, function ($query, $relationId) {
                return $query->where('group_id', $relationId);
            })->count();

        return [
            'all' => Tab::make('All Live Channels')
                ->badge($totalCount),
            'enabled' => Tab::make('Enabled')
                ->badgeColor('success')
                ->modifyQueryUsing(fn ($query) => $query->where('enabled', true))
                ->badge($enabledCount),
            'disabled' => Tab::make('Disabled')
                ->badgeColor('danger')
                ->modifyQueryUsing(fn ($query) => $query->where('enabled', false))
                ->badge($disabledCount),
            'failover' => Tab::make('Failover')
                ->badgeColor('info')
                ->modifyQueryUsing(fn ($query) => $query->whereHas('failovers'))
                ->badge($withFailoverCount),
            'custom' => Tab::make('Custom')
                ->modifyQueryUsing(fn ($query) => $query->where('is_custom', true))
                ->badge($customCount),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
            View::make('filament.channels.status-tabs'),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }

    protected function modifyQueryWithActiveTab(Builder $query, bool $isResolvingRecord = false): Builder
    {
        $query = parent::modifyQueryWithActiveTab($query, $isResolvingRecord);

        return match ($this->statusFilter) {
            'enabled' => $query->where('enabled', true),
            'disabled' => $query->where('enabled', false),
            'failover' => $query->whereHas('failovers'),
            'custom' => $query->where('is_custom', true),
            default => $query,
        };
    }

    /**
     * @return array<string, int>
     */
    public function getStatusTabCounts(): array
    {
        $baseQuery = Channel::query()
            ->where('user_id', auth()->id())
            ->where('is_vod', false);

        // Apply the active playlist tab's query modifier
        $activeTab = $this->activeTab;
        if ($activeTab && $activeTab !== 'all') {
            $tabs = $this->getCachedTabs();
            if (isset($tabs[$activeTab])) {
                $baseQuery = $tabs[$activeTab]->modifyQuery($baseQuery);
            }
        }

        $counts = (clone $baseQuery)
            ->selectRaw('count(*) as all_count, sum(case when enabled then 1 else 0 end) as enabled_count, sum(case when not enabled then 1 else 0 end) as disabled_count, sum(case when is_custom then 1 else 0 end) as custom_count')
            ->first();

        return [
            'all' => (int) ($counts->all_count ?? 0),
            'enabled' => (int) ($counts->enabled_count ?? 0),
            'disabled' => (int) ($counts->disabled_count ?? 0),
            'failover' => (clone $baseQuery)->whereHas('failovers')->count(),
            'custom' => (int) ($counts->custom_count ?? 0),
        ];
    }

    public function updatedActiveTab(): void
    {
        parent::updatedActiveTab();
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * @deprecated Override the `table()` method to configure the table.
     */
    protected function getTableQuery(): ?Builder
    {
        return static::getResource()::getEloquentQuery()
            ->where('user_id', auth()->id())
            ->where('is_vod', false);
    }
}
