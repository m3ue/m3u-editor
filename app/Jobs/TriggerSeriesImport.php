<?php

namespace App\Jobs;

use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TriggerSeriesImport implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(
        public Playlist $playlist,
        public bool $isNew = false,
        public ?string $batchNo = null,
    ) {}

    public function handle(): void
    {
        dispatch(new ProcessM3uImportSeries(
            playlist: $this->playlist,
            force: true,
            isNew: $this->isNew,
            batchNo: $this->batchNo,
        ));

        Notification::make()
            ->info()
            ->title('Fetching Series Metadata')
            ->body('Fetching series metadata now. This may take a while depending on how many series you have enabled. If stream file syncing is enabled, it will also be ran. Please check back later.')
            ->broadcast($this->playlist->user)
            ->sendToDatabase($this->playlist->user);
    }
}
