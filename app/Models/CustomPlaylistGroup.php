<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A group a custom playlist's content is delivered under: either a custom group tag
 * assigned to the content, or the provider group/category name that untagged content
 * falls back to. Series categories are the same idea on the series side.
 *
 * This model has no table of its own. CustomPlaylist builds its rows with a union
 * subquery so both kinds of group can be listed, searched and paginated in a single
 * Filament table. Records are keyed by name, which is also what a playlist alias
 * channel filter stores, so no id/name translation is needed when selecting them.
 */
class CustomPlaylistGroup extends Model
{
    protected $table = 'custom_playlist_groups';

    /** Read-only: rows only ever come from the union subquery, never from mass assignment. */
    protected $fillable = [];

    protected $primaryKey = 'name';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Wrap a union of name-producing subqueries as a queryable set of groups.
     */
    public static function fromNameUnion(\Illuminate\Database\Query\Builder $names): Builder
    {
        return static::query()
            ->fromSub($names, 'custom_playlist_groups')
            ->whereNotNull('name');
    }

    /**
     * An empty set, for when no custom playlist has been selected yet.
     *
     * Selects an empty string rather than null so the derived column has a definite text
     * type — PostgreSQL will not always infer one for an untyped null in a subquery.
     */
    public static function none(): Builder
    {
        return static::fromNameUnion(
            DB::query()->selectRaw("'' as name")->whereRaw('1 = 0')
        );
    }
}
