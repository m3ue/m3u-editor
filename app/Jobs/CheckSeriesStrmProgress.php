<?php

namespace App\Jobs;

use App\Models\Series;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Checks if there are more series STRM files to sync and dispatches the next chain if needed.
 */
class CheckSeriesStrmProgress implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public const JOBS_PER_CHAIN = 10;

    public function __construct(
        public int $currentOffset,
        public int $totalSeries,
        public bool $notify = true,
        public bool $all_playlists = false,
        public ?int $playlist_id = null,
        public ?int $user_id = null,
        public bool $needsCleanup = false,
        public ?array $series_ids = null,
    ) {
        $this->onQueue('file_sync');
    }

    public function handle(): void
    {
        $batchSize = SyncSeriesStrmFiles::BATCH_SIZE;
        $seriesProcessed = $this->currentOffset;
        $seriesRemaining = $this->totalSeries - $seriesProcessed;

        Log::info('STRM Sync: Progress check', [
            'processed' => $seriesProcessed,
            'total' => $this->totalSeries,
            'remaining' => $seriesRemaining,
            'progress_pct' => round(($seriesProcessed / $this->totalSeries) * 100, 1),
        ]);

        if ($seriesRemaining <= 0) {
            // All done! Run cleanup if needed
            Log::info('STRM Sync: All batches complete', [
                'total_series' => $this->totalSeries,
            ]);

            if ($this->needsCleanup) {
                Log::info('STRM Sync: Dispatching cleanup job');
                dispatch(new SyncSeriesStrmFiles(
                    series: null,
                    notify: $this->notify,
                    all_playlists: $this->all_playlists,
                    playlist_id: $this->playlist_id,
                    user_id: $this->user_id,
                    isCleanupJob: true,
                    series_ids: $this->series_ids,
                ));
            } else {
                // Send completion notification
                if ($this->notify && $this->user_id) {
                    $user = User::find($this->user_id);
                    if ($user) {
                        Notification::make()
                            ->success()
                            ->title('STRM File Sync Complete')
                            ->body("Successfully synced {$this->totalSeries} series.")
                            ->broadcast($user)
                            ->sendToDatabase($user);
                    }
                }
            }

            return;
        }

        // More series to process - dispatch next chain
        $jobs = [];
        $jobsInThisChain = min(self::JOBS_PER_CHAIN, (int) ceil($seriesRemaining / $batchSize));

        for ($i = 0; $i < $jobsInThisChain; $i++) {
            $offset = $seriesProcessed + ($i * $batchSize);
            $batchNumber = (int) floor($offset / $batchSize) + 1;
            $totalBatches = (int) ceil($this->totalSeries / $batchSize);

            $jobs[] = new SyncSeriesStrmFiles(
                series: null,
                notify: false,
                all_playlists: $this->all_playlists,
                playlist_id: $this->playlist_id,
                user_id: $this->user_id,
                batchOffset: $offset,
                totalBatches: $totalBatches,
                currentBatch: $batchNumber,
                series_ids: $this->series_ids,
            );
        }

        // Add checker as last job in chain
        $nextOffset = $seriesProcessed + ($jobsInThisChain * $batchSize);
        $jobs[] = new self(
            currentOffset: $nextOffset,
            totalSeries: $this->totalSeries,
            notify: $this->notify,
            all_playlists: $this->all_playlists,
            playlist_id: $this->playlist_id,
            user_id: $this->user_id,
            needsCleanup: $this->needsCleanup,
            series_ids: $this->series_ids,
        );

        Log::info('STRM Sync: Dispatching next chain', [
            'jobs_in_chain' => $jobsInThisChain,
            'next_offset' => $nextOffset,
        ]);

        Bus::chain($jobs)->dispatch();
    }
}
