<?php

namespace App\Jobs;

use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Checks if there are more series to process and dispatches the next chain if needed.
 * This runs as the last job in a chain, keeping Redis memory usage low.
 */
class CheckSeriesImportProgress implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    /**
     * How many batch jobs to include in each chain.
     * Lower = less Redis memory, more chains
     * Higher = fewer chains, more Redis memory
     */
    public const JOBS_PER_CHAIN = 10;

    public function __construct(
        public int $currentOffset,
        public int $totalSeries,
        public bool $notify = true,
        public bool $all_playlists = false,
        public ?int $playlist_id = null,
        public bool $overwrite_existing = false,
        public ?int $user_id = null,
        public ?bool $sync_stream_files = true,
        public ?float $startedAt = null,
    ) {
        // Track start time on first checker
        if ($this->startedAt === null) {
            $this->startedAt = microtime(true);
        }
    }

    public function handle(GeneralSettings $settings): void
    {
        $batchSize = ProcessM3uImportSeriesEpisodes::BATCH_SIZE;
        $seriesProcessed = $this->currentOffset;
        $seriesRemaining = $this->totalSeries - $seriesProcessed;

        Log::info('Series Import: Progress check', [
            'processed' => $seriesProcessed,
            'total' => $this->totalSeries,
            'remaining' => $seriesRemaining,
            'progress_pct' => round(($seriesProcessed / $this->totalSeries) * 100, 1),
        ]);

        if ($seriesRemaining <= 0) {
            // All done! Trigger STRM sync if needed
            Log::info('Series Import: All metadata batches complete', [
                'total_series' => $this->totalSeries,
            ]);

            if ($this->sync_stream_files && $settings->stream_file_sync_enabled) {
                Log::info('Series Import: Dispatching STRM sync');
                dispatch(new SyncSeriesStrmFiles(
                    series: null,
                    notify: true,
                    all_playlists: $this->all_playlists,
                    playlist_id: $this->playlist_id,
                    user_id: $this->user_id,
                ));
            }

            // Send completion notification
            if ($this->notify && $this->user_id) {
                $user = User::find($this->user_id);
                if ($user) {
                    $duration = round(microtime(true) - $this->startedAt, 2);
                    $minutes = floor($duration / 60);
                    $seconds = $duration % 60;
                    $timeStr = $minutes > 0
                        ? "{$minutes} minute(s) and ".round($seconds, 0).' second(s)'
                        : round($seconds, 1).' second(s)';

                    Notification::make()
                        ->success()
                        ->title('Series Metadata Sync Complete')
                        ->body("Successfully processed {$this->totalSeries} series in {$timeStr}.")
                        ->broadcast($user)
                        ->sendToDatabase($user);
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

            $jobs[] = new ProcessM3uImportSeriesEpisodes(
                playlistSeries: null,
                notify: false,
                all_playlists: $this->all_playlists,
                playlist_id: $this->playlist_id,
                overwrite_existing: $this->overwrite_existing,
                user_id: $this->user_id,
                sync_stream_files: false, // Don't trigger per-job STRM sync
                batchOffset: $offset,
                totalBatches: $totalBatches,
                currentBatch: $batchNumber,
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
            overwrite_existing: $this->overwrite_existing,
            user_id: $this->user_id,
            sync_stream_files: $this->sync_stream_files,
            startedAt: $this->startedAt,
        );

        Log::info('Series Import: Dispatching next chain', [
            'jobs_in_chain' => $jobsInThisChain,
            'next_offset' => $nextOffset,
            'series_processed_after_chain' => $nextOffset,
        ]);

        Bus::chain($jobs)->dispatch();
    }
}
