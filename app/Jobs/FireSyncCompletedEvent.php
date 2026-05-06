<?php

namespace App\Jobs;

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
        // Idempotent within the current sync window; safe even if other code paths
        // fired SyncCompleted earlier in this run.
        $this->playlist->dispatchSyncCompletedOnce();
    }
}
