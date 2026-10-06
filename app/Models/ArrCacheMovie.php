<?php

namespace App\Models;

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
            ->whereIn('arr_integration_id', ArrIntegration::query()->where('user_id', $userId)->select('id'))
            ->delete();
    }
}
