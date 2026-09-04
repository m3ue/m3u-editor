<?php

namespace App\Filament\Resources\DynamicGroups;

use App\Filament\Resources\DynamicGroups\RelationManagers\ChannelsRelationManager;
use App\Filament\Resources\DynamicGroups\RelationManagers\SeriesRelationManager;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Models\DynamicGroup;
use App\Services\TmdbService;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
    public static function formatTmdbParams(array $params): string
    {
        if (empty($params)) {
            return '-';
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

            $lines[] = $key.' = '.$resolved;
        }

        return implode("\n", $lines);
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
                ->collapsible()
                ->collapsed()
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('playlist.source_type')
                        ->label(__('Source Type')),
                    TextEntry::make('type')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            'vod' => __('VOD'),
                            'series' => __('Series'),
                            default => $state,
                        }),
                    TextEntry::make('playlist.name'),
                    TextEntry::make('source')
                        ->formatStateUsing(function (DynamicGroup $record): string {
                            return static::sourceLabelFor($record->type)[$record->source] ?? $record->source;
                        }),
                    TextEntry::make('tmdb_params')
                        ->formatStateUsing(function (DynamicGroup $record): string {
                            return static::formatTmdbParams((array) ($record->tmdb_params ?? []));
                        })
                        ->placeholder('-'),
                    TextEntry::make('sort_order'),
                    TextEntry::make('enabled')
                        ->badge()
                        ->formatStateUsing(fn (bool $state): string => $state ? __('Enabled') : __('Disabled'))
                        ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                    // Inline timestamp + diff chips + click-to-expand list of
                    // titles. State is forced to null because the partial
                    // reads $getRecord() and queries snapshots directly.
                    ViewEntry::make('last_sync_diff')
                        ->label(__('Last synced'))
                        ->state(null)
                        ->view('filament.partials.last-sync-diff'),
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
