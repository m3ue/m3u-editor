<?php

namespace App\Filament\Resources\Channels;

use App\Facades\LogoFacade;
use App\Facades\SortFacade;
use App\Filament\Actions\AssetPickerAction;
use App\Filament\Actions\BulkModalActionGroup;
use App\Filament\Concerns\HasCopilotSupport;
use App\Filament\Resources\ChannelResource\Pages;
use App\Filament\Resources\Channels\Pages\ListChannels;
use App\Filament\Resources\EpgMaps\EpgMapResource;
use App\Filament\Tables\ProbeStatusColumn;
use App\Jobs\ChannelFindAndReplace;
use App\Jobs\ChannelFindAndReplaceReset;
use App\Jobs\MapPlaylistChannelsToEpg;
use App\Jobs\ProbeChannelStreams;
use App\Jobs\RescoreChannelFailovers;
use App\Jobs\SyncPlexDvrJob;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\StreamProfile;
use App\Services\Channels\SmartChannelCreator;
use App\Services\DateFormatService;
use App\Services\EpgCacheService;
use App\Services\LogoCacheService;
use App\Services\PlaylistService;
use App\Traits\HasUserFiltering;
use EslamRedaDiv\FilamentCopilot\Contracts\CopilotResource;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChannelResource extends Resource implements CopilotResource
{
    use HasCopilotSupport;
    use HasUserFiltering;

    protected static ?string $model = Channel::class;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'title_custom', 'name', 'name_custom', 'url', 'stream_id', 'stream_id_custom'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        $query = parent::getGlobalSearchEloquentQuery()
            ->where('is_vod', false);

        // Filter by user_id for non-admin users
        if (auth()->check() && ! auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->where('is_vod', false);

        // Filter by user_id for non-admin users
        if (auth()->check() && ! auth()->user()->isAdmin()) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Live Channels');
    }

    public static function getNavigationLabel(): string
    {
        return __('Channels');
    }

    public static function getModelLabel(): string
    {
        return __('Channel');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Live Channels');
    }

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components(self::getForm());
    }

    public static function table(Table $table): Table
    {
        return self::setupTable($table);
    }

    public static function setupTable(Table $table, $relationId = null): Table
    {
        return $table->persistFiltersInSession()
            ->persistSortInSession()
            ->filtersTriggerAction(function ($action) {
                return $action->button()->label(__('Filters'));
            })
            ->modifyQueryUsing(function (Builder $query) {
                $query->with([
                    'epgChannel' => fn ($q) => $q->select('id', 'name', 'icon', 'icon_custom'),
                    'playlist' => fn ($q) => $q->select('id', 'name', 'uuid', 'auto_sort', 'enable_proxy', 'user_id')
                        ->with(['user' => fn ($uq) => $uq->select('id', 'is_admin', 'permissions')]),
                    'customPlaylist' => fn ($q) => $q->select('id', 'name', 'uuid', 'enable_proxy', 'user_id')
                        ->with(['user' => fn ($uq) => $uq->select('id', 'is_admin', 'permissions')]),
                    'streamProfile' => fn ($q) => $q->select('id', 'name'),
                ])
                    ->withCount(['failovers'])
                    ->where('is_vod', false);
            })
            ->deferLoading()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('sort')
            ->columns(self::getTableColumns(showGroup: ! $relationId, showPlaylist: ! $relationId))
            ->filters(self::getTableFilters(showPlaylist: ! $relationId))
            ->recordActions(self::getTableActions(), position: RecordActionsPosition::BeforeCells)
            ->toolbarActions(self::getTableBulkActions());
    }

    public static function getTableColumns($showGroup = true, $showPlaylist = true): array
    {
        return [
            ImageColumn::make('logo')
                ->label(__('Logo'))
                ->checkFileExistence(false)
                ->size('inherit', 'inherit')
                ->extraImgAttributes(fn ($record): array => [
                    'style' => 'height:2.5rem; width:auto; border-radius:4px;', // Live channel style
                ])
                ->getStateUsing(fn ($record) => LogoFacade::getChannelLogoUrl($record))
                ->toggleable(),
            TextColumn::make('info')
                ->label(__('Info'))
                ->wrap()
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    return $query
                        ->orderBy('title_custom', $direction)
                        ->orderBy('title', $direction);
                })
                ->getStateUsing(function ($record) {
                    $info = $record->info;
                    $title = $record->title_custom ?: $record->title;
                    $html = "<span class='fi-ta-text-item-label whitespace-normal text-sm leading-6 text-gray-950 dark:text-white'>{$title}</span>";
                    if (is_array($info)) {
                        $description = Str::limit($info['description'] ?? $info['plot'] ?? '', 200);
                        $html .= "<p class='text-sm text-gray-500 dark:text-gray-400 whitespace-normal mt-2'>{$description}</p>";
                    }

                    return new HtmlString($html);
                })
                ->extraAttributes(['style' => 'min-width: 350px;'])
                ->toggleable(),
            TextInputColumn::make('sort')
                ->label(__('Sort Order'))
                ->rules(['min:0'])
                ->type('number')
                ->placeholder(__('Sort Order'))
                ->sortable()
                ->tooltip(fn ($record) => ! $record->is_custom && $record->playlist?->auto_sort ? 'Playlist auto-sort enabled; any changes will be overwritten on next sync' : 'Channel sort order')
                ->toggleable(),
            ToggleColumn::make('enabled')
                ->toggleable()
                ->sortable()
                ->afterStateUpdated(function (): void {
                    dispatch(new SyncPlexDvrJob(trigger: 'channel_toggle'));
                }),
            ToggleColumn::make('can_merge')
                ->label(__('Merge Enabled'))
                ->toggleable()
                ->sortable(),
            TextColumn::make('failovers_count')
                ->label(__('Failovers'))
                ->counts('failovers')
                ->badge()
                ->toggleable()
                ->sortable(),
            IconColumn::make('is_smart_channel')
                ->label(__('Smart'))
                ->icon(fn ($record): ?string => $record?->is_smart_channel ? 'heroicon-o-sparkles' : null)
                ->color('info')
                ->tooltip(fn ($record): ?string => $record?->is_smart_channel ? __('Smart channel — streams the highest-ranked failover automatically') : null)
                ->sortable()
                ->toggleable(),
            TextInputColumn::make('stream_id_custom')
                ->label(__('ID'))
                ->rules(['min:0', 'max:255'])
                ->placeholder(fn ($record) => $record->stream_id)
                ->searchable()
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    return $query
                        ->orderBy('stream_id_custom', $direction)
                        ->orderBy('stream_id', $direction);
                })
                ->toggleable(),
            TextInputColumn::make('title_custom')
                ->label(__('Title'))
                ->rules(['min:0', 'max:255'])
                ->placeholder(fn ($record) => $record->title)
                ->searchable()
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    return $query
                        ->orderBy('title_custom', $direction)
                        ->orderBy('title', $direction);
                })
                ->toggleable(),
            TextInputColumn::make('name_custom')
                ->label(__('Name'))
                ->rules(['min:0', 'max:255'])
                ->placeholder(fn ($record) => $record->name)
                ->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->orWhereRaw('LOWER(channels.name_custom) LIKE ?', ['%'.strtolower($search).'%']);
                })
                ->sortable(query: function (Builder $query, string $direction): Builder {
                    return $query
                        ->orderBy('name_custom', $direction)
                        ->orderBy('name', $direction);
                })
                ->toggleable(),
            TextInputColumn::make('channel')
                ->rules(['numeric', 'min:0'])
                ->type('number')
                ->placeholder(__('Channel No.'))
                ->toggleable()
                ->sortable(),
            TextInputColumn::make('url_custom')
                ->label(__('URL'))
                ->rules(['url'])
                ->type('url')
                ->placeholder(fn ($record) => $record->url)
                ->searchable()
                ->toggleable(),
            TextInputColumn::make('shift')
                ->label(__('Time Shift'))
                ->rules(['numeric', 'min:0'])
                ->type('number')
                ->placeholder(__('Time Shift'))
                ->toggleable()
                ->sortable(),
            TextColumn::make('group')
                ->hidden(fn () => ! $showGroup)
                ->badge()
                ->toggleable()
                ->searchable(query: function ($query, string $search): Builder {
                    $connection = $query->getConnection();
                    $driver = $connection->getDriverName();

                    switch ($driver) {
                        case 'pgsql':
                            return $query->orWhereRaw('LOWER("group"::text) LIKE ?', ["%{$search}%"]);
                        case 'mysql':
                            return $query->orWhereRaw('LOWER(`group`) LIKE ?', ["%{$search}%"]);
                        case 'sqlite':
                            return $query->orWhereRaw('LOWER("group") LIKE ?', ["%{$search}%"]);
                        default:
                            // Fallback using Laravel's database abstraction
                            return $query->orWhere(DB::raw('LOWER(group)'), 'LIKE', "%{$search}%");
                    }
                })
                ->sortable(),
            ToggleColumn::make('probe_enabled')
                ->label(__('Probe Enabled'))
                ->toggleable()
                ->sortable(),
            IconColumn::make('is_proxy_enabled')
                ->label(__('Proxy'))
                ->getStateUsing(fn ($record): bool => $record->isProxyEnabled())
                ->boolean()
                ->trueIcon('heroicon-o-shield-check')
                ->falseIcon('heroicon-o-shield-exclamation')
                ->trueColor('success')
                ->falseColor('gray')
                ->tooltip(fn ($record): string => $record->isProxyEnabled() ? 'Proxy enabled' : 'Proxy disabled')
                ->toggleable(isToggledHiddenByDefault: false)
                ->sortable(false)
                ->hidden(fn () => ! auth()->user()->canUseProxy()),
            ProbeStatusColumn::make(),
            ToggleColumn::make('epg_map_enabled')
                ->label(__('Mapping Enabled'))
                ->sortable(),
            TextColumn::make('epgChannel.name')
                ->label(__('EPG Channel'))
                ->toggleable()
                ->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->orWhereHas('epgChannel', function (Builder $query) use ($search) {
                        $query->whereRaw('LOWER(epg_channels.name) LIKE ?', ['%'.strtolower($search).'%']);
                    });
                })
                ->limit(40)
                ->sortable(),
            TextInputColumn::make('tvg_shift')
                ->label(__('EPG Shift'))
                ->rules(['numeric'])
                ->placeholder(__('EPG Shift'))
                ->toggleable()
                ->sortable(),
            SelectColumn::make('logo_type')
                ->label(__('Preferred Icon'))
                ->options([
                    'channel' => 'Channel',
                    'epg' => 'EPG',
                ])
                ->sortable()
                ->toggleable(),
            TextColumn::make('lang')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true)
                ->sortable(),
            TextColumn::make('country')
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true)
                ->sortable(),
            TextColumn::make('playlist.name')
                ->hidden(fn () => ! $showPlaylist)
                ->numeric()
                ->toggleable()
                ->sortable(),

            TextColumn::make('stream_id')
                ->label(__('Default ID'))
                ->sortable()
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('title')
                ->label(__('Default Title'))
                ->sortable()
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('name')
                ->label(__('Default Name'))
                ->sortable()
                ->searchable(query: function (Builder $query, string $search): Builder {
                    return $query->orWhereRaw('LOWER(channels.name) LIKE ?', ['%'.strtolower($search).'%']);
                })
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('url')
                ->label(__('Default URL'))
                ->sortable()
                ->searchable(query: function (Builder $query, string $search): Builder {
                    $urlExpr = DB::getDriverName() === 'sqlite' ? 'channels.url' : 'channels.url::text';

                    return $query->orWhereRaw("LOWER({$urlExpr}) LIKE ?", ['%'.strtolower($search).'%']);
                })
                ->toggleable(isToggledHiddenByDefault: true),

            TextColumn::make('created_at')
                ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('updated_at')
                ->formatStateUsing(fn ($state) => app(DateFormatService::class)->format($state))
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function getTableFilters($showPlaylist = true): array
    {
        return [
            Filter::make('mapped')
                ->label(__('EPG is mapped'))
                ->toggle()
                ->query(function ($query) {
                    return $query->where('epg_channel_id', '!=', null);
                }),
            Filter::make('un_mapped')
                ->label(__('EPG is not mapped'))
                ->toggle()
                ->query(function ($query) {
                    return $query->where('epg_channel_id', '=', null);
                }),
            Filter::make('probed')
                ->label(__('Probed'))
                ->toggle()
                ->query(function ($query) {
                    return $query->whereNotNull('stream_stats_probed_at')
                        ->whereNotNull('stream_stats')->whereRaw("CAST(stream_stats AS TEXT) != '[]'");
                }),
            Filter::make('probe_failed')
                ->label(__('Probe failed'))
                ->toggle()
                ->query(function ($query) {
                    return $query->whereNotNull('stream_stats_probed_at')
                        ->where(fn ($q) => $q->whereNull('stream_stats')->orWhereRaw("CAST(stream_stats AS TEXT) = '[]'"));
                }),
            Filter::make('not_probed')
                ->label(__('Not probed'))
                ->toggle()
                ->query(function ($query) {
                    return $query->whereNull('stream_stats_probed_at');
                }),
        ];
    }

    public static function getTableActions(): array
    {
        return [
            ActionGroup::make([
                EditAction::make('edit_custom')
                    ->slideOver()
                    ->schema(fn (EditAction $action): array => [
                        Grid::make()
                            ->schema(self::getForm(edit: true))
                            ->columns(2),
                    ])
                    // Refresh table after edit to remove records that no longer match active filters
                    ->after(fn ($livewire) => $livewire->dispatch('$refresh')),
                DeleteAction::make()->hidden(fn (Model $record) => ! $record->is_custom),
            ])->button()->hiddenLabel()->size('sm')->hidden(fn (Model $record) => ! $record->is_custom),
            EditAction::make('edit')
                ->slideOver()
                ->schema(fn (EditAction $action): array => [
                    Grid::make()
                        ->schema(self::getForm(edit: true))
                        ->columns(2),
                ])
                // Refresh table after edit to remove records that no longer match active filters
                ->after(fn ($livewire) => $livewire->dispatch('$refresh'))
                ->button()
                ->hiddenLabel()
                ->disabled(fn (Model $record) => $record->is_custom)
                ->hidden(fn (Model $record) => $record->is_custom),
            Action::make('play')
                ->tooltip(__('Play Channel'))
                ->action(function ($record, $livewire) {
                    $livewire->dispatch('openFloatingStream', $record->getFloatingPlayerAttributes());
                })
                ->icon('heroicon-s-play-circle')
                ->button()
                ->hiddenLabel()
                ->size('sm'),
            ViewAction::make()
                ->button()
                ->icon('heroicon-s-information-circle')
                ->hiddenLabel()
                ->slideOver(),
        ];
    }

    public static function getTableBulkActions($addToCustom = true, bool $includeRecount = true): array
    {
        return [
            BulkModalActionGroup::make('Bulk channel actions')
                ->modalHeading(__('Bulk channel actions'))
                ->gridColumns(2)
                ->schema(self::getBulkActionSchema($addToCustom, $includeRecount)),
        ];
    }

    /**
     * Build the sectioned schema for the bulk actions modal.
     */
    private static function getBulkActionSchema(bool $addToCustom, bool $includeRecount): array
    {
        return [
            // -- Playlist & Groups --
            BulkModalActionGroup::section('Playlist & Groups', [
                PlaylistService::getAddToPlaylistBulkAction('add', 'channel')
                    ->hidden(fn () => ! $addToCustom),
                BulkAction::make('move')
                    ->label(__('Move to Group'))
                    ->schema([
                        Select::make('playlist')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('group', null);
                            })
                            ->label(__('Playlist'))
                            ->helperText(__('Select a playlist - only channels in the selected playlist will be moved. Any channels selected from another playlist will be ignored.'))
                            ->options(Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                            ->searchable(),
                        Select::make('group')
                            ->required()
                            ->live()
                            ->label(__('Group'))
                            ->helperText(fn (Get $get) => $get('playlist') === null ? 'Select a playlist first...' : 'Select the group you would like to move the items to.')
                            ->options(fn (Get $get) => Group::where([
                                'type' => 'live',
                                'user_id' => auth()->id(),
                                'playlist_id' => $get('playlist'),
                            ])->get(['name', 'id'])->pluck('name', 'id'))
                            ->searchable()
                            ->disabled(fn (Get $get) => $get('playlist') === null),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $filtered = $records->where('playlist_id', $data['playlist']);
                        $group = Group::findOrFail($data['group']);
                        foreach ($filtered as $record) {
                            $record->update([
                                'group' => $group->name,
                                'group_id' => $group->id,
                            ]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Channels moved to group'))
                            ->body(__('The selected channels have been moved to the chosen group.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-right-left')
                    ->modalIcon('heroicon-o-arrows-right-left')
                    ->modalDescription(__('Move the selected channel(s) to the chosen group.'))
                    ->modalSubmitActionLabel(__('Move now')),
                ...($includeRecount ? [
                    BulkAction::make('recount')
                        ->label(__('Recount Channels'))
                        ->icon('heroicon-o-hashtag')
                        ->schema([
                            TextInput::make('start')
                                ->label(__('Start Number'))
                                ->numeric()
                                ->default(1)
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $start = (int) $data['start'];
                            SortFacade::bulkRecountChannels($records, $start);
                            dispatch(new SyncPlexDvrJob(trigger: 'channel_recount'));
                        })
                        ->after(function ($livewire) {
                            Notification::make()
                                ->success()
                                ->title(__('Channels Recounted'))
                                ->body(__('The selected channels have been recounted.'))
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-hashtag')
                        ->modalDescription(__('Recount the selected channels sequentially? Channel numbers will be assigned based on the current sort order.')),
                ] : []),
            ]),

            // -- Logo --
            BulkModalActionGroup::section('Logo', [
                BulkAction::make('preferred_logo')
                    ->label(__('Update preferred icon'))
                    ->schema([
                        Select::make('logo_type')
                            ->label(__('Preferred Icon'))
                            ->helperText(__('Prefer logo from channel or EPG.'))
                            ->options([
                                'channel' => 'Channel',
                                'epg' => 'EPG',
                            ])
                            ->searchable(),

                    ])
                    ->action(function (Collection $records, array $data): void {
                        Channel::whereIn('id', $records->pluck('id')->toArray())
                            ->update([
                                'logo_type' => $data['logo_type'],
                            ]);
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Preferred icon updated'))
                            ->body(__('The preferred icon has been updated.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-photo')
                    ->modalIcon('heroicon-o-photo')
                    ->modalDescription(__('Update the preferred icon for the selected channel(s).'))
                    ->modalSubmitActionLabel(__('Update now')),
                BulkAction::make('set_logo_override_url')
                    ->label(__('Set logo override URL'))
                    ->schema([
                        TextInput::make('logo')
                            ->label(__('Logo override URL'))
                            ->url()
                            ->nullable()
                            ->helperText(__('Leave empty to remove the custom logo and use provider/EPG logo.'))
                            ->suffixActions([
                                AssetPickerAction::upload('logo'),
                                AssetPickerAction::browse('logo'),
                            ]),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        Channel::whereIn('id', $records->pluck('id')->toArray())
                            ->update([
                                'logo' => empty($data['logo']) ? null : $data['logo'],
                            ]);
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Logo override updated'))
                            ->body(__('The logo override URL has been updated for the selected channels.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-link')
                    ->modalIcon('heroicon-o-link')
                    ->modalDescription(__('Apply a single logo override URL to all selected channels. Leave empty to remove overrides.'))
                    ->modalSubmitActionLabel(__('Apply URL')),
                BulkAction::make('refresh_logo_cache')
                    ->label(__('Refresh logo cache (selected)'))
                    ->action(function (Collection $records): void {
                        $urls = [];

                        foreach ($records as $record) {
                            $urls[] = $record->logo;
                            $urls[] = $record->logo_internal;
                            $urls[] = $record->epgChannel?->icon_custom;
                            $urls[] = $record->epgChannel?->icon;
                        }

                        $cleared = LogoCacheService::clearByUrls($urls);

                        Notification::make()
                            ->success()
                            ->title(__('Selected logo cache refreshed'))
                            ->body("Removed {$cleared} cache file(s) for selected channels.")
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-path')
                    ->modalIcon('heroicon-o-arrow-path')
                    ->modalDescription(__('Clear cached logos for selected channels so they are fetched again on the next request.'))
                    ->modalSubmitActionLabel(__('Refresh selected cache')),
            ]),

            // -- EPG --
            BulkModalActionGroup::section('EPG', [
                BulkAction::make('map')
                    ->label(__('Map EPG to selected'))
                    ->schema(EpgMapResource::getForm(showPlaylist: false, showEpg: true))
                    ->action(function (Collection $records, array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new MapPlaylistChannelsToEpg(
                                epg: (int) $data['epg_id'],
                                channels: $records->pluck('id')->toArray(),
                                force: $data['override'],
                                settings: $data['settings'] ?? [],
                            ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('EPG to Channel mapping'))
                            ->body(__('Mapping started, you will be notified when the process is complete.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-link')
                    ->modalIcon('heroicon-o-link')
                    ->modalWidth(Width::FourExtraLarge)
                    ->modalDescription(__('Map the selected EPG to the selected channel(s).'))
                    ->modalSubmitActionLabel(__('Map now')),
                BulkAction::make('unmap')
                    ->label(__('Undo EPG Map'))
                    ->action(function (Collection $records): void {
                        // Clear the EPG mapping
                        Channel::whereIn('id', $records->pluck('id'))
                            ->update(['epg_channel_id' => null]);

                        // Invalidate cached EPG XML for all playlists containing these channels
                        // (regular, custom, and merged) so Xtream API clients receive updated
                        // XMLTV data immediately instead of waiting for the cache TTL to expire
                        $records->loadMissing(['playlist.mergedPlaylists', 'customPlaylists']);

                        $affectedPlaylists = collect();
                        foreach ($records as $channel) {
                            if ($channel->playlist) {
                                $affectedPlaylists->push($channel->playlist);
                                foreach ($channel->playlist->mergedPlaylists as $merged) {
                                    $affectedPlaylists->push($merged);
                                }
                            }
                            foreach ($channel->customPlaylists as $custom) {
                                $affectedPlaylists->push($custom);
                            }
                        }
                        $affectedPlaylists->unique(fn ($p) => $p->getTable().'-'.$p->id)
                            ->each(fn ($p) => EpgCacheService::clearPlaylistEpgCacheFile($p));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('EPG Channel mapping removed'))
                            ->body(__('Channel mapping removed for the selected channels.'))
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->modalIcon('heroicon-o-arrow-uturn-left')
                    ->modalDescription(__('Clear EPG mappings for the selected channels.'))
                    ->modalSubmitActionLabel(__('Reset now')),
                BulkAction::make('enable-epg-mapping')
                    ->label(__('Enable EPG mapping'))
                    ->action(function (Collection $records, array $data): void {
                        $records->each(fn ($channel) => $channel->update([
                            'epg_map_enabled' => true,
                        ]));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('EPG map re-enabled for selected channels'))
                            ->body(__('The EPG map has been re-enabled for the selected channels.'))
                            ->send();
                    })
                    ->hidden(fn () => ! $addToCustom)
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-calendar')
                    ->modalIcon('heroicon-o-calendar')
                    ->modalDescription(__('Allow mapping EPG to selected channels when running EPG mapping jobs.'))
                    ->modalSubmitActionLabel(__('Enable now')),
                BulkAction::make('disable-epg-mapping')
                    ->label(__('Disable EPG mapping'))
                    ->color('warning')
                    ->action(function (Collection $records, array $data): void {
                        $records->each(fn ($channel) => $channel->update([
                            'epg_map_enabled' => false,
                        ]));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('EPG map disabled for selected channels'))
                            ->body(__('The EPG map has been disabled for the selected channels.'))
                            ->send();
                    })
                    ->hidden(fn () => ! $addToCustom)
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-calendar')
                    ->modalIcon('heroicon-o-calendar')
                    ->modalDescription(__('Don\\\'t map EPG to selected channels when running EPG mapping jobs.'))
                    ->modalSubmitActionLabel(__('Disable now')),
                BulkAction::make('set-timeshift')
                    ->label(__('Set Timeshift'))
                    ->schema([
                        TextInput::make('shift')
                            ->label(__('Timeshift value'))
                            ->helperText(__('Set the timeshift (in hours) for the selected channels. Use 0 to disable catch-up.'))
                            ->type('number')
                            ->rules(['integer', 'min:0'])
                            ->default(0)
                            ->required(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $value = (int) $data['shift'];
                        foreach ($records->chunk(100) as $chunk) {
                            Channel::whereIn('id', $chunk->pluck('id'))->update(['shift' => $value]);
                        }
                    })->after(function (array $data) {
                        Notification::make()
                            ->success()
                            ->title(__('Timeshift updated'))
                            ->body("Timeshift set to {$data['shift']} for the selected channels.")
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-clock')
                    ->modalIcon('heroicon-o-clock')
                    ->modalDescription(__('Set the timeshift value for the selected channels. Use 0 to disable catch-up.'))
                    ->modalSubmitActionLabel(__('Set timeshift')),
                BulkAction::make('set-epg-shift')
                    ->label(__('Set EPG shift'))
                    ->schema([
                        TextInput::make('tvg_shift')
                            ->label(__('EPG Shift value'))
                            ->helperText(__('Shift the EPG time for the selected channels by this many hours. Use values like -2, -1, 0, 1, 2, etc. Use 0 to reset.'))
                            ->type('number')
                            ->rules(['required', 'numeric'])
                            ->default(0)
                            ->required(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $value = (string) $data['tvg_shift'];
                        foreach ($records->chunk(100) as $chunk) {
                            Channel::whereIn('id', $chunk->pluck('id'))->update(['tvg_shift' => $value]);
                        }
                    })->after(function (array $data) {
                        Notification::make()
                            ->success()
                            ->title(__('EPG Shift updated'))
                            ->body("EPG Shift set to {$data['tvg_shift']} for the selected channels.")
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-clock')
                    ->modalIcon('heroicon-o-clock')
                    ->modalDescription(__('Set the EPG time shift (tvg-shift) for the selected channels. This shifts the EPG program schedule by the specified number of hours.'))
                    ->modalSubmitActionLabel(__('Set EPG shift')),
            ]),

            // -- Find & Replace --
            BulkModalActionGroup::section('Find & Replace', [
                BulkAction::make('find-replace')
                    ->label(__('Find & Replace'))
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
                                ->label(__('Load saved pattern'))
                                ->searchable()
                                ->placeholder(__('Select a saved pattern...'))
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
                            Toggle::make('use_regex')
                                ->label(__('Use Regex'))
                                ->live()
                                ->helperText(__('Use regex patterns to find and replace. If disabled, will use direct string comparison.'))
                                ->default(true),
                            Select::make('column')
                                ->label(__('Column to modify'))
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
                                ->label(__('Replace with (optional)'))
                                ->placeholder(__('Leave empty to remove')),
                        ];
                    })
                    ->action(function (Collection $records, array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ChannelFindAndReplace(
                                user_id: auth()->id(), // The ID of the user who owns the content
                                use_regex: $data['use_regex'] ?? true,
                                column: $data['column'] ?? 'title',
                                find_replace: $data['find_replace'] ?? null,
                                replace_with: $data['replace_with'] ?? '',
                                channels: $records
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
                    ->modalDescription(__('Select what you would like to find and replace in the selected channels.'))
                    ->modalSubmitActionLabel(__('Replace now')),
                BulkAction::make('find-replace-reset')
                    ->label(__('Undo Find & Replace'))
                    ->schema([
                        Select::make('column')
                            ->label(__('Column to reset'))
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
                    ->action(function (Collection $records, array $data): void {
                        app('Illuminate\Contracts\Bus\Dispatcher')
                            ->dispatch(new ChannelFindAndReplaceReset(
                                user_id: auth()->id(), // The ID of the user who owns the content
                                column: $data['column'] ?? 'title',
                                channels: $records
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
                    ->modalDescription(__('Reset Find & Replace results back to playlist defaults for the selected channels. This will remove any custom values set in the selected column.'))
                    ->modalSubmitActionLabel(__('Reset now')),
            ]),

            // -- Streaming --
            BulkModalActionGroup::section('Streaming', [
                BulkAction::make('set-stream-profile')
                    ->label(__('Set Stream Profile'))
                    ->schema([
                        Select::make('stream_profile_id')
                            ->label(__('Stream Profile'))
                            ->options(fn () => StreamProfile::where('user_id', auth()->id())->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->placeholder(__('None (clear profile)'))
                            ->helperText(__('The stored profile only takes effect when the channel (or its playlist) has proxy enabled.')),
                        Toggle::make('overwrite_existing')
                            ->label(__('Overwrite existing assignments'))
                            ->helperText(__('When off, only channels without a stream profile will be updated. When on, all selected channels will be overwritten (including clearing back to none).'))
                            ->default(false),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $profileId = ! empty($data['stream_profile_id']) ? (int) $data['stream_profile_id'] : null;
                        $overwrite = (bool) ($data['overwrite_existing'] ?? false);
                        $updated = 0;

                        foreach ($records->chunk(100) as $chunk) {
                            $query = Channel::whereIn('id', $chunk->pluck('id'));
                            if (! $overwrite) {
                                $query->whereNull('stream_profile_id');
                            }
                            $updated += $query->update(['stream_profile_id' => $profileId]);
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
                    ->modalDescription(__('Assign (or clear) a stream profile for the selected channels.'))
                    ->modalSubmitActionLabel(__('Apply')),
                BulkAction::make('enable-merge')
                    ->label(__('Enable Merge'))
                    ->action(function (Collection $records, array $data): void {
                        $records->each(fn ($channel) => $channel->update([
                            'can_merge' => true,
                        ]));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Merge re-enabled for selected channels'))
                            ->body(__('The merge has been re-enabled for the selected channels. They can now be merged during "Merge Same ID" jobs.'))
                            ->send();
                    })
                    ->hidden(fn () => ! $addToCustom)
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-pointing-in')
                    ->modalIcon('heroicon-o-arrows-pointing-in')
                    ->modalDescription(__('Allow merging for selected channels when running "Merge Same ID" jobs.'))
                    ->modalSubmitActionLabel(__('Enable now')),
                BulkAction::make('disable-merge')
                    ->label(__('Disable Merge'))
                    ->color('warning')
                    ->action(function (Collection $records, array $data): void {
                        $records->each(fn ($channel) => $channel->update([
                            'can_merge' => false,
                        ]));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Merge disabled for selected channels'))
                            ->body(__('The merge has been disabled for the selected channels. They will not be merged during "Merge Same ID" jobs.'))
                            ->send();
                    })
                    ->hidden(fn () => ! $addToCustom)
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrows-pointing-in')
                    ->modalIcon('heroicon-o-arrows-pointing-in')
                    ->modalDescription(__('Don\\\'t allow merging for selected channels when running "Merge Same ID" jobs.'))
                    ->modalSubmitActionLabel(__('Disable now')),
                BulkAction::make('failover')
                    ->label(__('Add as failover'))
                    ->schema(function (Collection $records) {
                        $existingFailoverIds = $records->pluck('id')->toArray();
                        $initialMasterOptions = [];
                        foreach ($records as $record) {
                            $displayTitle = $record->title_custom ?: $record->title;
                            $playlistName = $record->getEffectivePlaylist()->name ?? 'Unknown';
                            $initialMasterOptions[$record->id] = "{$displayTitle} [{$playlistName}]";
                        }

                        return [
                            ToggleButtons::make('master_source')
                                ->label(__('Choose master from?'))
                                ->options([
                                    'selected' => 'Selected Channels',
                                    'searched' => 'Channel Search',
                                ])
                                ->icons([
                                    'selected' => 'heroicon-o-check',
                                    'searched' => 'heroicon-o-magnifying-glass',
                                ])
                                ->default('selected')
                                ->live()
                                ->grouped(),
                            Select::make('selected_master_id')
                                ->label(__('Select master channel'))
                                ->helperText(__('From the selected channels'))
                                ->options($initialMasterOptions)
                                ->required()
                                ->hidden(fn (Get $get) => $get('master_source') !== 'selected')
                                ->searchable(),
                            Select::make('master_channel_id')
                                ->label(__('Search for master channel'))
                                ->searchable()
                                ->required()
                                ->hidden(fn (Get $get) => $get('master_source') !== 'searched')
                                ->getSearchResultsUsing(function (string $search) use ($existingFailoverIds) {
                                    $searchLower = strtolower($search);
                                    $channels = auth()->user()->channels()
                                        ->withoutEagerLoads()
                                        ->with('playlist')
                                        ->whereNotIn('id', $existingFailoverIds)
                                        ->where(function ($query) use ($searchLower) {
                                            $query->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(title_custom) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(name_custom) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(stream_id) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(stream_id_custom) LIKE ?', ["%{$searchLower}%"]);
                                        })
                                        ->limit(50) // Keep a reasonable limit
                                        ->get();

                                    // Create options array
                                    $options = [];
                                    foreach ($channels as $channel) {
                                        $displayTitle = $channel->title_custom ?: $channel->title;
                                        $playlistName = $channel->getEffectivePlaylist()->name ?? 'Unknown';
                                        $options[$channel->id] = "{$displayTitle} [{$playlistName}]";
                                    }

                                    return $options;
                                })
                                ->helperText(__('To use as the master for the selected channel.'))
                                ->required(),
                        ];
                    })
                    ->action(function (Collection $records, array $data): void {
                        // Filter out the master channel from the records to be added as failovers
                        $masterRecordId = $data['master_source'] === 'selected'
                            ? $data['selected_master_id']
                            : $data['master_channel_id'];
                        $failoverRecords = $records->filter(function ($record) use ($masterRecordId) {
                            return (int) $record->id !== (int) $masterRecordId;
                        });

                        $smartChannelCount = $failoverRecords->filter(fn ($r) => (bool) $r->is_smart_channel)->count();
                        $failoverRecords = $failoverRecords->filter(fn ($r) => ! $r->is_smart_channel);

                        foreach ($failoverRecords as $record) {
                            ChannelFailover::updateOrCreate([
                                'channel_id' => $masterRecordId,
                                'channel_failover_id' => $record->id,
                            ]);
                        }

                        if ($smartChannelCount > 0) {
                            Notification::make()
                                ->warning()
                                ->title(__('Some channels were skipped'))
                                ->body(__(':count smart channel(s) were skipped — smart channels can\'t be used as failovers themselves.', ['count' => $smartChannelCount]))
                                ->send();
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Channels as failover'))
                            ->body(__('The selected channels have been added as failovers.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->modalIcon('heroicon-o-arrow-path-rounded-square')
                    ->modalDescription(__('Add the selected channel(s) to the chosen channel as failover sources.'))
                    ->modalSubmitActionLabel(__('Add failovers now')),
                BulkAction::make('make_smart_channel')
                    ->label(__('Make smart channel'))
                    ->schema(function (Collection $records) {
                        $playlists = $records->pluck('playlist_id')->unique();
                        $playlistForScoring = $playlists->count() === 1
                            ? Playlist::find($playlists->first())
                            : null;

                        $creator = SmartChannelCreator::fromPlaylist($playlistForScoring);
                        $ranking = $creator->rank($records);

                        $rows = $ranking->map(function ($row, int $index) {
                            $channel = $row['channel'];
                            $playlistName = $channel->getEffectivePlaylist()->name ?? 'Unknown';
                            $displayTitle = e($channel->title_custom ?: $channel->title ?: $channel->name);
                            $rankBadge = '<span class="font-mono text-xs text-gray-500 dark:text-gray-400">#'.($index + 1).'</span>';
                            $titleCell = "<div class=\"font-medium\">{$displayTitle}</div><div class=\"text-xs text-gray-500 dark:text-gray-400\">".e($playlistName).'</div>';
                            $totalScore = '<span class="font-mono text-sm">'.number_format($row['score']).'</span>';

                            $breakdownParts = [];
                            foreach ($row['breakdown'] as $attribute => $score) {
                                $label = e(str_replace('_', ' ', $attribute));
                                $breakdownParts[] = "<span class=\"inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-xs mr-1 mb-1\"><span class=\"text-gray-500 dark:text-gray-400\">{$label}</span><span class=\"font-mono\">{$score}</span></span>";
                            }
                            $breakdownCell = '<div class="flex flex-wrap">'.implode('', $breakdownParts).'</div>';

                            return "<tr class=\"border-t border-gray-200 dark:border-gray-700\"><td class=\"py-2 pr-2 align-top\">{$rankBadge}</td><td class=\"py-2 pr-2 align-top\">{$titleCell}</td><td class=\"py-2 pr-2 align-top\">{$totalScore}</td><td class=\"py-2 align-top\">{$breakdownCell}</td></tr>";
                        })->implode('');

                        $headerRow = '<thead><tr class="text-left text-xs uppercase text-gray-500 dark:text-gray-400"><th class="py-1 pr-2">Rank</th><th class="py-1 pr-2">Channel</th><th class="py-1 pr-2">Score</th><th class="py-1">Breakdown (per attribute, 0-100)</th></tr></thead>';
                        $tableHtml = "<table class=\"w-full text-sm\">{$headerRow}<tbody>{$rows}</tbody></table>";

                        return [
                            Placeholder::make('ranking_preview')
                                ->label(__('Ranked sources'))
                                ->content(new HtmlString($tableHtml))
                                ->columnSpanFull(),
                            TextInput::make('title')
                                ->label(__('Virtual channel title'))
                                ->helperText(__('Leave empty to copy the highest-scoring source\'s title.'))
                                ->maxLength(255),
                            Toggle::make('disable_sources')
                                ->label(__('Disable source channels'))
                                ->helperText(__('When enabled, the selected source channels will be disabled after being attached as failovers. They\'ll only be reachable via the new smart channel.'))
                                ->default(false)
                                ->inline(false),
                        ];
                    })
                    ->action(function (Collection $records, array $data): void {
                        if ($records->isEmpty()) {
                            return;
                        }

                        $playlists = $records->pluck('playlist_id')->unique();
                        if ($playlists->count() > 1) {
                            Notification::make()
                                ->warning()
                                ->title(__('Mixed playlists not supported'))
                                ->body(__('Smart channels must be created from sources within a single playlist. Narrow your selection and try again.'))
                                ->send();

                            return;
                        }

                        if ($records->contains(fn (Channel $channel) => (bool) $channel->is_smart_channel)) {
                            Notification::make()
                                ->warning()
                                ->title(__('Smart channels cannot be sources'))
                                ->body(__('Your selection includes one or more existing smart channels — pick raw provider channels instead, or remove the smart-channel flag first.'))
                                ->send();

                            return;
                        }

                        $playlistForScoring = Playlist::find($playlists->first());

                        try {
                            SmartChannelCreator::fromPlaylist($playlistForScoring)->create(
                                channels: $records,
                                title: $data['title'] ?? null,
                                disableSources: (bool) ($data['disable_sources'] ?? false),
                            );
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()
                                ->warning()
                                ->title(__('Could not create smart channel'))
                                ->body($e->getMessage())
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Smart channel created'))
                            ->body(__('A custom channel was created with the selected sources attached as failovers, ranked by quality.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-arrow-trending-up')
                    ->modalIcon('heroicon-o-arrow-trending-up')
                    ->modalDescription(__('Create a custom "smart channel" from the selected channels. The highest-scoring source\'s title, logo, and EPG mapping will be copied. All selected channels become failovers, sorted by score, and the playlist will stream the top failover automatically.'))
                    ->modalSubmitActionLabel(__('Create smart channel')),
            ]),

            // -- Probing --
            BulkModalActionGroup::section('Probing', [
                BulkAction::make('enable-probing')
                    ->label(__('Enable Probing'))
                    ->action(function (Collection $records): void {
                        foreach ($records->chunk(100) as $chunk) {
                            Channel::whereIn('id', $chunk->pluck('id'))->update(['probe_enabled' => true]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Stream probing enabled'))
                            ->body(__('Stream probing has been enabled for the selected channels.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-signal')
                    ->modalIcon('heroicon-o-signal')
                    ->modalDescription(__('Enable stream probing for the selected channels. They will be included in stream probing jobs.'))
                    ->modalSubmitActionLabel(__('Enable now')),
                BulkAction::make('disable-probing')
                    ->label(__('Disable Probing'))
                    ->color('warning')
                    ->action(function (Collection $records): void {
                        foreach ($records->chunk(100) as $chunk) {
                            Channel::whereIn('id', $chunk->pluck('id'))->update(['probe_enabled' => false]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Stream probing disabled'))
                            ->body(__('Stream probing has been disabled for the selected channels.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-signal-slash')
                    ->modalIcon('heroicon-o-signal-slash')
                    ->modalDescription(__('Disable stream probing for the selected channels. They will be excluded from stream probing jobs.'))
                    ->modalSubmitActionLabel(__('Disable now')),
                BulkAction::make('probe-streams')
                    ->label(__('Probe Streams'))
                    ->action(function (Collection $records): void {
                        dispatch(new ProbeChannelStreams(
                            channelIds: $records->pluck('id')->all(),
                        ));
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Stream probing started'))
                            ->body(__('Stream probing is running in the background. You will be notified once the process is complete.'))
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-signal')
                    ->modalIcon('heroicon-o-signal')
                    ->modalDescription(__('Probe the selected channels with ffprobe to collect stream metadata (codec, resolution, bitrate). This data enables fast channel switching in Emby.'))
                    ->modalSubmitActionLabel(__('Start probing')),
            ]),

            // -- Enable / Disable --
            BulkModalActionGroup::section('Enable / Disable', [
                BulkAction::make('enable')
                    ->label(__('Enable selected'))
                    ->action(function (Collection $records): void {
                        foreach ($records->chunk(100) as $chunk) {
                            Channel::whereIn('id', $chunk->pluck('id'))->update(['enabled' => true]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Selected channels enabled'))
                            ->body(__('The selected channels have been enabled.'))
                            ->send();
                        dispatch(new SyncPlexDvrJob(trigger: 'channel_bulk_enable'));
                    })
                    ->color('success')
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-check-circle')
                    ->modalIcon('heroicon-o-check-circle')
                    ->modalDescription(__('Enable the selected channel(s) now?'))
                    ->modalSubmitActionLabel(__('Yes, enable now')),
                BulkAction::make('disable')
                    ->label(__('Disable selected'))
                    ->action(function (Collection $records): void {
                        foreach ($records->chunk(100) as $chunk) {
                            Channel::whereIn('id', $chunk->pluck('id'))->update(['enabled' => false]);
                        }
                    })->after(function () {
                        Notification::make()
                            ->success()
                            ->title(__('Selected channels disabled'))
                            ->body(__('The selected channels have been disabled.'))
                            ->send();
                        dispatch(new SyncPlexDvrJob(trigger: 'channel_bulk_disable'));
                    })
                    ->color('danger')
                    ->deselectRecordsAfterCompletion()
                    ->requiresConfirmation()
                    ->icon('heroicon-o-x-circle')
                    ->modalIcon('heroicon-o-x-circle')
                    ->modalDescription(__('Disable the selected channel(s) now?'))
                    ->modalSubmitActionLabel(__('Yes, disable now')),
            ]),
        ];
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChannels::route('/'),
            // 'create' => Pages\CreateChannel::route('/create'),
            // 'view' => Pages\ViewChannel::route('/{record}'),
            // 'edit' => Pages\EditChannel::route('/{record}/edit'),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Channel Details'))
                    ->collapsible()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('url')
                            ->label(__('URL'))->columnSpanFull(),
                        TextEntry::make('proxy_url')
                            ->state(fn ($record) => $record?->getProxyUrl())
                            ->label(__('Proxy URL'))->columnSpanFull(),
                        TextEntry::make('stream_id')
                            ->label(__('ID')),
                        TextEntry::make('title')
                            ->label(__('Title')),
                        TextEntry::make('name')
                            ->label(__('Name')),
                        TextEntry::make('channel')
                            ->label(__('Channel')),
                        TextEntry::make('group')
                            ->label(__('Group')),
                        IconEntry::make('catchup')
                            ->label(__('Catchup'))
                            ->boolean()
                            ->trueColor('success')
                            ->falseColor('danger'),
                    ]),
                Section::make(__('Technical Details'))
                    ->collapsible()
                    ->visible(fn ($record) => $record && ! $record->is_vod && ! $record->isSmartChannel())
                    ->headerActions([
                        Action::make('probe')
                            ->label(fn ($record) => match (self::resolveTechnicalDetailsState($record)) {
                                'ok' => __('Re-probe'),
                                'failed' => __('Retry probe'),
                                default => __('Probe now'),
                            })
                            ->icon('heroicon-o-arrow-path')
                            ->visible(fn ($record) => $record && $record->probe_enabled)
                            ->action(function ($record) {
                                dispatch(new ProbeChannelStreams(channelIds: [$record->id]));

                                Notification::make()
                                    ->success()
                                    ->title(__('Probing started'))
                                    ->body(__('You will be notified when complete.'))
                                    ->send();
                            }),
                    ])
                    ->columns(2)
                    ->schema([
                        TextEntry::make('tech.resolution')
                            ->label(__('Resolution'))
                            ->state(fn ($record) => $record?->getStreamStatsForDisplay()['compact']['resolution'] ?? null)
                            ->visible(fn ($record) => self::resolveTechnicalDetailsState($record) === 'ok'
                                && ! empty($record->getStreamStatsForDisplay()['compact']['resolution'])),
                        TextEntry::make('tech.source_fps')
                            ->label(__('Frame rate'))
                            ->state(function ($record) {
                                $fps = $record?->getStreamStatsForDisplay()['compact']['source_fps'] ?? null;

                                return $fps !== null ? "{$fps} fps" : null;
                            })
                            ->visible(fn ($record) => self::resolveTechnicalDetailsState($record) === 'ok'
                                && ($record->getStreamStatsForDisplay()['compact']['source_fps'] ?? null) !== null),
                        TextEntry::make('tech.video_codec_display')
                            ->label(__('Video codec'))
                            ->state(fn ($record) => $record?->getStreamStatsForDisplay()['compact']['video_codec_display'] ?? null)
                            ->visible(fn ($record) => self::resolveTechnicalDetailsState($record) === 'ok'
                                && ! empty($record->getStreamStatsForDisplay()['compact']['video_codec_display'])),
                        TextEntry::make('tech.ffmpeg_output_bitrate')
                            ->label(__('Video bitrate'))
                            ->state(function ($record) {
                                $v = $record?->getStreamStatsForDisplay()['compact']['ffmpeg_output_bitrate'] ?? null;

                                return $v !== null ? number_format($v, 0).' kbps' : null;
                            })
                            ->visible(fn ($record) => self::resolveTechnicalDetailsState($record) === 'ok'
                                && ($record->getStreamStatsForDisplay()['compact']['ffmpeg_output_bitrate'] ?? null) !== null),
                        TextEntry::make('tech.audio_codec')
                            ->label(__('Audio codec'))
                            ->state(fn ($record) => $record?->getStreamStatsForDisplay()['compact']['audio_codec'] ?? null)
                            ->visible(fn ($record) => self::resolveTechnicalDetailsState($record) === 'ok'
                                && ! empty($record->getStreamStatsForDisplay()['compact']['audio_codec'])),
                        TextEntry::make('tech.audio_channels')
                            ->label(__('Audio channels'))
                            ->state(fn ($record) => $record?->getStreamStatsForDisplay()['compact']['audio_channels'] ?? null)
                            ->visible(fn ($record) => self::resolveTechnicalDetailsState($record) === 'ok'
                                && ! empty($record->getStreamStatsForDisplay()['compact']['audio_channels'])),
                        TextEntry::make('tech.audio_bitrate')
                            ->label(__('Audio bitrate'))
                            ->state(function ($record) {
                                $v = $record?->getStreamStatsForDisplay()['compact']['audio_bitrate'] ?? null;

                                return $v !== null ? number_format($v, 0).' kbps' : null;
                            })
                            ->visible(fn ($record) => self::resolveTechnicalDetailsState($record) === 'ok'
                                && ($record->getStreamStatsForDisplay()['compact']['audio_bitrate'] ?? null) !== null),
                        TextEntry::make('tech.audio_language')
                            ->label(__('Audio language'))
                            ->state(fn ($record) => $record?->getStreamStatsForDisplay()['compact']['audio_language'] ?? null)
                            ->visible(fn ($record) => self::resolveTechnicalDetailsState($record) === 'ok'
                                && ! empty($record->getStreamStatsForDisplay()['compact']['audio_language'])),
                        TextEntry::make('tech.probed_at')
                            ->label(__('Last probed'))
                            ->state(fn ($record) => $record?->stream_stats_probed_at?->diffForHumans())
                            ->tooltip(fn ($record) => $record?->stream_stats_probed_at?->toDayDateTimeString())
                            ->visible(fn ($record) => self::resolveTechnicalDetailsState($record) === 'ok'),
                        Section::make(__('Advanced'))
                            ->collapsible()
                            ->collapsed()
                            ->compact()
                            ->columnSpanFull()
                            ->columns(2)
                            ->visible(fn ($record) => self::resolveTechnicalDetailsState($record) === 'ok')
                            ->schema([
                                TextEntry::make('tech.adv.video_codec_long_name')
                                    ->label(__('Codec long name'))
                                    ->state(fn ($record) => $record?->getStreamStatsForDisplay()['advanced']['video']['codec_long_name'] ?? null)
                                    ->visible(fn ($record) => ! empty($record?->getStreamStatsForDisplay()['advanced']['video']['codec_long_name'])),
                                TextEntry::make('tech.adv.video_level')
                                    ->label(__('Level'))
                                    ->state(fn ($record) => $record?->getStreamStatsForDisplay()['advanced']['video']['level'])
                                    ->visible(fn ($record) => ($record?->getStreamStatsForDisplay()['advanced']['video']['level'] ?? null) !== null),
                                TextEntry::make('tech.adv.video_bit_depth')
                                    ->label(__('Bit depth'))
                                    ->state(fn ($record) => $record?->getStreamStatsForDisplay()['advanced']['video']['bit_depth'])
                                    ->visible(fn ($record) => ($record?->getStreamStatsForDisplay()['advanced']['video']['bit_depth'] ?? null) !== null),
                                TextEntry::make('tech.adv.video_ref_frames')
                                    ->label(__('Ref frames'))
                                    ->state(fn ($record) => $record?->getStreamStatsForDisplay()['advanced']['video']['ref_frames'])
                                    ->visible(fn ($record) => ($record?->getStreamStatsForDisplay()['advanced']['video']['ref_frames'] ?? null) !== null),
                                TextEntry::make('tech.adv.video_dar')
                                    ->label(__('Display aspect ratio'))
                                    ->state(fn ($record) => $record?->getStreamStatsForDisplay()['advanced']['video']['display_aspect_ratio'] ?? null)
                                    ->visible(fn ($record) => ! empty($record?->getStreamStatsForDisplay()['advanced']['video']['display_aspect_ratio'])),
                                TextEntry::make('tech.adv.audio_sample_rate')
                                    ->label(__('Sample rate'))
                                    ->state(fn ($record) => $record?->getStreamStatsForDisplay()['advanced']['audio']['sample_rate'])
                                    ->visible(fn ($record) => ($record?->getStreamStatsForDisplay()['advanced']['audio']['sample_rate'] ?? null) !== null),
                                TextEntry::make('tech.adv.audio_codec_long_name')
                                    ->label(__('Codec long name'))
                                    ->state(fn ($record) => $record?->getStreamStatsForDisplay()['advanced']['audio']['codec_long_name'] ?? null)
                                    ->visible(fn ($record) => ! empty($record?->getStreamStatsForDisplay()['advanced']['audio']['codec_long_name'])),
                                TextEntry::make('tech.adv.all_streams')
                                    ->label(__('All streams'))
                                    ->columnSpanFull()
                                    ->state(function ($record) {
                                        $streams = $record?->getStreamStatsForDisplay()['advanced']['all_streams'] ?? null;
                                        if (! $streams) {
                                            return null;
                                        }
                                        $rows = array_map(function (array $s) {
                                            $parts = ["#{$s['index']}", $s['type'], $s['codec']];
                                            if ($s['lang'] !== null && $s['lang'] !== '') {
                                                $parts[] = $s['lang'];
                                            }

                                            return implode(' · ', array_filter($parts, fn ($p) => $p !== null && $p !== ''));
                                        }, $streams);

                                        return implode("\n", $rows);
                                    })
                                    ->visible(fn ($record) => ! empty($record?->getStreamStatsForDisplay()['advanced']['all_streams'])),
                                TextEntry::make('tech.adv.tags')
                                    ->label(__('Tags'))
                                    ->columnSpanFull()
                                    ->state(function ($record) {
                                        $tags = $record?->getStreamStatsForDisplay()['advanced']['tags'] ?? null;
                                        if (! $tags) {
                                            return null;
                                        }
                                        $rows = [];
                                        foreach ($tags as $k => $v) {
                                            $rows[] = "{$k}: {$v}";
                                        }

                                        return implode("\n", $rows);
                                    })
                                    ->visible(fn ($record) => ! empty($record?->getStreamStatsForDisplay()['advanced']['tags'])),
                            ]),
                        TextEntry::make('tech.placeholder_never')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->icon('heroicon-o-information-circle')
                            ->state(fn () => __("This channel hasn't been probed yet. Run a probe to capture stream details."))
                            ->visible(fn ($record) => self::resolveTechnicalDetailsState($record) === 'never'),
                        TextEntry::make('tech.placeholder_failed')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->icon('heroicon-o-exclamation-triangle')
                            ->state(function ($record) {
                                $when = $record?->stream_stats_probed_at?->diffForHumans();

                                return __('Last probe returned no data — the stream may have been unreachable.')
                                    .($when ? " ({$when})" : '');
                            })
                            ->visible(fn ($record) => self::resolveTechnicalDetailsState($record) === 'failed'),
                        TextEntry::make('tech.placeholder_disabled')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->icon('heroicon-o-no-symbol')
                            ->state(fn () => __("Probing is disabled for this channel. Enable it in the channel's edit form to allow probe data collection."))
                            ->visible(fn ($record) => self::resolveTechnicalDetailsState($record) === 'disabled'),
                    ]),
                Section::make(__('Failover Ranking'))
                    ->description(__('Failovers ranked by score. Stored from the last merge or rescore — click "Rescore now" to recalculate against current stream stats.'))
                    ->collapsible()
                    ->visible(fn ($record) => $record && $record->failovers()->exists())
                    ->headerActions([
                        Action::make('rescore_failovers_inline')
                            ->label(__('Rescore now'))
                            ->icon('heroicon-o-arrow-path')
                            ->color('info')
                            ->action(function ($record) {
                                dispatch(new RescoreChannelFailovers(
                                    playlistId: $record->playlist_id,
                                    channelIds: [$record->id],
                                ));

                                Notification::make()
                                    ->success()
                                    ->title(__('Rescoring queued'))
                                    ->body(__('Failovers will be re-scored in the background. Refresh this page in a moment to see the updated ranking.'))
                                    ->duration(6000)
                                    ->send();
                            })
                            ->requiresConfirmation()
                            ->modalIcon('heroicon-o-arrow-path')
                            ->modalDescription(__('Re-score this channel\'s failovers against current stream stats. Stale channels may be re-probed (subject to the playlist\'s staleness window). The master channel is never altered — only the failover order changes.'))
                            ->modalSubmitActionLabel(__('Rescore')),
                    ])
                    ->schema([
                        TextEntry::make('failover_ranking_html')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->state(fn ($record) => self::renderFailoverRankingHtml($record))
                            ->html(),
                    ]),
            ]);
    }

    private static function resolveTechnicalDetailsState(?Channel $record): string
    {
        if (! $record) {
            return 'disabled';
        }
        if (! $record->probe_enabled) {
            return 'disabled';
        }
        if ($record->stream_stats_probed_at === null) {
            return 'never';
        }
        if (empty($record->stream_stats)) {
            return 'failed';
        }

        return 'ok';
    }

    /**
     * Render the channel's failover ranking as a stack of expandable cards.
     *
     * Each card's summary row shows rank + title + score; expanding reveals
     * the per-attribute breakdown plus the failover's probed technical
     * details (resolution, fps, bitrate, codec, audio info, last probed).
     * Returns null when the channel has no failovers attached so the section
     * can hide itself.
     */
    private static function renderFailoverRankingHtml(?Channel $record): ?HtmlString
    {
        if (! $record) {
            return null;
        }

        $failovers = $record->failovers()
            ->with('channelFailover')
            ->orderBy('sort')
            ->get();

        if ($failovers->isEmpty()) {
            return null;
        }

        $cards = $failovers->map(function ($failover, int $index) {
            $channel = $failover->channelFailover;
            if (! $channel) {
                return '';
            }

            $metadata = $failover->metadata ?? [];
            $score = $metadata['score'] ?? null;
            $breakdown = $metadata['attribute_scores'] ?? [];

            $displayTitle = e($channel->title_custom ?: $channel->title ?: $channel->name);
            $playlistName = e($channel->getEffectivePlaylist()->name ?? 'Unknown');

            $scoreBadge = $score !== null
                ? '<span class="font-mono text-sm whitespace-nowrap px-2 py-1 rounded bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300">Score '.(int) $score.'</span>'
                : '<span class="text-xs text-gray-400 italic whitespace-nowrap">not scored</span>';

            $summary = '<summary class="cursor-pointer flex items-center gap-3 list-none select-none">
                <span class="text-gray-400 group-open:rotate-90 transition-transform inline-block w-3">▸</span>
                <span class="font-mono text-xs text-gray-500 dark:text-gray-400 w-8">#'.($index + 1).'</span>
                <div class="flex-1 min-w-0">
                    <div class="font-medium truncate">'.$displayTitle.'</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 truncate">'.$playlistName.'</div>
                </div>
                '.$scoreBadge.'
            </summary>';

            $expanded = '<div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 space-y-3">';

            if (! empty($breakdown)) {
                $breakdownParts = [];
                foreach ($breakdown as $attribute => $value) {
                    $label = e(str_replace('_', ' ', $attribute));
                    $breakdownParts[] = '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-xs"><span class="text-gray-500 dark:text-gray-400">'.$label.'</span><span class="font-mono">'.(int) $value.'</span></span>';
                }
                $expanded .= '<div>
                    <div class="text-xs uppercase text-gray-500 dark:text-gray-400 mb-1.5">Score breakdown (per attribute, 0-100)</div>
                    <div class="flex flex-wrap gap-1.5">'.implode('', $breakdownParts).'</div>
                </div>';
            }

            $expanded .= self::renderFailoverTechDetails($channel);
            $expanded .= '</div>';

            return '<details class="group rounded border border-gray-200 dark:border-gray-700 p-3 hover:bg-gray-50 dark:hover:bg-gray-900/50">'.$summary.$expanded.'</details>';
        })->implode('');

        return new HtmlString('<div class="space-y-2">'.$cards.'</div>');
    }

    /**
     * Render a single failover channel's technical details for the expandable
     * ranking card. Returns a "not yet probed" hint when no stream stats are
     * available so users know whether re-probing might help.
     */
    private static function renderFailoverTechDetails(Channel $channel): string
    {
        if (empty($channel->stream_stats)) {
            $hint = $channel->probe_enabled
                ? __('Not yet probed — run a probe on this channel to capture stream details.')
                : __('Probing is disabled for this channel.');

            return '<div>
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400 mb-1.5">Technical details</div>
                <div class="text-sm text-gray-500 italic">'.e($hint).'</div>
            </div>';
        }

        $compact = $channel->getStreamStatsForDisplay()['compact'] ?? [];
        $rows = [];

        $append = function (string $label, $value, ?string $suffix = null) use (&$rows) {
            if ($value === null || $value === '') {
                return;
            }
            $formatted = is_numeric($value) && $suffix === ' kbps'
                ? number_format((float) $value, 0).$suffix
                : ($suffix ? $value.$suffix : $value);
            $rows[] = '<div class="text-xs text-gray-500 dark:text-gray-400">'.e($label).'</div><div class="text-sm font-mono">'.e((string) $formatted).'</div>';
        };

        $append('Resolution', $compact['resolution'] ?? null);
        $append('Frame rate', isset($compact['source_fps']) ? $compact['source_fps'] : null, ' fps');
        $append('Video codec', $compact['video_codec_display'] ?? null);
        $append('Video bitrate', $compact['ffmpeg_output_bitrate'] ?? null, ' kbps');
        $append('Audio codec', $compact['audio_codec'] ?? null);
        $append('Audio channels', $compact['audio_channels'] ?? null);
        $append('Audio bitrate', $compact['audio_bitrate'] ?? null, ' kbps');
        $append('Audio language', $compact['audio_language'] ?? null);

        if (empty($rows)) {
            return '<div>
                <div class="text-xs uppercase text-gray-500 dark:text-gray-400 mb-1.5">Technical details</div>
                <div class="text-sm text-gray-500 italic">'.e(__('Probe ran but returned no usable details.')).'</div>
            </div>';
        }

        $probedAt = $channel->stream_stats_probed_at?->diffForHumans();
        $footer = $probedAt
            ? '<div class="text-xs text-gray-400 dark:text-gray-500 mt-2">'.e(__('Last probed').': '.$probedAt).'</div>'
            : '';

        return '<div>
            <div class="text-xs uppercase text-gray-500 dark:text-gray-400 mb-1.5">Technical details</div>
            <div class="grid grid-cols-2 gap-x-4 gap-y-1">'.implode('', $rows).'</div>
            '.$footer.'
        </div>';
    }

    public static function getForm($customPlaylist = null, $edit = false): array
    {
        return [
            // Customizable channel fields
            Toggle::make('enabled')
                ->columnSpanFull()
                ->default(true),
            Grid::make()
                ->columns(3)
                ->schema([
                    Toggle::make('can_merge')
                        ->default(true)
                        ->helperText(__('Allow this channel to be merged during "Merge Same ID" jobs.')),
                    Toggle::make('epg_map_enabled')
                        ->default(true)
                        ->helperText(__('Allow mapping EPG to this channel when running EPG mapping jobs.')),
                    Toggle::make('probe_enabled')
                        ->default(true)
                        ->helperText(__('Allow probing this channel when running playlist channel probe jobs.')),
                    Toggle::make('is_smart_channel')
                        ->label(__('Smart channel'))
                        ->default(false)
                        ->live()
                        ->helperText(__('Auto-streams the highest-ranked failover. URL field is locked while on. Custom channels only.'))
                        ->visible(fn (Get $get) => (bool) $get('is_custom')),
                ]),
            Fieldset::make(__('Playlist Type (choose one)'))
                ->schema([
                    Toggle::make('is_custom')
                        ->default(true)
                        ->hidden()
                        ->columnSpan('full'),
                    Select::make('playlist_id')
                        ->label(__('Playlist'))
                        ->options(fn () => Playlist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Set $set, $state) {
                            if ($state) {
                                $set('custom_playlist_id', null);
                                $set('group', null);
                                $set('group_id', null);
                            }
                        })
                        ->requiredWithout('custom_playlist_id')
                        ->hidden($customPlaylist !== null)
                        ->validationMessages([
                            'required_without' => 'Playlist is required if not using a custom playlist.',
                        ])
                        ->rules(['exists:playlists,id']),
                    Select::make('custom_playlist_id')
                        ->label(__('Custom Playlist'))
                        ->options(fn () => CustomPlaylist::where(['user_id' => auth()->id()])->get(['name', 'id'])->pluck('name', 'id'))
                        ->searchable()
                        ->disabled($customPlaylist !== null)
                        ->default($customPlaylist ? $customPlaylist->id : null)
                        ->live()
                        ->afterStateUpdated(function (Set $set, $state) {
                            if ($state) {
                                $set('playlist_id', null);
                                $set('group', null);
                                $set('group_id', null);
                            }
                        })
                        ->requiredWithout('playlist_id')
                        ->validationMessages([
                            'required_without' => 'Custom Playlist is required if not using a standard playlist.',
                        ])
                        ->dehydrated(true)
                        ->rules(['exists:custom_playlists,id']),
                ])->hidden($edit),
            Fieldset::make(__('General Settings'))
                ->schema([
                    TextInput::make('title')
                        ->label(__('Title'))
                        ->columnSpan(1)
                        ->required()
                        ->hidden($edit)
                        ->rules(['min:1', 'max:255']),
                    TextInput::make('title_custom')
                        ->label(__('Title'))
                        ->placeholder(fn (Get $get) => $get('title'))
                        ->helperText(__('Leave empty to use default value.'))
                        ->columnSpan(1)
                        ->rules(['min:1', 'max:255'])
                        ->hidden(! $edit),
                    TextInput::make('name_custom')
                        ->label(__('Name'))
                        ->hint(__('tvg-name'))
                        ->placeholder(fn (Get $get) => $get('name'))
                        ->helperText(fn (Get $get) => $get('is_custom') ? '' : 'Leave empty to use default value.')
                        ->columnSpan(1)
                        ->rules(['min:1', 'max:255']),
                    TextInput::make('stream_id_custom')
                        ->label(__('ID'))
                        ->hint(__('tvg-id'))
                        ->columnSpan(1)
                        ->placeholder(fn (Get $get) => $get('stream_id'))
                        ->helperText(fn (Get $get) => $get('is_custom') ? '' : 'Leave empty to use default value.')
                        ->rules(['min:1', 'max:255']),
                    TextInput::make('station_id')
                        ->label(__('Station ID'))
                        ->hint(__('tvc-guide-stationid'))
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Gracenote station ID is a unique identifier for a TV channel in the Gracenote database. It is used to associate the channel with its metadata, such as program listings and other information.'
                        )
                        ->columnSpan(1)
                        ->helperText(__('Gracenote station ID'))
                        ->type('number')
                        ->rules(['numeric', 'min:0']),
                    TextInput::make('channel')
                        ->label(__('Channel No.'))
                        ->hint(__('tvg-chno'))
                        ->columnSpan(1)
                        ->rules(['numeric', 'min:0']),
                    TextInput::make('shift')
                        ->label(__('Time Shift'))
                        ->hint(__('timeshift'))
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Time-shift is features that enable you to access content that has already been broadcast or is currently being broadcast, but at a different time than the original schedule. Time-shift allows you to pause, rewind, or fast-forward live TV, giving you more control over your viewing experience. Your provider must support this feature for it to work.'
                        )
                        ->type('number')
                        ->placeholder(0)
                        ->columnSpan(1)
                        ->rules(['numeric', 'min:0']),
                    Grid::make()
                        ->columnSpanFull()
                        ->schema([
                            Hidden::make('group'),
                            Select::make('group_id')
                                ->label(__('Group'))
                                ->hint(__('group-title'))
                                ->options(fn (Get $get) => Group::where('playlist_id', $get('playlist_id'))->get(['name', 'id'])->pluck('name', 'id'))
                                ->columnSpanFull()
                                ->placeholder(__('Select a group'))
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set) {
                                    $group = Group::find($get('group_id'));
                                    $set('group', $group->name ?? null);
                                })
                                ->rules(['numeric', 'min:0']),
                        ])->hidden(fn (Get $get) => ! $get('playlist_id')),
                    TextInput::make('group')
                        ->columnSpanFull()
                        ->placeholder(__('Enter a group title'))
                        ->hint(__('group-title'))
                        ->hidden(! $edit)
                        ->rules(['min:1', 'max:255'])
                        ->hidden(fn (Get $get) => ! $get('custom_playlist_id')),
                ]),
            Fieldset::make(__('URL Settings'))
                ->schema([
                    TextInput::make('url')
                        ->label(fn (Get $get) => $get('is_custom') ? 'URL' : 'Provider URL')
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hintIcon(
                            icon: fn (Get $get) => $get('is_smart_channel')
                                ? 'heroicon-m-sparkles'
                                : ($get('is_custom') ? null : 'heroicon-m-question-mark-circle'),
                            tooltip: fn (Get $get) => $get('is_smart_channel')
                                ? 'This is a smart channel. The streamed URL is taken from the highest-ranked failover automatically — set the URL on a failover channel instead, or remove the smart-channel flag to manage the URL directly.'
                                : ($get('is_custom') ? null : 'The original URL from the playlist provider. This is read-only and cannot be modified. This URL is automatically updated on Playlist sync.')
                        )
                        ->formatStateUsing(fn ($record) => $record?->url)
                        ->disabled(fn (Get $get) => $get('is_smart_channel') || ! $get('is_custom'))
                        ->dehydrated(fn (Get $get) => ! $get('is_smart_channel') && $get('is_custom'))
                        ->type('url'),
                    TextInput::make('url_custom')
                        ->label(__('URL Override'))
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Override the provider URL with your own custom URL. This URL will be used instead of the provider URL.'
                        )
                        ->helperText(__('Leave empty to use provider URL.'))
                        ->rules(['min:1'])
                        ->type('url')
                        ->hidden(fn (Get $get) => $get('is_custom')),
                    TextInput::make('logo_internal')
                        ->label(fn (Get $get) => $get('is_custom') ? 'Logo' : 'Provider Logo')
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hint(__('tvg-logo'))
                        ->hintIcon(
                            icon: fn (Get $get) => $get('is_custom') ? null : 'heroicon-m-question-mark-circle',
                            tooltip: fn (Get $get) => $get('is_custom') ? null : 'The original logo from the playlist provider. This is read-only and cannot be modified. This URL is automatically updated on Playlist sync.'
                        )
                        ->formatStateUsing(fn ($record) => $record?->logo_internal)
                        ->disabled(fn (Get $get) => ! $get('is_custom')) // make it read-only but copyable for non-custom channels
                        ->dehydrated(fn (Get $get) => $get('is_custom')) // don't save the value in the database for custom channels
                        ->type('url')
                        ->suffixActions([
                            AssetPickerAction::upload('logo_internal')
                                ->visible(fn (Get $get): bool => $get('is_custom')),
                            AssetPickerAction::browse('logo_internal')
                                ->visible(fn (Get $get): bool => $get('is_custom')),
                        ]),
                    TextInput::make('logo')
                        ->label(__('Logo Override'))
                        ->columnSpan(1)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hint(__('tvg-logo'))
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Override the provider logo with your own custom logo. This logo will be used instead of the provider logo.'
                        )
                        ->helperText(__('Leave empty to use provider logo.'))
                        ->rules(['min:1'])
                        ->type('url')
                        ->hidden(fn (Get $get) => $get('is_custom'))
                        ->suffixActions([
                            AssetPickerAction::upload('logo'),
                            AssetPickerAction::browse('logo'),
                        ]),
                    Toggle::make('catchup')
                        ->label(__('Catchup'))
                        ->inline(false)
                        ->columnSpan(1)
                        ->live()
                        ->hidden(fn (Get $get) => ! $get('is_custom')),
                    TextInput::make('catchup_source')
                        ->label(__('Catchup Source'))
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->url()
                        ->hidden(fn (Get $get) => ! ($get('is_custom') && $get('catchup'))),
                    TextInput::make('proxy_url')
                        ->label(__('Proxy URL'))
                        ->columnSpan(2)
                        ->prefixIcon('heroicon-m-globe-alt')
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'Use m3u editor proxy to access this channel.'
                        )
                        ->formatStateUsing(fn ($record) => $record?->getProxyUrl())
                        ->helperText(__('m3u editor proxy url.'))
                        ->disabled() // make it read-only but copyable
                        ->dehydrated(false) // don't save the value in the database
                        ->type('url')
                        ->hiddenOn('create'),
                ]),
            Fieldset::make(__('Proxy Settings'))
                ->columns(2)
                ->hidden(fn () => ! auth()->user()->canUseProxy())
                ->schema([
                    Toggle::make('enable_proxy')
                        ->label(__('Enable Stream Proxy'))
                        ->columnSpanFull()
                        ->live()
                        ->formatStateUsing(fn ($state, $record): bool => (bool) $state || (bool) ($record?->playlist?->enable_proxy ?? false))
                        ->hint(fn (Get $get, $record): string => ($get('enable_proxy') || $record?->playlist?->enable_proxy) ? 'Proxied' : 'Not proxied')
                        ->hintIcon(fn (Get $get, $record): string => ($get('enable_proxy') || $record?->playlist?->enable_proxy) ? 'heroicon-m-lock-closed' : 'heroicon-m-lock-open')
                        ->helperText(fn ($record): string => $record?->playlist?->enable_proxy
                            ? __('Proxy is enabled on the parent playlist. All channels in this playlist are already proxied. You can still select a stream profile override below.')
                            : __('When enabled, this stream will be proxied through the application. This allows for better compatibility with various clients and enables features such as output format selection.'))
                        ->disabled(fn ($record): bool => (bool) ($record?->playlist?->enable_proxy ?? false))
                        ->dehydrated()
                        ->inline(false)
                        ->default(false),
                    Select::make('stream_profile_id')
                        ->label(__('Stream Profile'))
                        ->options(fn () => StreamProfile::where('user_id', auth()->id())->pluck('name', 'id'))
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->columnSpanFull()
                        ->visible(fn (Get $get, $record): bool => (bool) $get('enable_proxy') || (bool) ($record?->playlist?->enable_proxy ?? false))
                        ->helperText(__('Transcode this channel using the selected profile. Takes priority over both the playlist-level stream profile (used by external clients) and the in-app player default (Settings → Proxy → In-App Player Transcoding). Leave empty to fall back to those defaults.')),
                ]),
            Fieldset::make(__('EPG Settings'))
                ->schema([
                    Select::make('epg_channel_id')
                        ->label(__('EPG Channel'))
                        ->helperText(__('Select an associated EPG channel for this channel.'))
                        ->relationship('epgChannel', 'name', fn ($query) => $query->with('epg'))
                        ->getOptionLabelFromRecordUsing(fn ($record) => "$record->name [".($record->epg?->name ?? 'Unknown').']')
                        ->getSearchResultsUsing(function (string $search) {
                            $searchLower = strtolower($search);
                            $channels = auth()->user()->epgChannels()
                                ->withoutEagerLoads()
                                ->with('epg')
                                ->where(function ($query) use ($searchLower) {
                                    $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                        ->orWhereRaw('LOWER(display_name) LIKE ?', ["%{$searchLower}%"])
                                        ->orWhereRaw('LOWER(channel_id) LIKE ?', ["%{$searchLower}%"]);
                                })
                                ->limit(50) // Keep a reasonable limit
                                ->get();

                            // Create options array
                            $options = [];
                            foreach ($channels as $channel) {
                                $displayTitle = $channel->name;
                                $epgName = $channel->epg->name ?? 'Unknown';
                                $options[$channel->id] = "{$displayTitle} [{$epgName}]";
                            }

                            return $options;
                        })
                        ->searchable()
                        ->columnSpan(1),
                    Select::make('logo_type')
                        ->label(__('Preferred Icon'))
                        ->helperText(__('Prefer icon from channel or EPG.'))
                        ->options([
                            'channel' => 'Channel',
                            'epg' => 'EPG',
                        ])
                        ->columnSpan(1),
                    TextInput::make('tvg_shift')
                        ->label(__('EPG Shift'))
                        ->hint(__('tvg-shift'))
                        ->hintIcon(
                            'heroicon-m-question-mark-circle',
                            tooltip: 'The "tvg-shift" attribute is used in your generated M3U playlist to shift the EPG (Electronic Program Guide) time for specific channels by a certain number of hours. This allows for adjusting the EPG data for individual channels rather than applying a global shift.'
                        )
                        ->columnSpan(1)
                        ->placeholder(__('0'))
                        ->type('number')
                        ->helperText(__('Indicates the shift of the program schedule, use the values -2,-1,0,1,2,.. and so on.'))
                        ->rules(['nullable', 'numeric']),
                ]),
            Fieldset::make(__('Failover Channels'))
                ->schema([
                    Placeholder::make('smart_channel_no_failovers_warning')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('is_smart_channel') && empty($get('failovers')))
                        ->content(new HtmlString(
                            '<div class="flex items-start gap-3 p-3 rounded-lg bg-warning-50 dark:bg-warning-950/50 border border-warning-200 dark:border-warning-800">
                                <svg class="w-5 h-5 flex-shrink-0 text-warning-600 dark:text-warning-400 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                <div class="text-sm text-warning-700 dark:text-warning-300">
                                    <strong>'.e(__('Smart channel without failovers won\'t stream.')).'</strong> '
                                    .e(__('Add at least one failover channel below — the smart channel takes its stream URL from the highest-ranked failover at stream time.')).'
                                </div>
                            </div>'
                        )),
                    Repeater::make('failovers')
                        ->relationship()
                        ->label('')
                        ->reorderable()
                        ->reorderableWithButtons()
                        ->orderColumn('sort')
                        ->simple(
                            Select::make('channel_failover_id')
                                ->label(__('Failover Channel'))
                                ->options(function ($state, $record) {
                                    // Get the current channel ID to exclude it from options
                                    if (! $state) {
                                        return [];
                                    }
                                    $channel = Channel::find($state);
                                    if (! $channel) {
                                        return [];
                                    }

                                    // Return the single channel as the only results if not searching
                                    $displayTitle = $channel->title_custom ?: $channel->title;
                                    $playlistName = $channel->getEffectivePlaylist()->name ?? 'Unknown';

                                    return [$channel->id => "{$displayTitle} [{$playlistName}]"];
                                })
                                ->searchable()
                                ->getSearchResultsUsing(function (string $search, $get, $livewire) {
                                    $existingFailoverIds = collect($get('../../failovers') ?? [])
                                        ->filter(fn ($failover) => $failover['channel_failover_id'] ?? null)
                                        ->pluck('channel_failover_id')
                                        ->toArray();

                                    // Get parent record ID to exclude it from search results
                                    $parentRecordId = $livewire->mountedTableActionsData[0]['id'] ?? null;
                                    if ($parentRecordId) {
                                        $existingFailoverIds[] = $parentRecordId;
                                    }

                                    // Always include the selected value if it exists
                                    $searchLower = strtolower($search);
                                    $channels = auth()->user()->channels()
                                        ->withoutEagerLoads()
                                        ->with('playlist')
                                        ->whereNotIn('id', $existingFailoverIds)
                                        ->where('is_smart_channel', false)
                                        ->where(function ($query) use ($searchLower) {
                                            $query->whereRaw('LOWER(title) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(title_custom) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(name) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(name_custom) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(stream_id) LIKE ?', ["%{$searchLower}%"])
                                                ->orWhereRaw('LOWER(stream_id_custom) LIKE ?', ["%{$searchLower}%"]);
                                        })
                                        ->limit(50) // Keep a reasonable limit
                                        ->get();

                                    // Create options array
                                    $options = [];
                                    foreach ($channels as $channel) {
                                        $displayTitle = $channel->title_custom ?: $channel->title;
                                        $playlistName = $channel->getEffectivePlaylist()->name ?? 'Unknown';
                                        $options[$channel->id] = "{$displayTitle} [{$playlistName}]";
                                    }

                                    return $options;
                                })->required()
                        )
                        ->distinct()
                        ->columns(1)
                        ->addActionLabel('Add failover channel')
                        ->columnSpanFull()
                        ->defaultItems(0),
                ]),
        ];
    }

    /**
     * Create a custom channel with the provided data.
     *
     * This method is used to create a channel with custom data, typically for a Custom Playlist.
     *
     * @param  array  $data  The data for the channel.
     * @param  string  $model  The model class to use for creating the channel.
     * @return Model The created channel model.
     *
     * @throws ValidationException
     * @throws ModelNotFoundException
     * @throws QueryException
     * @throws Exception
     */
    public static function createCustomChannel(array $data, string $model): Model
    {
        $data['user_id'] = auth()->id();
        $data['is_custom'] = true;
        if (! $data['shift']) {
            $data['shift'] = 0; // Default shift to 0 if not provided
        }
        if (! $data['logo_type']) {
            $data['logo_type'] = 'channel'; // Default to channel if not provided
        }
        $channel = $model::create($data);

        // If the channel is created for a Custom Playlist, we need to associate it with the Custom Playlist
        if (isset($data['custom_playlist_id']) && $data['custom_playlist_id']) {
            $channel->customPlaylists()
                ->syncWithoutDetaching([$data['custom_playlist_id']]);

            $channel->save();
        }

        return $channel;
    }
}
