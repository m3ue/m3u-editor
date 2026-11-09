<?php

namespace Tests\Support;

use App\Jobs\Middleware\SkipIfSyncReset;
use App\Models\Playlist;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One step of a fake sync chain: records that it ran, and can reset the
 * playlist while it runs, the way a user presses "Reset status" mid-sync.
 */
class RecordsSyncStepJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $step,
        public ?int $resetPlaylistId = null,
    ) {}

    public function handle(): void
    {
        cache()->forever('sync-steps', [...cache()->get('sync-steps', []), $this->step]);

        if ($this->resetPlaylistId !== null) {
            SkipIfSyncReset::recordReset(Playlist::findOrFail($this->resetPlaylistId));
        }
    }
}
