<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NetworkProgramme extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'network_id' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'duration_seconds' => 'integer',
    ];

    /**
     * Get the network this programme belongs to.
     */
    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    /**
     * Get the content item (Episode or Channel/VOD).
     */
    public function contentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Check if this programme is currently airing.
     */
    public function isCurrentlyAiring(): bool
    {
        $now = Carbon::now();

        return $now->between($this->start_time, $this->end_time);
    }

    /**
     * Get the stream URL for this programme's content.
     */
    public function getStreamUrl(): ?string
    {
        $content = $this->contentable;

        if (! $content) {
            return null;
        }

        return $content->url ?? null;
    }

    /**
     * Calculate the current offset into this programme in seconds.
     * Returns 0 if programme hasn't started yet.
     */
    public function getCurrentOffsetSeconds(): int
    {
        $now = Carbon::now();

        if ($now->lt($this->start_time)) {
            return 0;
        }

        if ($now->gt($this->end_time)) {
            return $this->duration_seconds;
        }

        return (int) $this->start_time->diffInSeconds($now);
    }
}
