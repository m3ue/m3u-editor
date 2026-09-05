<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Enums\SyncRunPhase;
use App\Exceptions\XtreamRateLimitedException;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\SyncPipelineService;
use App\Settings\GeneralSettings;
use App\Traits\ProviderRequestDelay;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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
        public ?int $batchOffset = null,
        public ?int $totalBatches = null,
        public ?int $currentBatch = null,
        public ?int $syncRunId = null,
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
        // Count total series to process. Series whose metadata is already
        // fresh (last_metadata_fetch after the provider's last_modified, and
        // episodes exist) are excluded entirely so their batch jobs never pay
        // the throttle slot + delay for a provider call that would be skipped
        // anyway — see Series::isMetadataFresh()/scopeNeedsMetadataRefresh().
        $totalCount = Series::query()
            ->where([
                ['enabled', true],
                ['user_id', $this->user_id],
            ])
            ->when($this->playlist_id, function ($query) {
                $query->where('playlist_id', $this->playlist_id);
            })
            ->needsMetadataRefresh($this->overwrite_existing)
            ->count();

        if ($totalCount === 0) {
            Log::info('Series Sync: No series to process');

            if ($this->playlist_id) {
                $playlist = Playlist::find($this->playlist_id);
                if ($playlist) {
                    $playlist->update([
                        'processing' => [
                            ...$playlist->processing ?? [],
                            'series_processing' => false,
                        ],
                        'status' => Status::Completed,
                        'series_progress' => 100,
                    ]);
                }
            }

            // Advance the pipeline so the run is not left stuck in running.
            if ($this->syncRunId) {
                app(SyncPipelineService::class)->completePhase($this->syncRunId, SyncRunPhase::SeriesMetadata);
            }

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
                syncRunId: $this->syncRunId, // so a failed batch can fail the pipeline phase
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
            syncRunId: $this->syncRunId,
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

        // Get series IDs for this batch (using offset/limit instead of chunkById).
        // Filtered the same way as dispatchBatches()'s count so the offsets stay
        // aligned with totalBatches/totalCount.
        $seriesIds = Series::query()
            ->where([
                ['enabled', true],
                ['user_id', $this->user_id],
            ])
            ->when($this->playlist_id, function ($query) {
                $query->where('playlist_id', $this->playlist_id);
            })
            ->needsMetadataRefresh($this->overwrite_existing)
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
                try {
                    // Suppress per-series sync/TMDB dispatch — handled in bulk by CheckSeriesImportProgress
                    $this->fetchMetadataForSeries($series, $global_sync_settings, dispatchSync: false, dispatchTmdb: false);
                    $processedCount++;
                } catch (XtreamRateLimitedException $e) {
                    // Account-wide cooldown: every remaining series in this batch
                    // (and every later batch already queued in this chain) would
                    // fail the same way. Rethrow — CheckSeriesImportProgress advances
                    // its offset arithmetically per batch without verifying actual
                    // success, so silently returning here would let the run report
                    // 100% complete despite skipping every series after this one.
                    // failed() below fails the pipeline/run honestly instead.
                    Log::warning('ProcessM3uImportSeriesEpisodes: aborting batch, Xtream account is rate limited', [
                        'user_id' => $this->user_id,
                        'playlist_id' => $this->playlist_id,
                        'batch' => $this->currentBatch,
                        'processed' => $processedCount,
                        'retry_at' => $e->retryAt->toIso8601String(),
                    ]);

                    throw $e;
                }
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
     * @param  bool  $dispatchTmdb  Whether to dispatch TMDB fetch job (false for bulk mode)
     */
    private function fetchMetadataForSeries($series, $settings, bool $dispatchSync = true, bool $dispatchTmdb = true)
    {
        // Get the playlist
        $playlist = $series->playlist;

        // Bulk mode fast path: skip the throttled provider round-trip entirely
        // for custom series (no Xtream fetch) and for series whose metadata is
        // still fresh. The batch queries already exclude these via
        // needsMetadataRefresh(), but this also covers callers that pass an
        // individual series directly without going through that filter.
        if (! $dispatchSync && ! $dispatchTmdb
            && ($series->is_custom || $series->isMetadataFresh($this->overwrite_existing))) {
            return;
        }

        // In bulk mode (dispatchSync=false), don't trigger per-series sync
        $shouldSync = $dispatchSync && $this->sync_stream_files;

        // Use provider throttling to limit concurrent requests and apply delay
        $results = $this->withProviderThrottling(function () use ($series, $shouldSync, $dispatchTmdb) {
            return $series->fetchMetadata(
                refresh: $this->overwrite_existing,
                sync: $shouldSync,
                dispatchTmdb: $dispatchTmdb,
            );
        });

        // A real fetch/DB failure must abort the whole sync, not land silently. Landing
        // a partial series_metadata pass here doesn't delete anything by itself, but it
        // does let the run report "complete" while some series keep stale episodes - the
        // same kind of silent partial we removed from the import phase. Only do this in
        // a pipeline sync run (syncRunId set); fetchMetadata() also returns false for
        // benign reasons (e.g. no provider configured) outside that context.
        if ($results === false && $this->syncRunId !== null) {
            throw new \RuntimeException(
                "Series metadata fetch failed for series {$series->id} ({$series->name}) on playlist {$series->playlist_id}. Aborting sync run {$this->syncRunId}."
            );
        }

        if ($this->notify && $results !== false) {
            // Check if the playlist has .strm file sync enabled
            $sync_settings = $series->sync_settings;
            $syncStrmFiles = $settings['enabled'] ?? $sync_settings['enabled'] ?? false;
            $episodeCount = $series->episodes()->count();
            $body = "Series sync completed successfully for \"{$series->name}\". Imported {$episodeCount} episodes.";
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

    /**
     * A batch job dying (uncaught error, timeout, OOM, worker restart) used to
     * abort its Bus::chain() silently, so CheckSeriesImportProgress never ran and
     * the SyncRun sat in the series_metadata phase forever ("stuck processing").
     * Fail the pipeline phase explicitly instead so the run ends cleanly and the
     * user gets a real failure they can retry.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessM3uImportSeriesEpisodes failed', [
            'playlist_id' => $this->playlist_id,
            'sync_run_id' => $this->syncRunId,
            'batch_offset' => $this->batchOffset,
            'error' => $exception?->getMessage(),
        ]);

        if ($this->playlist_id) {
            $playlist = Playlist::find($this->playlist_id);
            if ($playlist) {
                $playlist->update([
                    'processing' => [
                        ...$playlist->processing ?? [],
                        'series_processing' => false,
                    ],
                    'status' => Status::Failed,
                    'errors' => $exception ? Str::limit($exception->getMessage(), 255) : 'Series metadata sync failed',
                ]);
            }
        }

        if ($this->syncRunId) {
            $run = SyncRun::find($this->syncRunId);
            if ($run) {
                app(SyncPipelineService::class)->fail($run, 'Series metadata batch failed: '.($exception?->getMessage() ?? 'unknown error'));
            }
        }
    }
}
