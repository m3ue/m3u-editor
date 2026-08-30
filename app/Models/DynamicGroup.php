<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Per-playlist virtual group computed from TMDB list endpoints.
 *
 * Membership is tracked in the polymorphic `dynamic_group_items` table so a
 * single Channel/Series can belong to many DynamicGroups at once — distinct
 * from the scalar `group_id`/`category_id` FKs that enforce single-membership
 * elsewhere in the schema.
 *
 * Xtream API exposes these as categories by computing the category id as
 * `XTREAM_CATEGORY_ID_OFFSET + $this->id`. Real `groups`/`categories` rows
 * use plain auto-increment PKs that are far below the offset, so collisions
 * are impossible regardless of how many dynamic groups are created.
 */
class DynamicGroup extends Model
{
    /**
     * Base offset added to the local id to form the Xtream category_id.
     * Real groups/categories PKs are well below 2^31; 9e8 keeps the resulting
     * string-cast id within int32 range so Xtream clients that (int)-cast
     * category_id stay lossless.
     */
    public const XTREAM_CATEGORY_ID_OFFSET = 900_000_000;

    protected $fillable = [
        'playlist_id',
        'user_id',
        'type',
        'source',
        'name',
        'tmdb_params',
        'sort_order',
        'enabled',
        'last_synced_at',
    ];

    protected $casts = [
        'tmdb_params' => 'array',
        'enabled' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * VOD (Channel) members of this dynamic group.
     */
    public function channels(): MorphToMany
    {
        return $this->morphedByMany(Channel::class, 'item', 'dynamic_group_items');
    }

    /**
     * Series members of this dynamic group.
     */
    public function series(): MorphToMany
    {
        return $this->morphedByMany(Series::class, 'item', 'dynamic_group_items');
    }

    /**
     * Numeric Xtream category_id for this row.
     */
    public function xtreamCategoryId(): int
    {
        return self::XTREAM_CATEGORY_ID_OFFSET + (int) $this->id;
    }

    /**
     * Inverse of xtreamCategoryId(). Returns the local DynamicGroup id when
     * the given category id falls inside our reserved offset range, otherwise
     * null (the value belongs to a real group/category row and must not be
     * routed to the dynamic-group pipeline).
     */
    public static function idFromXtreamCategoryId(int|string $categoryId): ?int
    {
        $intId = (int) $categoryId;

        return $intId >= self::XTREAM_CATEGORY_ID_OFFSET
            ? $intId - self::XTREAM_CATEGORY_ID_OFFSET
            : null;
    }
}
