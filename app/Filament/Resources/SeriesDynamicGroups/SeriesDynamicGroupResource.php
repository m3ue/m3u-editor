<?php

namespace App\Filament\Resources\SeriesDynamicGroups;

use App\Filament\Resources\DynamicGroups\DynamicGroupResource;
use App\Filament\Resources\SeriesDynamicGroups\Pages\ListSeriesDynamicGroups;
use App\Models\DynamicGroup;
use App\Services\TmdbService;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Per-Series listing surface for DynamicGroup rows.
 *
 * Sister resource to `VodDynamicGroupResource`. Same model, scoped
 * `type = 'series'` filter, lives under the existing Series nav group
 * so the user gets "Dynamic Groups listing under Series" as a sibling
 * of Categories and Series itself.
 *
 * The per-row view route is on the shared parent
 * `DynamicGroupResource` — the single detail page that both listings
 * link into via their view action.
 */
class SeriesDynamicGroupResource extends Resource
{
    /**
     * `cached_content_files.content_type` value for this
     * widget's dynamic group rows. Used by the page-level tab
     * counter + by the widget itself to scope its query/canView().
     */
    public const CONTENT_TYPE = 'episode';

    protected static ?string $model = DynamicGroup::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $label = 'Series Dynamic Group';

    protected static ?string $pluralLabel = 'Dynamic Groups';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return true;
    }

    /**
     * Same experimental-feature + TMDB-configured gate as the VOD
     * sibling. Mirrors `VodDynamicGroupResource::shouldRegisterNavigation()`
     * so both nav items appear/disappear together as the feature
     * flag or TMDB key flips.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('feature.playlist_tmdb_dynamic_groups')
            && app(TmdbService::class)->isConfigured();
    }

    /**
     * Sit under the existing Series section so CJ's stated "dynamic
     * groups listing under Series" requirement maps directly.
     */
    public static function getNavigationGroup(): ?string
    {
        return __('Series');
    }

    /**
     * Sits ABOVE Categories (sort 4) and Series (sort 4) within the
     * Series group — Dynamic Groups is the operator's primary
     * destination for "auto-grouped by TMDB" content, so it earns
     * the top slot. Sort = 1 keeps it cleanly above the existing
     * sort-4 items.
     */
    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getModelLabel(): string
    {
        return __('Series Dynamic Group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Dynamic Groups');
    }

    /**
     * Single source of truth for the per-row visibility rule:
     *  - admin sees every series-type DynamicGroup
     *  - non-admin sees only their own series-type DynamicGroup
     *
     * Mirrors `VodDynamicGroupResource::getEloquentQuery()` except for
     * the `type = 'series'` filter. Both resources enforce the same
     * user-scoping rule (admin vs. owner) so an admin can see all
     * rows under either listing.
     */
    public static function getEloquentQuery(): Builder
    {
        // NOTE: do NOT add withCount('series') here. Tab counts are
        // computed from this same query (see ListSeriesDynamicGroups::
        // getTabs()) and groupBy('playlist_id') on a builder that also
        // has a withCount subquery in SELECT fails on Postgres with
        // "column ... must appear in the GROUP BY clause". The table
        // config attaches withCount('series') via modifyQueryUsing so
        // it only applies when the table is actually rendering.
        $query = parent::getEloquentQuery()
            ->where('dynamic_groups.type', 'series');

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
            'index' => ListSeriesDynamicGroups::route('/'),
        ];
    }
}
