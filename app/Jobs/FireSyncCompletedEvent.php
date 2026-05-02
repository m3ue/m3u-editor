<?php

namespace App\Jobs;

use App\Events\SyncCompleted;
use App\Models\Playlist;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FireSyncCompletedEvent implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(
        public Playlist $playlist,
    ) {}

    public function handle(): void
    {
        event(new SyncCompleted($this->playlist, 'playlist'));
    }
}
