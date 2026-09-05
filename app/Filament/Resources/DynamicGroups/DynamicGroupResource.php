<?php

namespace App\Filament\Resources\DynamicGroups;

use App\Filament\Resources\DynamicGroups\RelationManagers\ChannelsRelationManager;
use App\Filament\Resources\DynamicGroups\RelationManagers\SeriesRelationManager;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Resources\Series\SeriesResource;
use App\Filament\Resources\Vods\VodResource;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\DynamicGroupItemSnapshot;
use App\Models\Series;
use App\Models\SyncRun;
use App\Services\TmdbService;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Read-only Filament resource for a DynamicGroup row. Rule config lives on
 * the Playlist form's Dynamic Groups (TMDB) repeater, not here. Only the
 * `view` route is registered — the `index` page is intentionally absent,
 * see Pages\ViewDynamicGroup for the breadcrumb rationale.
 */
class DynamicGroupResource extends Resource
{
    protected static ?string $model = DynamicGroup::class;

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Always hide from Filament's nav and global search — drill-in is only
     * reachable from the per-playlist widgets, never as a top-level entry.
     * Access is intentionally permissive so the view route resolves, just
     * not advertised.
     */
    public static function canAccess(): bool
    {
        return true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /**
     * Source slugs → human labels. Must stay in sync with the `Select::make('source')`
     * options in `PlaylistResource.php:1958-1974` (the Playlist form's Dynamic
     * Groups (TMDB) section). Used by the per-playlist widgets to render the
     * source badge.
     *
     * @return array<string, string>
     */
    public static function sourceLabelFor(string $type): array
    {
        $vod = [
            'trending' => __('Trending'),
            'popular' => __('Popular'),
            'now_playing' => __('In Theatres'),
            'upcoming' => __('Coming Soon'),
            'top_genre' => __('Top Genre'),
            'provider' => __('By Streaming Service'),
        ];
        $series = [
            'trending' => __('Trending'),
            'popular' => __('Popular'),
            'top_genre' => __('Top Genre'),
            'tmdb_network' => __('By TV Network'),
            'provider' => __('By Streaming Service'),
        ];

        return $type === 'series' ? $series : $vod;
    }

    /**
     * Render `tmdb_params` (array on the DynamicGroup row) into a human-readable
     * key=value list. Resolves canonical IDs to names when a name can be sourced
     * cheaply (TV_NETWORKS is a local constant; genre/provider IDs require a TMDB
     * HTTP lookup and are left as the raw ID).
     */
    public static function formatTmdbParams(array $params): array
    {
        if (empty($params)) {
            return [];
        }

        $lines = [];
        foreach ($params as $key => $value) {
            $resolved = is_array($value)
                ? implode(', ', array_map(fn ($v) => (string) $v, $value))
                : (string) $value;

            if ($key === 'network_id' && is_numeric($value)) {
                $networkName = TmdbService::TV_NETWORKS[(int) $value] ?? null;
                if ($networkName) {
                    $resolved = $networkName.' ('.$value.')';
                }
            }

            $lines[$key] = ! empty($resolved) ? $resolved : '-';
        }

        return $lines;
    }

    /**
     * Per-record cache of `computeSyncDiff()`, so the several infolist
     * entries that each read a slice of the "last sync diff" (timestamp,
     * +N/-N badges, added/removed title lists) share one set of queries
     * instead of recomputing it per entry. Keyed by the record object itself
     * via WeakMap rather than by id - a plain id-keyed array leaks across
     * requests sharing one PHP process (Octane, or the test suite, where a
     * fresh record can reuse a truncated table's previous id) because the
     * class-static array outlives the request. WeakMap entries are scoped to
     * the record instance's own lifetime, so a new page load's fresh model
     * instance can never read another request's cached diff.
     *
     * @var \WeakMap<DynamicGroup, array{last_run: SyncRun|null, has_previous: bool, added_count: int, removed_count: int, added_titles: array<int, string>, removed_titles: array<int, string>}>
     */
    protected static \WeakMap $syncDiffCache;

    /**
     * @return array{last_run: SyncRun|null, has_previous: bool, added_count: int, removed_count: int, added_titles: array<int, string>, removed_titles: array<int, string>}
     */
    public static function getSyncDiffFor(DynamicGroup $record): array
    {
        static::$syncDiffCache ??= new \WeakMap;

        return static::$syncDiffCache[$record] ??= static::computeSyncDiff($record);
    }

    /**
     * @return array{last_run: SyncRun|null, has_previous: bool, added_count: int, removed_count: int, added_items: array<int, array{title: string, url: string|null}>, removed_items: array<int, array{title: string, url: string|null}>}
     */
    protected static function computeSyncDiff(DynamicGroup $record): array
    {
        $none = ['last_run' => null, 'has_previous' => false, 'added_count' => 0, 'removed_count' => 0, 'added_items' => [], 'removed_items' => []];

        $latestRunId = DynamicGroupItemSnapshot::query()
            ->where('dynamic_group_id', $record->id)
            ->whereNotNull('sync_run_id')
            ->max('sync_run_id');

        if ($latestRunId === null) {
            return $none;
        }

        $lastRun = SyncRun::query()->visibleTo(auth()->user())->find($latestRunId);

        if ($lastRun === null) {
            return $none;
        }

        $diff = DynamicGroupItemSnapshot::diffForRun($record->id, $latestRunId);

        // Only the ids we're actually going to render (first 50 per side)
        // need their type resolved - caps the lookup query even if the
        // underlying diff is huge.
        $shownAdded = $diff['added']->take(50);
        $shownRemoved = $diff['removed']->take(50);

        // `itemsForRun($latestRunId)` alone only knows the type of ids in the
        // *current* snapshot - "removed" ids only ever appear in the
        // *previous* run's snapshot, so that lookup always missed for them
        // (title/url silently fell back to null - a plain "#id"). item_type
        // doesn't vary per item_id (a Channel row is always a Channel), so
        // query every snapshot row for just the shown ids, regardless of
        // which run they were captured in.
        $itemTypes = DynamicGroupItemSnapshot::query()
            ->where('dynamic_group_id', $record->id)
            ->whereIn('item_id', $shownAdded->merge($shownRemoved)->unique())
            ->get(['item_id', 'item_type'])
            ->keyBy('item_id')
            ->map(fn ($row): string => $row->item_type);

        // Resolve titles (and, where the underlying row still exists, a link
        // to it) for the shown ids - two batched whereIn() lookups per side
        // instead of one query per item.
        $resolveItems = function (Collection $ids, Collection $shown) use ($itemTypes): array {
            $channelIds = $shown->filter(fn ($id): bool => ($itemTypes[$id] ?? null) === Channel::class)->values();
            $seriesIds = $shown->filter(fn ($id): bool => ($itemTypes[$id] ?? null) === Series::class)->values();

            $channelTitles = $channelIds->isNotEmpty()
                ? Channel::query()->whereIn('id', $channelIds)->pluck('title', 'id')
                : collect();
            $seriesTitles = $seriesIds->isNotEmpty()
                ? Series::query()->whereIn('id', $seriesIds)->pluck('name', 'id')
                : collect();

            $items = $shown->map(function ($id) use ($itemTypes, $channelTitles, $seriesTitles): array {
                $type = $itemTypes[$id] ?? null;
                $title = match ($type) {
                    Channel::class => $channelTitles[$id] ?? null,
                    Series::class => $seriesTitles[$id] ?? null,
                    default => null,
                };

                // No link when the title lookup came back empty - the row
                // was deleted since this snapshot was captured, so a link
                // would just 404.
                $url = match (true) {
                    $title === null => null,
                    $type === Channel::class => VodResource::getUrl('view', ['record' => $id]),
                    $type === Series::class => SeriesResource::getUrl('view', ['record' => $id]),
                    default => null,
                };

                return ['title' => $title ?: '#'.$id, 'url' => $url];
            })->all();

            if ($ids->count() > 50) {
                $items[] = ['title' => __('and :count more…', ['count' => $ids->count() - 50]), 'url' => null];
            }

            return $items;
        };

        return [
            'last_run' => $lastRun,
            'has_previous' => $diff['has_previous'],
            'added_count' => $diff['added']->count(),
            'removed_count' => $diff['removed']->count(),
            'added_items' => $resolveItems($diff['added'], $shownAdded),
            'removed_items' => $resolveItems($diff['removed'], $shownRemoved),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withCount(['channels', 'series'])
            // The View page's infolist only ever reads `playlist.name` and
            // `playlist.source_type` - scope the eager load to just those
            // columns so viewing/searching a DynamicGroup never pulls a full
            // Playlist row. Avoids being on the hook for anything a future
            // Playlist accessor/attribute might do (e.g. `xtream_status`,
            // which dispatches a stats-refresh job on a cache miss) - this
            // page has no business touching Playlist beyond its name/type.
            ->with(['playlist:id,name,source_type']);

        // Per this repo's convention (see CLAUDE.md "Scope both `getEloquentQuery`
        // and `getGlobalSearchEloquentQuery()`"), admins see every user's rows,
        // non-admins see only their own. Mirror DvrRecordingResource:41-49 exactly
        // because the trait-based HasUserFiltering doesn't compose with this
        // resource's `withCount()` override.
        if (auth()->check() && ! auth()->user()->isAdmin()) {
            $query->where('dynamic_groups.user_id', auth()->id());
        }

        return $query;
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        $query = parent::getGlobalSearchEloquentQuery()
            ->withCount(['channels', 'series'])
            ->with(['playlist:id,name,source_type']);

        if (auth()->check() && ! auth()->user()->isAdmin()) {
            $query->where('dynamic_groups.user_id', auth()->id());
        }

        return $query;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Details'))
                ->columns(2)
                ->compact()
                ->collapsible()
                ->collapsed()
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('type')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            'vod' => __('VOD'),
                            'series' => __('Series'),
                            default => $state,
                        }),
                    TextEntry::make('playlist.name')
                        ->label(__('Playlist'))
                        ->badge(),
                    TextEntry::make('source')
                        ->label(__('Source'))
                        ->formatStateUsing(function (DynamicGroup $record): string {
                            return static::sourceLabelFor($record->type)[$record->source] ?? $record->source;
                        })
                        ->badge(),
                    TextEntry::make('last_synced_at')
                        ->label(__('Last synced'))
                        ->state(fn (DynamicGroup $record): ?string => (static::getSyncDiffFor($record)['last_run']?->started_at ?? $record->last_synced_at)?->diffForHumans())
                        ->placeholder(__('Never')),
                    TextEntry::make('sync_diff_summary')
                        ->label(__('Since previous sync'))
                        ->badge()
                        ->state(function (DynamicGroup $record): array {
                            $diff = static::getSyncDiffFor($record);

                            if ($diff['last_run'] === null) {
                                return [];
                            }

                            if (! $diff['has_previous']) {
                                return [__('baseline')];
                            }

                            return array_filter([
                                $diff['added_count'] > 0 ? '+'.$diff['added_count'] : null,
                                $diff['removed_count'] > 0 ? '-'.$diff['removed_count'] : null,
                            ]);
                        })
                        ->color(fn (string $state): string => match (true) {
                            str_starts_with($state, '+') => 'success',
                            str_starts_with($state, '-') => 'danger',
                            default => 'gray',
                        })
                        ->placeholder('-'),
                    KeyValueEntry::make('tmdb_params')
                        ->label(__('TMDB Params'))
                        ->columnSpanFull()
                        ->state(fn (DynamicGroup $record): array => static::formatTmdbParams((array) ($record->tmdb_params ?? [])))
                        ->placeholder('-'),
                ]),
            Section::make(__('Added since previous sync'))
                ->compact()
                ->collapsible()
                ->collapsed()
                ->columnSpanFull()
                ->visible(fn (DynamicGroup $record): bool => static::getSyncDiffFor($record)['added_count'] > 0)
                ->schema([
                    TextEntry::make('sync_diff_added')
                        ->hiddenLabel()
                        ->state(fn (DynamicGroup $record): array => static::getSyncDiffFor($record)['added_items'])
                        ->formatStateUsing(fn (array $state): string => $state['title'])
                        ->url(fn (array $state): ?string => $state['url'])
                        ->listWithLineBreaks()
                        ->bulleted(),
                ]),
            Section::make(__('Removed since previous sync'))
                ->compact()
                ->collapsible()
                ->collapsed()
                ->columnSpanFull()
                ->visible(fn (DynamicGroup $record): bool => static::getSyncDiffFor($record)['removed_count'] > 0)
                ->schema([
                    TextEntry::make('sync_diff_removed')
                        ->hiddenLabel()
                        ->state(fn (DynamicGroup $record): array => static::getSyncDiffFor($record)['removed_items'])
                        ->formatStateUsing(fn (array $state): string => $state['title'])
                        ->url(fn (array $state): ?string => $state['url'])
                        ->listWithLineBreaks()
                        ->bulleted(),
                ]),
        ]);
    }

    /**
     * The resource has no `index` page (see class docblock). This exists as
     * a defensive fallback for any generic Filament internals that still
     * call `getUrl('index')` / `getIndexUrl()` on this resource directly
     * (e.g. global search's "view all results" link) — without it those
     * would throw a `LogicException`. It is NOT what drives the page's own
     * breadcrumb/back-navigation chain anymore: `Pages\ViewDynamicGroup`
     * overrides `getBreadcrumbs()` and its header actions directly, routing
     * through `VodGroupResource`/`CategoryResource` by type instead.
     *
     * @param  array<mixed>  $parameters
     */
    public static function getIndexUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false): string
    {
        return PlaylistResource::getUrl('index', $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters);
    }

    /**
     * Both managers are registered even though only one tab will render
     * per record (gating is done in `canViewForRecord` on each manager).
     * Filament iterates `getRelations()` and picks the tabs that pass the
     * gate — a vod-type group shows the Movies tab only, a series-type
     * group shows the Series tab only.
     */
    public static function getRelations(): array
    {
        return [
            ChannelsRelationManager::class,
            SeriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'view' => Pages\ViewDynamicGroup::route('/{record}'),
        ];
    }
}
