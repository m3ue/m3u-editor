<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use InvalidArgumentException;

class Category extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Category $category): void {
            $category->assertMergedCategoryRulesHold();
        });

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
        'parent_id' => 'integer',
        'is_merged' => 'boolean',
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

    /**
     * The merged category this category has been folded into, if any. When set,
     * playlist output substitutes the parent's identity for this category's.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Child categories folded into this merged category.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Series belonging to any child category folded into this merged category.
     */
    public function descendantSeries(): HasManyThrough
    {
        return $this->hasManyThrough(Series::class, self::class, 'parent_id', 'category_id', 'id', 'id');
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

    /**
     * The category id playlist output should attribute this category's series to:
     * the merged parent when folded in, otherwise this category.
     */
    protected function effectiveId(): Attribute
    {
        return Attribute::get(fn (): int => $this->parent_id ?? $this->id);
    }

    /**
     * The category name playlist output should display for this category's series.
     */
    protected function effectiveName(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->parent?->name ?? $this->name);
    }

    /**
     * The sort order playlist output should rank this category's series by.
     */
    protected function effectiveSortOrder(): Attribute
    {
        return Attribute::get(fn () => $this->parent?->sort_order ?? $this->sort_order);
    }

    /**
     * Categories that a series may be assigned to. Merged categories are containers
     * only and must never be selectable as an assignment target.
     */
    public function scopeAssignableTarget(Builder $query): Builder
    {
        return $query->where('is_merged', false);
    }

    /**
     * Guard the merged-category invariants on every save: a merged category cannot
     * itself be nested, and a parent must be a merged category in the same playlist.
     */
    protected function assertMergedCategoryRulesHold(): void
    {
        if ($this->is_merged && $this->parent_id !== null) {
            throw new InvalidArgumentException('A merged category cannot be nested inside another category.');
        }

        if ($this->parent_id === null) {
            return;
        }

        $parent = self::query()->find($this->parent_id);

        if (! $parent || ! $parent->is_merged) {
            throw new InvalidArgumentException('A category can only be folded into a merged category.');
        }

        if ($parent->playlist_id !== $this->playlist_id) {
            throw new InvalidArgumentException('A merged category must belong to the same playlist as its children.');
        }
    }
}
