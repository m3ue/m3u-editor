<?php

namespace App\Jobs;

use App\Models\Playlist;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FireStreamFilesSyncedEvent implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(
        public Playlist $playlist,
        public string $event = 'stream_files_synced',
    ) {}

    public function handle(): void
    {
        $this->playlist->postProcesses()
            ->where('event', $this->event)
            ->where('enabled', true)
            ->get()
            ->each(fn ($postProcess) => dispatch(new RunPostProcess($postProcess, $this->playlist)));
    }
}
