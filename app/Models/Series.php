<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Series extends Model
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
        'source_category_id' => 'integer',
        'source_series_id' => 'integer',
        'user_id' => 'integer',
        'playlist_id' => 'integer',
        'category_id' => 'integer',
        'rating_5based' => 'integer',
        'enabled' => 'boolean',
        'backdrop_path' => 'array',
        'metadata' => 'array',
        'sync_settings' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class);
    }

    /**
     * Get the release date attribute with safe parsing
     */
    public function getReleaseDateAttribute($value)
    {
        if (!$value) {
            return null;
        }

        try {
            // Extract just the date part (remove any text after the date)
            if (preg_match('/(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
                return \Carbon\Carbon::parse($matches[1]);
            }

            // Try to parse the full value if it's a valid date
            return \Carbon\Carbon::parse($value);
        } catch (\Exception $e) {
            // If parsing fails, return null or the raw value
            return null;
        }
    }
}
