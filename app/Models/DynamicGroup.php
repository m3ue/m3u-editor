<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;

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
    use HasFactory;

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

    protected static function booted(): void
    {
        static::deleted(function (DynamicGroup $group): void {
            $group->removeRuleFromPlaylistConfig();
        });
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Enabled, already-materialized groups owned by $userId on a playlist they also own.
     */
    public function scopePublishableBy(Builder $query, int $userId): Builder
    {
        return $query
            ->where('user_id', $userId)
            ->where('enabled', true)
            ->whereNotNull('last_synced_at')
            ->whereHas('playlist', fn (Builder $playlistQuery) => $playlistQuery->where('user_id', $userId));
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
     * Query for the playlist items a rule's TMDB id set matches — VOD
     * channels when $type is 'vod', otherwise series. Shared by the
     * SyncDynamicGroups membership writer and the playlist form's per-rule
     * preview action so both resolve membership identically.
     *
     * @param  array<int, string>  $tmdbIds
     */
    public static function itemsMatchingTmdbIds(string $type, int $playlistId, array $tmdbIds): Builder
    {
        if ($type === 'vod') {
            return Channel::query()
                ->where('playlist_id', $playlistId)
                ->where('is_vod', true)
                ->whereIn('tmdb_id', $tmdbIds);
        }

        return Series::query()
            ->where('playlist_id', $playlistId)
            ->whereIn('tmdb_id', $tmdbIds);
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

    /**
     * Whether a playlist's `dynamic_groups_config` holds at least one enabled
     * rule. Shared by SyncDynamicGroups (pipeline phase) and the
     * app:refresh-dynamic-groups command so the "does this playlist have any
     * dynamic-group work to do" check stays defined in one place.
     *
     * @param  array<int, array<string, mixed>>|null  $config
     */
    public static function configHasEnabledRule(?array $config): bool
    {
        return collect($config ?? [])
            ->contains(fn (array $rule): bool => (bool) ($rule['enabled'] ?? false));
    }

    /**
     * Normalized (type, source, name) identity for a `dynamic_groups_config`
     * rule. SyncDynamicGroups keys DynamicGroup rows to rules by this triple,
     * so any code matching a row back to its rule must use the same
     * normalization or the two drift apart (a deleted group would then be
     * recreated on the next sync, see issue #1550).
     *
     * @param  array<string, mixed>  $rule
     * @return array{type: string, source: string, name: string}
     */
    public static function ruleIdentity(array $rule): array
    {
        return [
            'type' => (string) ($rule['type'] ?? ''),
            'source' => (string) ($rule['source'] ?? ''),
            'name' => trim((string) ($rule['name'] ?? '')),
        ];
    }

    /**
     * Whether a `dynamic_groups_config` rule is the one this row was
     * materialized from.
     *
     * @param  array<string, mixed>  $rule
     */
    public function matchesRule(array $rule): bool
    {
        return self::ruleIdentity($rule) === self::ruleIdentity($this->only(['type', 'source', 'name']));
    }

    /**
     * Wrap the delete in a transaction so the row delete and the
     * `deleted` hook's rule removal commit together. Without it, a failed
     * playlist save would leave the rule behind and the next sync would
     * recreate the group.
     */
    public function delete(): ?bool
    {
        return DB::transaction(fn (): ?bool => parent::delete());
    }

    /**
     * Strip every rule in the owning playlist's `dynamic_groups_config` that
     * matches this row (see ruleIdentity()), then persist quietly. Called
     * from the model's `deleted` hook so the three Filament delete surfaces
     * (VOD / Series listing DeleteActions, View page DeleteAction) and any
     * future bulk delete stay in lockstep with SyncDynamicGroups, which
     * would otherwise recreate the deleted row on the next sync.
     *
     * Only model-level deletes reach this hook. SyncDynamicGroups' stale-row
     * cleanup deliberately uses a query-builder delete so that disabling a
     * rule (or syncing while TMDB is unconfigured) never strips rules here.
     *
     * `saveQuietly()` is intentional: Playlist::updated in AppServiceProvider
     * dispatches PlaylistUpdated, which fans out the user's "updated"
     * post-processes (webhooks/scripts) and re-syncs the primary profile.
     * Deleting a derived group is not a playlist edit and must not trigger
     * either side effect.
     */
    public function removeRuleFromPlaylistConfig(): void
    {
        $playlist = $this->playlist;
        if ($playlist === null) {
            return;
        }

        $config = $playlist->dynamic_groups_config;
        if ($config === null) {
            return;
        }

        $filtered = array_values(array_filter(
            $config,
            fn (array $rule): bool => ! $this->matchesRule($rule),
        ));

        if (count($filtered) === count($config)) {
            return;
        }

        $playlist->dynamic_groups_config = $filtered;
        $playlist->saveQuietly();
    }
}
