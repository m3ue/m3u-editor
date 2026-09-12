<?php

namespace App\Filament\Resources\SeriesDynamicGroups\Pages;

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\SeriesDynamicGroups\SeriesDynamicGroupResource;
use App\Filament\Resources\SeriesDynamicGroups\Widgets\SeriesDynamicGroupCacheActivityWidget;
use App\Jobs\SyncDynamicGroups;
use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Services\DynamicGroupCacheDispatchService;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select as FormsSelect;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Series-only listing surface for DynamicGroup rows.
 *
 * Sister page to `VodDynamicGroups\Pages\ListVodDynamicGroups` — the
 * Dynamic Groups listing the user wanted split out of the per-type
 * footer widgets, exposed under the existing Series nav section so
 * it sits as a sibling of Categories and Series itself.
 *
 * The query is already filtered to `type = 'series'` by
 * `SeriesDynamicGroupResource::getEloquentQuery()` — no per-row type
 * switch needed here, so the cache-status math is the simple episode
 * version that lives here (the VOD-side gets the channel version).
 *
 * The row's "view" action links to the shared
 * `DynamicGroupResource::getUrl('view', ...)` route — single detail
 * page keyed by record id, breadcrumb chains through
 * `CategoryResource` (see `Pages\ViewDynamicGroup`).
 */
class ListSeriesDynamicGroups extends ListRecords
{
    protected static string $resource = SeriesDynamicGroupResource::class;

    /**
     * Top-level tab state — "groups" (default) or "cache". Set by
     * Filament when the user clicks one of the two top-level tab
     * labels rendered by getTabs(). Used by content() to switch
     * between the Dynamic Groups table and the Download Cache
     * Activity widget.
     */
    public ?string $activeTab = null;

    /**
     * Per-playlist sub-tab state, independent of activeTab. Lets the
     * user drill into a specific playlist's groups or cache activity
     * without losing the selection when they switch top-level tabs.
     * Default "all" = no playlist filter.
     */
    public ?string $activePlaylistTab = 'all';

