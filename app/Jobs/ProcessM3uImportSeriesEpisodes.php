<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Enums\SyncRunPhase;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\SyncPipelineService;
use App\Services\XtreamService;
use App\Settings\GeneralSettings;
use App\Traits\ProviderRequestDelay;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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
     * Cache key prefix for the provider bulk-diff target list.
     */
    private function targetCacheKey(): string
    {
        return 'series_sync_target_'.($this->playlist_id ?? 'all').'_'.($this->user_id ?? 'all');
    }

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
     * Fetch the provider's full series list (bulk get_series, ~1-2s for ~17k series)
     * and return the local series IDs whose provider last_modified differs from
     * what we stored (i.e. actually changed since our last fetch), or null when
     * the bulk cannot be used (fallback to full scan).
     *
     * @return array<int>|null Local series IDs to process, or null for full scan
     */
    private function diffSeriesAgainstProvider(): ?array
    {
        try {
            $playlist = Playlist::find($this->playlist_id);
            if (! $playlist || ! $playlist->xtream) {
                return null;
            }

            $xtream = XtreamService::make($playlist);
            if (! $xtream) {
                return null;
            }

            $bulk = $xtream->getAllSeries();
            if (! is_array($bulk) || count($bulk) === 0) {
                Log::warning('Series bulk diff: provider returned empty list, falling back to full scan');

                return null;
            }

            // Map provider series_id -> last_modified (unix ts)
            $providerMap = [];
            foreach ($bulk as $s) {
                $id = (string) ($s['series_id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $providerMap[$id] = (int) ($s['last_modified'] ?? 0);
            }

            $local = Series::query()
                ->where([
                    ['enabled', true],
                    ['user_id', $this->user_id],
                ])
                ->when($this->playlist_id, function ($query) {
                    $query->where('playlist_id', $this->playlist_id);
                })
                ->select('id', 'source_series_id', 'last_modified', 'last_metadata_fetch')
                ->get();

            $toProcess = [];
            foreach ($local as $s) {
                $sid = (string) $s->source_series_id;

                // Series absent from the provider are kept as-is (no action).
                if (! isset($providerMap[$sid])) {
                    continue;
                }

                $plm = $providerMap[$sid];
                $llm = $s->last_modified ? strtotime($s->last_modified.' UTC') : 0;

                // Fresh = we fetched after the provider's last change AND we
                // already have episodes. Anything else needs a (re)visit.
                $fresh = $s->last_metadata_fetch
                    && $llm
                    && $s->last_metadata_fetch >= $s->last_modified
                    && $plm <= $llm;

                if (! $fresh) {
                    $toProcess[] = (int) $s->id;
                }
            }

            Log::info('Series bulk diff completed', [
                'provider_series' => count($providerMap),
                'local_series' => count($local),
                'to_process' => count($toProcess),
            ]);

            return $toProcess;
        } catch (\Throwable $e) {
            Log::warning('Series bulk diff failed, falling back to full scan: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Dispatch first chain of batch jobs.
     * Uses Bus::chain() with CheckSeriesImportProgress to recursively process series
     * in waves, preventing Redis memory exhaustion.
     */
    private function dispatchBatches(GeneralSettings $settings): void
    {
        // Optional provider bulk diff: when the playlist is Xtream and no force
        // refresh is requested, only re-visit series whose provider last_modified
        // changed since our last fetch (plus new/never-fetched ones). Falls back
        // to the full scan when the bulk cannot be fetched.
        $targetIds = null;
        if (! $this->overwrite_existing && $this->playlist_id && ! $this->all_playlists) {
            $targetIds = $this->diffSeriesAgainstProvider();
            if ($targetIds !== null) {
                // TTL covers the longest possible run; the key is re-written at
                // the start of every run, so a stale list cannot leak into the
                // next sync.
                Cache::put($this->targetCacheKey(), $targetIds, now()->addHours(6));
            } else {
                // Bulk diff unavailable (fallback full scan): make sure batch
                // jobs do not filter on a previous run's target list.
                Cache::forget($this->targetCacheKey());
            }
        }

        // Count total series to process
        $totalCount = Series::query()
            ->where([
                ['enabled', true],
                ['user_id', $this->user_id],
            ])
            ->when($this->playlist_id, function ($query) {
                $query->where('playlist_id', $this->playlist_id);
            })
            ->when($targetIds !== null, function ($query) use ($targetIds) {
                $query->whereIn('id', $targetIds);
            })
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
            'bulk_diff' => $targetIds !== null,
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

        // Read the bulk-diff target list if present (set during dispatch).
        $targetIds = Cache::get($this->targetCacheKey());

        Log::info("Series Sync: Processing batch {$this->currentBatch}/{$this->totalBatches}", [
            'offset' => $this->batchOffset,
            'batch_size' => self::BATCH_SIZE,
            'bulk_diff_targets' => $targetIds !== null ? count($targetIds) : null,
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
            ->when($targetIds !== null, function ($query) use ($targetIds) {
                $query->whereIn('id', $targetIds);
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
                // Suppress per-series sync/TMDB dispatch — handled in bulk by CheckSeriesImportProgress
                $this->fetchMetadataForSeries($series, $global_sync_settings, dispatchSync: false, dispatchTmdb: false);
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
     * @param  bool  $dispatchTmdb  Whether to dispatch TMDB fetch job (false for bulk mode)
     */
    private function fetchMetadataForSeries($series, $settings, bool $dispatchSync = true, bool $dispatchTmdb = true)
    {
        // Get the playlist
        $playlist = $series->playlist;

        // Bulk mode fast path: skip the throttled provider round-trip entirely
        // for custom series (no Xtream fetch) and for series whose metadata is
        // still fresh. The throttle slot + 500ms delay would otherwise be paid
        // for every series in every batch, even when fetchMetadata() has nothing
        // to do — the main cause of the ~2h30 per-run duration.
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
}
