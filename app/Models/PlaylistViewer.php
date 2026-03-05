<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PlaylistViewer extends Model
{
    use HasFactory;

    protected $casts = [
        'is_admin' => 'boolean',
    ];

    public function viewerable(): MorphTo
    {
        return $this->morphTo();
    }

    public function watchProgress(): HasMany
    {
        return $this->hasMany(ViewerWatchProgress::class);
    }

    public function playlistAuth(): BelongsTo
    {
        return $this->belongsTo(PlaylistAuth::class);
    }

    public function scopeAdmin($query)
    {
        return $query->where('is_admin', true);
    }

    public function scopeForViewerable($query, string $type, int $id)
    {
        return $query->where('viewerable_type', $type)->where('viewerable_id', $id);
    }
}
