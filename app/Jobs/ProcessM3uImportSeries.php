<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Playlist;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessM3uImportSeries implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 60 * 60 * 1; // 1 hour

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public ?bool $force = false,
        public ?bool $isNew = false,
        public ?string $batchNo = null,

    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! $this->force) {
            // Don't update if currently processing
            if ($this->playlist->isProcessingSeries()) {
                return;
            }

            // Check if auto sync is enabled, or the playlist hasn't been synced yet
            if (! $this->playlist->auto_sync && $this->playlist->synced) {
                return;
            }
        }

        // Set the batch number
        if (! $this->batchNo) {
            $this->batchNo = Str::orderedUuid()->toString();
        }

        // Update the playlist status to processing
        $this->playlist->update([
            'processing' => [
                ...$this->playlist->processing ?? [],
                'series_processing' => true,
            ],
            'status' => Status::Processing,
            'errors' => null,
            'series_progress' => 0,
        ]);
        $this->importSeries();
    }

    /**
     * Import/sync series episodes.
     */
    public function importSeries(): void
    {
        try {
            // Use the new bulk dispatcher pattern instead of creating individual jobs
            // This prevents flooding Redis with thousands of jobs
            dispatch(new ProcessM3uImportSeriesEpisodes(
                playlistSeries: null,  // null triggers bulk mode
                notify: true,
                all_playlists: false,
                playlist_id: $this->playlist->id,
                overwrite_existing: false,
                user_id: $this->playlist->user_id,
                sync_stream_files: true,
            ));

            Log::info('ProcessM3uImportSeries: Dispatched bulk series sync', [
                'playlist_id' => $this->playlist->id,
                'playlist_name' => $this->playlist->name,
            ]);
        } catch (Exception $e) {
            // Update the playlist status to error
            $error = Str::limit($e->getMessage(), 255);
            $this->playlist->update([
                'processing' => [
                    ...$this->playlist->processing ?? [],
                    'series_processing' => false,
                ],
                'status' => Status::Failed,
                'errors' => $error,
                'series_progress' => 0,
            ]);
            Notification::make()
                ->danger()
                ->title('Series Sync Failed')
                ->body("Playlist series sync failed for \"{$this->playlist->name}\". Error: {$error}")
                ->broadcast($this->playlist->user)
                ->sendToDatabase($this->playlist->user);
            Log::error('Series Sync Failed', [
                'playlist_id' => $this->playlist->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Fire the playlist synced event
            event(new SyncCompleted($playlist));
        }
    }
}
