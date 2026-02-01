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
     */
    public function __construct(
        public Model $model,
        public string $source = 'playlist',
    ) {}
}
