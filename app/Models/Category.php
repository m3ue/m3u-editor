<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::deleted(function (Category $category): void {
            // When a category is deleted, strip its ID from the source playlist's
            // auto-sync rules so the saved config stays valid on next playlist save.
            $category->playlist?->pruneAutoSyncGroupIds([$category->id], 'series_categories');
        });
    }

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'source_category_id' => 'integer',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function streamFileSetting(): BelongsTo
    {
        return $this->belongsTo(StreamFileSetting::class);
    }

    public function series(): HasMany
    {
        return $this->hasMany(Series::class);
    }

    public function enabled_series()
    {
        return $this->hasMany(Series::class)->where('enabled', true);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }
}
