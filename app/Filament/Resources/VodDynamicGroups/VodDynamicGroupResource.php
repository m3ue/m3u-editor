<?php

namespace App\Filament\Resources\VodDynamicGroups;

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\VodDynamicGroups\Pages\ListVodDynamicGroups;
use App\Models\DynamicGroup;
use App\Services\TmdbService;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Per-VOD listing surface for DynamicGroup rows.
 *
 * One of the two paired resources created in the "split dynamic groups
 * out of VOD Groups / Series Categories widgets" refactor. The user
 * wanted a Dynamic Groups listing *under* the VOD section in the
 * sidebar and a separate one under Series — so we expose TWO Filament
 * resources backed by the same `DynamicGroup` model, each scoped to
 * one type in `getEloquentQuery()` and surfaced under its respective
 * nav group.
 *
 * The per-row "view" route lives on the shared parent
 * `DynamicGroupResource` (single detail page that both listings link
 * into via their view action).
 */
class VodDynamicGroupResource extends Resource
{
    /**
     * `cached_content_files.content_type` value for this
     * widget's dynamic group rows. Used by the page-level tab
     * counter + by the widget itself to scope its query/canView().
     */
    public const CONTENT_TYPE = 'movie';

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
     * Sidebar gate — same experimental-feature + TMDB-configured check
     * the old footer widgets used. Without TMDB the Sync pipeline is a
     * no-op and the page would render an empty list.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('feature.playlist_tmdb_dynamic_groups')
            && app(TmdbService::class)->isConfigured();
    }

    /**
     * Sit under the existing VOD Channels section so CJ's stated
     * "dynamic groups listing under VOD" requirement maps directly.
     */
    public static function getNavigationGroup(): ?string
    {
        return __('VOD Channels');
    }

    /**
     * Sits ABOVE VOD Groups (sort 2) and VODs (sort 3) within the
     * VOD Channels group — Dynamic Groups is the operator's primary
     * destination for "auto-grouped by TMDB" content, so it earns
     * the top slot. Sort = 1 leaves room for future additions that
     * would also need to appear above the existing items without
     * reshuffling them.
     */
    public static function getNavigationSort(): ?int
    {
        return 1;
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
     * extra `type = 'vod'` filter. We don't need `withCount(['channels'])`
     * here because the index page computes the items count inline
     * (matches the column-level state the old widget used).
     */
    public static function getEloquentQuery(): Builder
    {
        // NOTE: do NOT add withCount('channels') here. Tab counts are
        // computed from this same query (see ListVodDynamicGroups::
        // getTabs()) and groupBy('playlist_id') on a builder that also
        // has a withCount subquery in SELECT fails on Postgres with
        // "column ... must appear in the GROUP BY clause". The table
        // config attaches withCount('channels') via modifyQueryUsing
        // so it only applies when the table is actually rendering.
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
