<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'new' => 'boolean',
        'source_season_id' => 'integer',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
        'category_id' => 'integer',
        'series_id' => 'integer',
        'season_number' => 'integer',
        'episode_count' => 'integer',
        'metadata' => 'array',
        'is_custom' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function serie(): BelongsTo
    {
        return $this->belongsTo(Series::class, 'series_id');
    }

    public function series(): BelongsTo
    {
        return $this->serie();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    public function scopeForSerie(Builder $query, int $serieId): Builder
    {
        return $query->where('series_id', $serieId);
    }

    /**
     * Fall back to the parent series' cover when the provider didn't send
     * season-specific artwork (e.g. a season missing from its "seasons" list).
     */
    protected function cover(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ?? $this->serie?->cover,
        );
    }

    protected function coverBig(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ?? $this->serie?->cover,
        );
    }
}
