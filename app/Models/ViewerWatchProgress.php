<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ViewerWatchProgress extends Model
{
    use HasFactory;

    protected $table = 'viewer_watch_progress';

    protected $casts = [
        'completed' => 'boolean',
        'last_watched_at' => 'datetime',
        'position_seconds' => 'integer',
        'duration_seconds' => 'integer',
        'watch_count' => 'integer',
        'stream_id' => 'integer',
        'series_id' => 'integer',
        'season_number' => 'integer',
    ];

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(PlaylistViewer::class, 'playlist_viewer_id');
    }

    /**
     * Get the Channel associated with this progress record (live/vod).
     * stream_id in viewer_watch_progress = Channel.id
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'stream_id');
    }

    /**
     * Get the Episode associated with this progress record (episode type).
     * stream_id in viewer_watch_progress = Episode.id
     */
    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class, 'stream_id');
    }

    /**
     * Get the display title for this progress record.
     */
    public function getContentTitleAttribute(): string
    {
        if ($this->content_type === 'episode') {
            $episode = $this->episode;
            if ($episode) {
                $seriesName = $episode->series?->name ?? '';
                $suffix = $episode->season ? "S{$episode->season}E{$episode->episode_num}" : "Ep {$episode->episode_num}";

                return $seriesName ? "{$seriesName} – {$suffix}" : $episode->title;
            }
        } else {
            $channel = $this->channel;
            if ($channel) {
                return $channel->title ?? $channel->name;
            }
        }

        return "Stream #{$this->stream_id}";
    }

    /**
     * Get the artwork URL for this progress record.
     */
    public function getContentLogoAttribute(): ?string
    {
        if ($this->content_type === 'episode') {
            return $this->episode?->cover ?? $this->episode?->series?->cover ?? null;
        }

        return $this->channel?->logo ?? null;
    }

    public function scopeLive($query)
    {
        return $query->where('content_type', 'live');
    }

    public function scopeVod($query)
    {
        return $query->where('content_type', 'vod');
    }

    public function scopeEpisode($query)
    {
        return $query->where('content_type', 'episode');
    }

    public function scopeCompleted($query)
    {
        return $query->where('completed', true);
    }
}
