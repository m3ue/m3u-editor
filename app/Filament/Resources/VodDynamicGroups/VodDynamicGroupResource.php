<?php

namespace App\Filament\Resources\VodDynamicGroups;

use App\Filament\Resources\VodDynamicGroups\Pages\ListVodDynamicGroups;
use App\Models\DynamicGroup;
use App\Services\TmdbService;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Per-VOD listing surface for DynamicGroup rows.
 *
 * Sister resource to `SeriesDynamicGroupResource`. Both are backed by the
 * same `DynamicGroup` model and each scopes its query to one type in
 * `getEloquentQuery()` so a single Filament model can surface under two
 * sidebar sections (VOD Channels / Series). The user drill-in comes from
 * either listing's row action into the shared view-only parent
 * `DynamicGroupResource`.
 *
 * Cache-specific actions and columns are intentionally absent from this
 * navigation-only surface.
 */
class VodDynamicGroupResource extends Resource
{
    protected static ?string $model = DynamicGroup::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $label = 'VOD Dynamic Group';

    protected static ?string $pluralLabel = 'Dynamic Groups';

    public static function canCreate(): bool
    {
        // Rule config lives on the Playlist form's Dynamic Groups (TMDB)
        // repeater; this resource is a list+view surface only.
        return false;
    }

    /**
     * Access intentionally permissive so the view route resolves from
     * the row action; per-user row scoping is handled in
     * `getEloquentQuery()` (admin sees all, non-admin sees only their own).
     */
    public static function canAccess(): bool
    {
        return true;
    }

    /**
     * Sidebar gate - same experimental-feature + TMDB-configured check
     * the old footer widgets used. Without TMDB the Sync pipeline is a
     * no-op and the page would render an empty list.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('feature.playlist_tmdb_dynamic_groups')
            && app(TmdbService::class)->isConfigured();
    }

    /**
     * Sit under the existing VOD Channels section so the stated
     * "dynamic groups listing under VOD" requirement maps directly.
     */
    public static function getNavigationGroup(): ?string
    {
        return __('VOD Channels');
    }

    /**
     * Sits BELOW VOD Groups (sort 2) and VODs (sort 3) within the
     * VOD Channels group. Sort = 4 keeps it in the next available
     * position without changing existing navigation slots.
     */
    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    public static function getModelLabel(): string
    {
        return __('VOD Dynamic Group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Dynamic Groups');
    }

    /**
     * Single source of truth for the per-row visibility rule:
     *  - admin sees every vod-type DynamicGroup
     *  - non-admin sees only their own vod-type DynamicGroup
     *
     * Mirrors `DynamicGroupResource::getEloquentQuery()` except for the
     * extra `type = 'vod'` filter.
     *
     * NOTE: do NOT add withCount('channels') here. Tab counts are
     * computed from this same query (see ListVodDynamicGroups::getTabs())
     * and groupBy('playlist_id') on a builder that also has a withCount
     * subquery in SELECT fails on Postgres with "column ... must appear
     * in the GROUP BY clause". The table config attaches withCount via
     * modifyQueryUsing so it only applies when the table is actually
     * rendering.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->where('dynamic_groups.type', 'vod');

        if (auth()->check() && ! auth()->user()->isAdmin()) {
            $query->where('dynamic_groups.user_id', auth()->id());
        }

        return $query;
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVodDynamicGroups::route('/'),
        ];
    }
}
