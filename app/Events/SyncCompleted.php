<?php

namespace App\Events;

use App\Models\Playlist;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  string  $source  The source of the sync (e.g., 'playlist', 'emby_vod', 'emby_series')
     * @param  array<int>|null  $newChannelIds  Optional array of new channel IDs from this sync
     */
    public function __construct(
        public Model $model,
        public string $source = 'playlist',
        public ?array $newChannelIds = null,
    ) {}
}