    /**
     * Header action: a 'New Series Dynamic Group' CreateAction that opens
     * a slide-over modal with the same rule schema the Playlist form's
     * `dynamic_groups_config` Repeater uses, plus a Playlist picker
     * (prefilled with the active sub-tab when not on 'All'). On save the
     * rule is appended to that playlist's dynamic_groups_config and the
     * DynamicGroup row is materialized synchronously so the new group
     * shows in the table immediately. Redirects to the shared
     * DynamicGroupResource::view page.
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New Series Dynamic Group'))
                ->icon('heroicon-o-plus')
                ->slideOver()
                ->modalHeading(__('New Series Dynamic Group'))
                ->modalSubmitActionLabel(__('Create'))
                ->schema([
                    FormsSelect::make('playlist_id')
                        ->label(__('Playlist'))
                        ->required()
                        ->options(fn (): array => auth()->user()?->playlists()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all() ?? [])
                        ->default(fn (): ?int => $this->activePlaylistTab !== 'all'
                            ? (int) $this->activePlaylistTab
                            : null)
                        ->helperText(__('The Dynamic Group rule will be appended to this playlist\u0027s Dynamic Groups (TMDB) configuration.')),
                ] + PlaylistResource::getDynamicGroupRuleSchema())
                // Inject the implicit type and user_id INSIDE the using()
                // closure rather than via Filament's mutateFormDataBeforeCreate
                // hook (v5 dropped that method).
                ->using(function (array $data, string $model): DynamicGroup {
                    $data['type'] = 'series';
                    $data['user_id'] = auth()->id();
                    $playlist = Playlist::findOrFail($data['playlist_id']);
                    $rule = collect($data)
                        ->only([
                            'enabled', 'type', 'source', 'name', 'tmdb_params',
                            'cache_enabled',
                            'cache_content_selection', 'cache_location_override',
                            'cache_prefer_quality_keyword', 'cache_avoid_duplicate_content',
                        ])
                        ->all();

                    $config = $playlist->dynamic_groups_config ?? [];
                    $config[] = $rule;
                    $playlist->update(['dynamic_groups_config' => $config]);

                    $tmdb = app(TmdbService::class);
                    $job = new SyncDynamicGroups($playlist->id);
                    $group = $job->materializeRule(
                        $playlist,
                        $data['type'],
                        $data['source'],
                        $data['name'],
                        $data['tmdb_params'] ?? [],
                        count($config) - 1,
                        $tmdb,
                    );

                    if ($group === null) {
                        $playlist->update(['dynamic_groups_config' => array_values(array_slice($config, 0, -1))]);
                        throw new RuntimeException(__('No TMDB matches for this rule and no pre-existing Dynamic Group. Rule was not saved.'));
                    }

                    return $group;
                })
                ->successNotification(
                    Notification::make()
                        ->success()
                        ->title(__('Dynamic Group created'))
                        ->body(__('The new group is now in the table. Cache Now can queue downloads for it.')),
                )
                ->successRedirectUrl(fn (DynamicGroup $record): string => DynamicGroupResource::getUrl('view', ['record' => $record]))
                ->visible(fn (): bool => DynamicGroup::canCreate()),
        ];
    }

    /**
     * Top-level tabs: "Dynamic Groups" (the main table) and
     * "Download Cache Activity" (the cache pipeline widget). The
     * per-playlist sub-tabs are NOT here — they're rendered as a
     * second, nested Tabs component inside content() so the two
     * state values stay independent (clicking a playlist on the
     * groups tab carries over to the cache tab).
     */
    public function getTabs(): array
    {
        $groupsCount = static::getResource()::getEloquentQuery()->count();
        $cacheCount = (int) CachedContentFile::query()
            ->where('content_type', SeriesDynamicGroupResource::CONTENT_TYPE)
            ->count();

        return [
            'groups' => Tab::make(__('Dynamic Groups'))
                ->icon('heroicon-m-squares-2x2')
                ->badge($groupsCount),
            'cache' => Tab::make(__('Download Cache Activity'))
                ->icon('heroicon-m-cloud-arrow-down')
                ->badge($cacheCount),
        ];
    }

    /**
     * Per-playlist sub-tabs. See the VOD-side docblock for the full
     * rationale — these scope BOTH the Dynamic Groups view AND the
     * Download Cache Activity view to a single playlist.
     */
    public function getPlaylistSubTabs(): array
    {
        $base = static::getResource()::getEloquentQuery();

        $allCount = (clone $base)->count();
        $playlistCounts = (clone $base)
            ->selectRaw('playlist_id, count(*) as aggregate')
            ->groupBy('playlist_id')
            ->pluck('aggregate', 'playlist_id');

        $playlists = Playlist::query()
            ->whereIn('id', $playlistCounts->keys())
            ->orderBy('name')
            ->get();

        $tabs = [
            'all' => Tab::make(__('All Playlists'))
                ->badge($allCount),
        ];
        foreach ($playlists as $playlist) {
            $tabs[(string) $playlist->id] = Tab::make($playlist->name)
                ->modifyQueryUsing(fn ($query) => $query->where('dynamic_groups.playlist_id', $playlist->id))
                ->badge($playlistCounts->get($playlist->id, 0));
        }

        return $tabs;
    }

