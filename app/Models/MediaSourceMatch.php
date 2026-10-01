<?php

namespace App\Models;

use Database\Factories\MediaSourceMatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaSourceMatch extends Model
{
    /** @use HasFactory<MediaSourceMatchFactory> */
    use HasFactory;

    protected $fillable = [
        'playlist_id',
        'media_server_integration_id',
        'channel_id',
        'episode_id',
        'media_channel_id',
        'media_episode_id',
        'match_key',
    ];

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    /**
     * The media server integration providing the replacement source
     * (non-standard FK: media_server_integration_id).
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(MediaServerIntegration::class, 'media_server_integration_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    /**
     * The media server's Channel that replaces the provider stream
     * (distinct from the provider-side channel() on the same model class).
     */
    public function mediaChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'media_channel_id');
    }

    /**
     * The media server's Episode that replaces the provider stream
     * (distinct from the provider-side episode() on the same model class).
     */
    public function mediaEpisode(): BelongsTo
    {
        return $this->belongsTo(Episode::class, 'media_episode_id');
    }
}
