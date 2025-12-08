<?php

namespace App\Jobs;

use Exception;
use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        public ?bool    $force = false,
        public ?bool    $isNew = false,
        public ?string  $batchNo = null,

    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!$this->force) {
            // Don't update if currently processing
            if ($this->playlist->isProcessingSeries()) {
                return;
            }

            // Check if auto sync is enabled, or the playlist hasn't been synced yet
            if (!$this->playlist->auto_sync && $this->playlist->synced) {
                return;
            }
        }

        // Set the batch number
        if (!$this->batchNo) {
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
            $jobs = [];
            $series = $this->playlist->series()->where('enabled', true)->cursor();
            foreach ($series as $seriesItem) {
                $jobs[] = new ProcessM3uImportSeriesEpisodes(
                    playlistSeries: $seriesItem,
                    notify: false, // don't notify user for bulk syncs
                );
            }
            $jobs[] = new ProcessM3uImportSeriesComplete(
                playlist: $this->playlist,
                batchNo: $this->batchNo,
            );
            $playlist = $this->playlist;
            Bus::chain($jobs)
                ->onConnection('redis') // force to use redis connection
                ->onQueue('import')
                ->catch(function (Throwable $e) use ($playlist) {
                    $error = "Error processing series sync on \"{$playlist->name}\": {$e->getMessage()}";
                    Log::error($error);
                    Notification::make()
                        ->danger()
                        ->title("Error processing series sync on \"{$playlist->name}\"")
                        ->body('Please view your notifications for details.')
                        ->broadcast($playlist->user);
                    Notification::make()
                        ->danger()
                        ->title("Error processing series sync on \"{$playlist->name}\"")
                        ->body($error)
                        ->sendToDatabase($playlist->user);
                    $playlist->update([
                        'status' => Status::Failed,
                        'synced' => now(),
                        'errors' => $error,
                        'series_progress' => 100,
                        'processing' => [
                            ...$playlist->processing ?? [],
                            'series_processing' => false,
                        ],
                    ]);

                    // Fire the playlist synced event
                    event(new SyncCompleted($playlist));
                })->dispatch();
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