    /**
     * Render the active top-level tab's content + the shared
     * per-playlist sub-tabs. See the VOD-side docblock for the full
     * rationale — top-level tab swaps the main content; sub-tabs
     * stay constant across top-level switches.
     */
    public function content(Schema $schema): Schema
    {
        $isCacheTab = $this->activeTab === 'cache';

        $subTabs = Tabs::make('playlistTabs')
            ->livewireProperty('activePlaylistTab')
            ->contained(false)
            ->tabs($this->getPlaylistSubTabs())
            ->hidden(empty($this->getPlaylistSubTabs()));

        $main = $isCacheTab
            ? Livewire::make(
                SeriesDynamicGroupCacheActivityWidget::class,
                fn (): array => ['activePlaylistId' => $this->activePlaylistTab],
            )
            : EmbeddedTable::make();

        return $schema
            ->components([
                $subTabs,
                $this->getTabsContentComponent(),
                $main,
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            // withCount('series') attaches the series pivot count as a
            // subquery column the TextColumn::make('series_count')
            // reads from. Applied via modifyQueryUsing so it ONLY
            // attaches when the table renders — getTabs() runs a
            // separate groupBy('playlist_id') query against the
            // resource's getEloquentQuery() and that combination blows
            // up Postgres (subquery columns must be in GROUP BY).
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('series'))
            ->recordUrl(fn (DynamicGroup $record): string => DynamicGroupResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('playlist.name')
                    ->label(__('Playlist'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source')
                    ->formatStateUsing(fn (DynamicGroup $record): string => DynamicGroupResource::sourceLabelFor($record->type)[$record->source] ?? $record->source)
                    ->badge(),
                IconColumn::make('enabled')
                    ->boolean(),
                TextColumn::make('series_count')
                    ->label(__('Items'))
                    ->numeric(),
                // Group-level "Cached / Total" fraction. See
                // VOD-side page for the full rationale. Per-episode
                // Cached indicators would live on the Series relation
                // manager if/when that surface needs them.
                TextColumn::make('cache_status')
                    ->label(__('Cache progress'))
                    // Series-type mirror of the VOD widget's "Cached / Total"
                    // column. Key difference vs. the VOD page: the
                    // denominator is the group's EPISODE count
                    // ($series->flatMap(fn ($s) => $s->episodes)->count()),
                    // NOT the series count — one cached series contributes
                    // its full episode count, matching how the Phase 2
                    // dispatcher iterates (each episode produces its own
                    // download job with its own fingerprint).
                    //
                    // Lazy-loading `$record->series` and `$series->episodes`
                    // is an N+1 cost per row; acceptable at the scale this
                    // listing runs at (handful of groups per playlist tab)
                    // and flagged for future optimization.
                    ->state(function (DynamicGroup $record): string {
                        $dispatch = app(DynamicGroupCacheDispatchService::class);
                        $rule = $dispatch->resolveRuleForGroup($record);
                        if ($rule === null || ! ($rule['cache_enabled'] ?? false)) {
                            return '—';
                        }

                        $series = $record->series;
                        $total = $series->flatMap(fn ($s) => $s->episodes)->count();
                        if ($total === 0) {
                            return '0/0';
                        }

                        $quality = $dispatch->resolveQuality($rule);
                        $fingerprints = [];
                        foreach ($series as $s) {
                            $seriesTmdbId = $s->tmdb_id !== null ? (string) $s->tmdb_id : null;
                            foreach ($s->episodes as $episode) {
                                $fingerprints[] = CachedContentFile::fingerprintFor([
                                    'content_type' => 'episode',
                                    'tmdb_id' => $seriesTmdbId,
                                    'season_number' => $episode->season,
                                    'episode_number' => $episode->episode_number,
                                    'quality' => $quality,
                                ]);
                            }
                        }

                        if (empty($fingerprints)) {
                            return '0/0';
                        }

                        $cached = CachedContentFile::query()
                            ->whereIn('content_fingerprint', $fingerprints)
                            ->where('status', CachedContentFileStatus::Completed)
                            ->count();

                        return "{$cached}/{$total}";
                    }),
                TextColumn::make('last_synced_at')
                    ->since()
                    ->placeholder(__('Never'))
                    ->sortable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('view')
                        ->label(__('View'))
                        ->icon('heroicon-o-eye')
                        ->url(fn (DynamicGroup $record): string => DynamicGroupResource::getUrl('view', ['record' => $record])),
                    $this->getEditRuleAction(),
                    DeleteAction::make(),
                ]),
            ], RecordActionsPosition::BeforeCells)
            ->bulkActions([
                BulkAction::make('cache_now')
                    ->label(__('Cache Now'))
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading(__('Cache selected dynamic groups'))
                    ->modalDescription(__('Queue downloads for every selected Dynamic Group now? Existing cached files are reused via fingerprint dedup; new jobs appear in the Dynamic Group Cache Activity widget on the Categories page.'))
                    ->modalSubmitActionLabel(__('Yes, cache now'))
                    ->action(function (Collection $records): void {
                        $settings = app(GeneralSettings::class);
                        if (! $settings->enable_dynamic_group_cache) {
                            Notification::make()
                                ->warning()
                                ->title(__('Dynamic Group Caching is disabled'))
                                ->body(__('Enable it in Preferences → Dynamic Groups before queueing cache downloads.'))
                                ->duration(10000)
                                ->send();

                            return;
                        }

                        $result = app(DynamicGroupCacheDispatchService::class)->dispatchForGroups($records);

                        if ($result['dispatched'] === 0) {
                            Notification::make()
                                ->info()
                                ->title(__('No cache jobs queued'))
                                ->body(__('Processed :processed of :total groups.', [
                                    'processed' => $result['groups_processed'],
                                    'total' => $result['groups_total'],
                                ]))
                                ->send();

                            return;
                        }

                        $title = $result['dispatched'] === 1
                            ? __('Dispatched 1 cache job.')
                            : __('Dispatched :count cache jobs.', ['count' => $result['dispatched']]);

                        Notification::make()
                            ->success()
                            ->title($title)
                            ->body(__('Processed :processed of :total groups. Track progress in the Dynamic Group Cache Activity widget below.', [
                                'processed' => $result['groups_processed'],
                                'total' => $result['groups_total'],
                            ]))
                            ->send();
                    }),
            ])
            ->emptyStateHeading(__('No Dynamic Groups configured'))
            ->emptyStateDescription(__('Add Dynamic Groups in the Playlist form → Processing → Dynamic Groups (TMDB) section. Synced TMDB lists appear here with their current member counts.'))
            ->emptyStateIcon('heroicon-o-sparkles');
    }

