<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelScrubberLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'channel_scrubber_id' => 'integer',
            'user_id' => 'integer',
            'playlist_id' => 'integer',
            'channel_count' => 'integer',
            'dead_count' => 'integer',
            'disabled_count' => 'integer',
            'live_count' => 'integer',
            'runtime' => 'float',
        ];
    }

    public function channelScrubber(): BelongsTo
    {
        return $this->belongsTo(ChannelScrubber::class);
    }

    public function deadChannels(): HasMany
    {
        return $this->hasMany(ChannelScrubberLogChannel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }
}
