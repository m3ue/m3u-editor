<?php

namespace App\Jobs;

use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Traits\ProviderRequestDelay;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ProcessM3uImportSeriesEpisodes implements ShouldQueue
{
    use ProviderRequestDelay;
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    /**
     * Batch size for processing series.
     * Each batch is dispatched as a separate job to prevent timeouts.
     */
    public const BATCH_SIZE = 100;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?Series $playlistSeries = null,
        public bool $notify = true,
        public bool $all_playlists = false,
        public ?int $playlist_id = null,
        public bool $overwrite_existing = false,
        public ?int $user_id = null,
        public ?bool $sync_stream_files = true,
        public ?int $batchOffset = null,  // For batch processing: starting offset
        public ?int $totalBatches = null, // For tracking progress
        public ?int $currentBatch = null, // Current batch number (1-indexed)
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GeneralSettings $settings): void
    {
        // Get the series
        $series = $this->playlistSeries;

        // Get global sync settings
        $global_sync_settings = [
            'enabled' => $settings->stream_file_sync_enabled ?? false,
        ];

        // Debug logging to see which path is taken
        Log::info('ProcessM3uImportSeriesEpisodes: Starting', [
            'has_series' => $series !== null,
            'has_batch_offset' => $this->batchOffset !== null,
            'user_id' => $this->user_id,
            'playlist_id' => $this->playlist_id,
            'all_playlists' => $this->all_playlists,
        ]);

        if ($series) {
            // Single series processing
            Log::info('ProcessM3uImportSeriesEpisodes: Single series mode');
            $this->fetchMetadataForSeries($series, $global_sync_settings);
        } elseif ($this->batchOffset !== null) {
            // Batch processing mode - process a specific batch
            Log::info('ProcessM3uImportSeriesEpisodes: Batch processing mode');
            $this->processBatch($settings, $global_sync_settings);
        } else {
            // Initial dispatch - calculate batches and dispatch them
            Log::info('ProcessM3uImportSeriesEpisodes: Dispatch batches mode');
            $this->dispatchBatches($settings);
        }
    }

    /**
     * Dispatch first chain of batch jobs.
     * Uses Bus::chain() with CheckSeriesImportProgress to recursively process series
     * in waves, preventing Redis memory exhaustion.
     */
    private function dispatchBatches(GeneralSettings $settings): void
    {
        // Count total series to process
        $totalCount = Series::query()
            ->where([
                ['enabled', true],
                ['user_id', $this->user_id],
            ])
            ->when($this->playlist_id, function ($query) {
                $query->where('playlist_id', $this->playlist_id);
            })
            ->count();

        if ($totalCount === 0) {
            Log::info('Series Sync: No series to process');

            return;
        }

        $batchSize = self::BATCH_SIZE;
        $totalBatches = (int) ceil($totalCount / $batchSize);
        $jobsPerChain = CheckSeriesImportProgress::JOBS_PER_CHAIN;
        $totalChains = (int) ceil($totalBatches / $jobsPerChain);

        Log::info('Series Sync: Starting chain-based dispatch', [
            'total_series' => $totalCount,
            'batch_size' => $batchSize,
            'total_batches' => $totalBatches,
            'jobs_per_chain' => $jobsPerChain,
            'total_chains' => $totalChains,
            'user_id' => $this->user_id,
            'playlist_id' => $this->playlist_id,
        ]);

        // Build first chain
        $jobs = [];
        $jobsInFirstChain = min($jobsPerChain, $totalBatches);

        for ($batch = 0; $batch < $jobsInFirstChain; $batch++) {
            $offset = $batch * $batchSize;

            $jobs[] = new self(
                playlistSeries: null,
                notify: false,
                all_playlists: $this->all_playlists,
                playlist_id: $this->playlist_id,
                overwrite_existing: $this->overwrite_existing,
                user_id: $this->user_id,
                sync_stream_files: false, // Don't trigger per-job STRM sync
                batchOffset: $offset,
                totalBatches: $totalBatches,
                currentBatch: $batch + 1,
            );
        }

        // Add checker job at the end of the chain
        $jobs[] = new CheckSeriesImportProgress(
            currentOffset: $jobsInFirstChain * $batchSize,
            totalSeries: $totalCount,
            notify: $this->notify,
            all_playlists: $this->all_playlists,
            playlist_id: $this->playlist_id,
            overwrite_existing: $this->overwrite_existing,
            user_id: $this->user_id,
            sync_stream_files: $this->sync_stream_files,
        );

        // Dispatch the chain
        Bus::chain($jobs)->dispatch();

        // Notify user that sync has started
        if ($this->user_id) {
            $user = User::find($this->user_id);
            if ($user) {
                Notification::make()
                    ->info()
                    ->title('Series Sync Started')
                    ->body("Processing {$totalCount} series in {$totalChains} chain(s) of {$jobsPerChain} jobs each...")
                    ->broadcast($user)
                    ->sendToDatabase($user);
            }
        }
    }

    /**
     * Process a specific batch of series.
     */
    private function processBatch(GeneralSettings $settings, array $global_sync_settings): void
    {
        $startTime = microtime(true);
        $processedCount = 0;

        Log::info("Series Sync: Processing batch {$this->currentBatch}/{$this->totalBatches}", [
            'offset' => $this->batchOffset,
            'batch_size' => self::BATCH_SIZE,
        ]);

        // Get series IDs for this batch (using offset/limit instead of chunkById)
        $seriesIds = Series::query()
            ->where([
                ['enabled', true],
                ['user_id', $this->user_id],
            ])
            ->when($this->playlist_id, function ($query) {
                $query->where('playlist_id', $this->playlist_id);
            })
            ->orderBy('id')
            ->skip($this->batchOffset)
            ->take(self::BATCH_SIZE)
            ->pluck('id')
            ->toArray();

        // Process in smaller chunks for memory management
        foreach (array_chunk($seriesIds, 10) as $chunkIds) {
            $seriesChunk = Series::query()
                ->whereIn('id', $chunkIds)
                ->with(['playlist'])
                ->get();

            foreach ($seriesChunk as $series) {
                // Pass dispatchSync: false to prevent per-series job dispatch
                $this->fetchMetadataForSeries($series, $global_sync_settings, dispatchSync: false);
                $processedCount++;
            }

            // Clear memory after each mini-chunk
            unset($seriesChunk);
            gc_collect_cycles();
        }

        $duration = round(microtime(true) - $startTime, 2);
        Log::info("Series Sync: Batch {$this->currentBatch}/{$this->totalBatches} completed", [
            'processed' => $processedCount,
            'duration_seconds' => $duration,
        ]);

        // Note: Completion notification is handled by CheckSeriesImportProgress
        // which runs after all chains complete, not after individual batches
    }

    /**
     * Fetch metadata for a single series.
     *
     * @param  bool  $dispatchSync  Whether to dispatch sync job (false for bulk mode)
     */
    private function fetchMetadataForSeries($series, $settings, bool $dispatchSync = true)
    {
        // Get the playlist
        $playlist = $series->playlist;

        // In bulk mode (dispatchSync=false), don't trigger per-series sync
        $shouldSync = $dispatchSync && $this->sync_stream_files;

        // Use provider throttling to limit concurrent requests and apply delay
        $results = $this->withProviderThrottling(function () use ($series, $shouldSync) {
            return $series->fetchMetadata(
                refresh: $this->overwrite_existing,
                sync: $shouldSync
            );
        });

        if ($this->notify && $results !== false) {
            // Check if the playlist has .strm file sync enabled
            $sync_settings = $series->sync_settings;
            $syncStrmFiles = $settings['enabled'] ?? $sync_settings['enabled'] ?? false;
            $body = "Series sync completed successfully for \"{$series->name}\". Imported {$results} episodes.";
            if ($syncStrmFiles) {
                $body .= ' .strm file sync is enabled, syncing now.';
            } else {
                $body .= ' .strm file sync is not enabled.';
            }
            Notification::make()
                ->success()
                ->title('Series Sync Completed')
                ->body($body)
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        }
    }
}