    /**
     * Edit-action for the row's 3-dot menu. The DynamicGroup row is a
     * materialized projection of a rule on the parent playlist's
     * `dynamic_groups_config`, keyed by the (type, source, name)
     * triple — so editing the row means editing that rule.
     *
     * The triple is locked (disabled) in the form: changing type/source/
     * name would silently mismatch the row's identity. To "rename" a
     * group, delete the row and add a new one. Everything else
     * (enabled toggle, tmdb_params.*, cache_*) is editable and a
     * SyncDynamicGroups re-run is queued on save so membership and
     * cache state pick up the new params.
     */
    protected function getEditRuleAction(): Action
    {
        return Action::make('edit')
            ->label(__('Edit'))
            ->icon('heroicon-o-pencil-square')
            ->slideOver()
            ->modalHeading(fn (DynamicGroup $record): string => $record->type === 'vod'
                ? __('Edit Dynamic Group')
                : __('Edit Dynamic Category'))
            ->modalSubmitActionLabel(__('Save changes'))
            ->schema($this->getEditRuleSchema())
            ->fillForm(fn (DynamicGroup $record): array => $this->loadRuleForRow($record))
            ->action(function (array $data, DynamicGroup $record): void {
                $playlist = $record->playlist;
                if (! $playlist) {
                    Notification::make()
                        ->danger()
                        ->title(__('Playlist not found'))
                        ->body(__('The parent playlist for this Dynamic Group no longer exists.'))
                        ->send();

                    return;
                }

                $config = $playlist->dynamic_groups_config ?? [];
                $index = $this->findRuleIndex($playlist, $record);

                if ($index === null) {
                    Notification::make()
                        ->danger()
                        ->title(__('Rule not found'))
                        ->body(__("Could not locate this Dynamic Group's rule on the parent playlist. The row may have been orphaned."))
                        ->send();

                    return;
                }

                // Preserve the locked identity fields — even though
                // the form disables them, defend against a tampered
                // POST.
                $data['type'] = $record->type;
                $data['source'] = $record->source;
                $data['name'] = $record->name;

                $config[$index] = collect($data)
                    ->only([
                        'enabled', 'type', 'source', 'name', 'tmdb_params',
                        'cache_enabled',
                        'cache_content_selection', 'cache_content_days',
                        'cache_retention_mode', 'cache_retention_extra_days',
                        'cache_selected_content_ids',
                        'cache_location_override',
                        'cache_prefer_quality_keyword', 'cache_avoid_duplicate_content',
                    ])
                    ->all();

                $playlist->update(['dynamic_groups_config' => array_values($config)]);

                // Re-sync the playlist so the rule's enabled state +
                // tmdb_params take effect (and disabled rules get
                // their rows cleaned up by SyncDynamicGroups).
                SyncDynamicGroups::dispatch($playlist->id);

                Notification::make()
                    ->success()
                    ->title(__('Dynamic Group updated'))
                    ->body(__('Membership and cache state will refresh in the background.'))
                    ->send();
            });
    }

