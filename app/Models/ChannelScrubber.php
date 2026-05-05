<?php

namespace App\Models;

use App\Enums\Status;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelScrubber extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'processing' => 'boolean',
            'progress' => 'float',
            'include_vod' => 'boolean',
            'scan_all' => 'boolean',
            'recurring' => 'boolean',
            'use_batching' => 'boolean',
            'probe_timeout' => 'integer',
            'disable_dead' => 'boolean',
            'enable_live' => 'boolean',
            'channel_count' => 'integer',
            'dead_count' => 'integer',
            'user_id' => 'integer',
            'playlist_id' => 'integer',
            'last_run_at' => 'datetime',
            'status' => Status::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ChannelScrubberLog::class);
    }
}
