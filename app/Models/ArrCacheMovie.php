<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A Radarr movie dynamic-group auto-cache added to an integration with
 * "Remove after leaving dynamic groups" on. ArrCacheCleanupService removes
 * it once it has been out of every group since `left_at` for the keep
 * days. Without a row, a movie is never removed.
 */
class ArrCacheMovie extends Model
{
    use HasFactory;

    protected $fillable = [
        'arr_integration_id',
        'tmdb_id',
        'arr_movie_id',
        'left_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tmdb_id' => 'integer',
            'arr_movie_id' => 'integer',
            'left_at' => 'datetime',
        ];
    }

    /**
     * Stop tracking a movie in any of the user's Radarrs so cleanup never
     * removes it, like CachedContentFile::keep(). Used by Cache Now and
     * Never expire rules.
     */
    public static function keep(int $userId, int $tmdbId): void
    {
        static::query()
            ->where('tmdb_id', $tmdbId)
            ->ownedBy($userId)
            ->delete();
    }

    /**
     * Whether dynamic-group caching added this movie to one of the user's
     * Radarrs that will remove it again. The user's TMDB ids load once per
     * request, so a table page costs one query.
     */
    public static function isTracked(int $userId, int $tmdbId): bool
    {
        if ($tmdbId <= 0) {
            return false;
        }

        $trackedTmdbIds = once(fn (): array => static::query()
            ->ownedBy($userId)
            ->awaitingCleanup()
            ->pluck('tmdb_id')
            ->flip()
            ->all());

        return isset($trackedTmdbIds[$tmdbId]);
    }

    /**
     * Whether the user can see any tracked movie (admins see every user's).
     */
    public static function anyVisibleTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return once(fn (): bool => static::query()
            ->awaitingCleanup()
            ->when(! $user->isAdmin(), fn (Builder $query) => $query->ownedBy($user->id))
            ->exists());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->whereIn('arr_integration_id', ArrIntegration::query()->where('user_id', $userId)->select('id'));
    }

    /**
     * Rows ArrCacheCleanupService acts on: its integration is enabled, used
     * for caching, and has "Remove after leaving dynamic groups" on.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAwaitingCleanup(Builder $query): Builder
    {
        return $query->whereIn('arr_integration_id', ArrIntegration::query()
            ->enabled()
            ->cacheEnabled()
            ->where('cache_cleanup', true)
            ->select('id'));
    }
}