    /**
     * Edit schema = the same Dynamic Groups rule schema the header
     * CreateAction uses, with the identity triple (type, source,
     * name) locked. Re-uses the canonical schema definition so the
     * edit form stays in lockstep with the create form when fields
     * are added/removed upstream.
     *
     * @return array<int, Component>
     */
    protected function getEditRuleSchema(): array
    {
        return collect(PlaylistResource::getDynamicGroupRuleSchema())
            ->map(function ($component) {
                if (! method_exists($component, 'getName')) {
                    return $component;
                }

                $name = $component->getName();

                // In edit mode, the rule's identity triple is locked:
                // changing type/source/name would silently mismatch the
                // materialized row's (type, source, name) key. Render
                // them disabled so the user can't edit them, and drop
                // the ->live() on `type` so its afterStateUpdated()
                // doesn't fire on initial mount and clobber the form
                // state filled from the rule (most visibly: the
                // 'enabled' toggle's value).
                if ($name === 'type') {
                    return $component->disabled()->live(false);
                }

                if (in_array($name, ['source', 'name'], true)) {
                    return $component->disabled();
                }

                return $component;
            })
            ->all();
    }

    /**
     * Pull the current rule array out of the parent playlist's
     * dynamic_groups_config, matched by (type, source, name) triple.
     * Returns an empty array when the rule can't be found — the
     * action surfaces a "Rule not found" notification on save.
     *
     * @return array<string, mixed>
     */
    protected function loadRuleForRow(DynamicGroup $record): array
    {
        $playlist = $record->playlist;
        if (! $playlist) {
            return [];
        }

        $index = $this->findRuleIndex($playlist, $record);
        if ($index === null) {
            return [];
        }

        $rule = $playlist->dynamic_groups_config[$index] ?? [];

        // Ensure 'enabled' is always present and bool. Rules created
        // before the toggle was added to the schema may lack the key
        // entirely; default to true to match the DynamicGroup row's
        // `enabled` column (forced true by SyncDynamicGroups::materializeRule)
        // and the Create action's default.
        $rule['enabled'] = (bool) ($rule['enabled'] ?? true);

        return $rule;
    }

    /**
     * Locate the index of the row's rule inside the playlist's
     * dynamic_groups_config. Matches on the (type, source, name)
     * triple, which is the canonical SyncDynamicGroups identity
     * key — see SyncDynamicGroups::handle().
     */
    protected function findRuleIndex(Playlist $playlist, DynamicGroup $record): ?int
    {
        $config = $playlist->dynamic_groups_config ?? [];

        foreach ($config as $index => $rule) {
            if (! is_array($rule)) {
                continue;
            }
            if (($rule['type'] ?? null) !== $record->type) {
                continue;
            }
            if (($rule['source'] ?? null) !== $record->source) {
                continue;
            }
            if (trim((string) ($rule['name'] ?? '')) !== $record->name) {
                continue;
            }

            return $index;
        }

        return null;
    }
}
