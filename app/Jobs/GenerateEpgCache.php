<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\Epg;
use App\Plugins\PluginHookDispatcher;
use App\Services\EpgCacheService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateEpgCache implements ShouldQueue
{
    use Queueable;

    // Giving a timeout of 120 minutes to the Job to generate the cache
    // This should be sufficient for most EPGs, but can be adjusted if needed
    public $timeout = 60 * 120;

    // Allow up to 2 attempts (1 retry)
    public $tries = 2;

    // Delay between attempts if it fails
    public $backoff = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $uuid,
        public bool $notify = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(EpgCacheService $cacheService): void
    {
        $epg = Epg::where('uuid', $this->uuid)->first();
        if (! $epg) {
            Log::error("EPG with UUID {$this->uuid} not found for cache generation.");

            return;
        }

        if ($epg->isMerged()) {
            Log::info("Skipping cache generation for merged EPG {$epg->uuid}.");

            $epg->update([
                'status' => Status::Completed,
                'is_cached' => false,
                'cache_progress' => 0,
                'processing_started_at' => null,
                'processing_phase' => null,
            ]);

            return;
        }

        // Set memory and time limits for large EPG files
        ini_set('memory_limit', '2G');
        set_time_limit(0); // No time limit
        $start = microtime(true);
        $epg->update([
            'status' => Status::Processing,
            'processing_started_at' => now(),
            'processing_phase' => 'cache',
        ]);

        $result = $cacheService->cacheEpgData($epg);

        // Parallel mode: result is an array with chunk info
        if (is_array($result)) {
            $this->dispatchParallelChunks($epg, $result, $start);

            return;
        }

        // Synchronous mode: result is a bool
        $duration = microtime(true) - $start;
        if ($result) {
            $this->handleSyncSuccess($epg, $duration);
        } else {
            $this->handleFailure($epg);
        }
    }

    /**
     * Dispatch parallel chunk jobs via Bus::batch after pre-scan.
     */
    private function dispatchParallelChunks(Epg $epg, array $preScanResult, float $start): void
    {
        $chunkPaths = $preScanResult['chunk_paths'];
        $chunkCount = $preScanResult['chunk_count'];
        $channelCount = $preScanResult['channel_count'];
        $programmeCount = $preScanResult['programme_count'];
        $dateRange = $preScanResult['date_range'];

        if ($chunkCount === 0) {
            Log::info("No programme chunks to process for EPG {$epg->name}, finalizing immediately.");
            $cacheService = app(EpgCacheService::class);
            $cacheService->finalizeCacheAfterChunks($epg, $channelCount, $programmeCount, $dateRange);
            $this->handleSyncSuccess($epg, microtime(true) - $start);

            return;
        }

        $chunkJobs = [];
        foreach ($chunkPaths as $chunkPath) {
            $chunkJobs[] = new GenerateEpgCacheChunk($epg->uuid, $chunkPath, $chunkCount);
        }

        $epgUuid = $epg->uuid;
        $notify = $this->notify;

        Bus::batch($chunkJobs)
            ->onConnection('redis')
            ->onQueue('import')
            ->allowFailures()
            ->then(function () use ($epgUuid, $channelCount, $programmeCount, $dateRange, $notify, $start): void {
                $epg = Epg::where('uuid', $epgUuid)->first();
                if (! $epg) {
                    return;
                }

                $cacheService = app(EpgCacheService::class);
                $cacheService->finalizeCacheAfterChunks($epg, $channelCount, $programmeCount, $dateRange);

                $epg->update([
                    'status' => Status::Completed,
                    'is_cached' => true,
                    'cache_progress' => 100,
                    'processing_started_at' => null,
                    'processing_phase' => null,
                ]);

                // Clear playlist EPG cache files AFTER new cache is generated
                foreach ($epg->getAllPlaylists() as $playlist) {
                    EpgCacheService::clearPlaylistEpgCacheFile($playlist);
                }

                $duration = microtime(true) - $start;
                if ($notify) {
                    $msg = 'Cache generated successfully in '.round($duration, 2).' seconds';
                    Notification::make()
                        ->success()
                        ->title("EPG cache created for \"{$epg->name}\"")
                        ->body($msg)
                        ->broadcast($epg->user)
                        ->sendToDatabase($epg->user);
                }

                app(PluginHookDispatcher::class)->dispatch('epg.cache.generated', [
                    'epg_id' => $epg->id,
                    'playlist_ids' => $epg->getAllPlaylists()->pluck('id')->all(),
                    'user_id' => $epg->user_id,
                ], [
                    'user_id' => $epg->user_id,
                    'dry_run' => true,
                ]);
            })
            ->catch(function (Batch $batch, ?Throwable $e) use ($epgUuid): void {
                $epg = Epg::where('uuid', $epgUuid)->first();
                if (! $epg) {
                    return;
                }

                Log::error("EPG cache batch failed for {$epg->name}: ".($e?->getMessage() ?? 'Unknown'));
                $epg->update([
                    'status' => Status::Failed,
                    'is_cached' => false,
                    'cache_progress' => 100,
                    'processing_started_at' => null,
                    'processing_phase' => null,
                ]);

                $error = 'Failed to generate cache. You can try to run the cache generation again manually from the EPG management page.';
                Notification::make()
                    ->danger()
                    ->title("Error creating cache for \"{$epg->name}\"")
                    ->body($error)
                    ->broadcast($epg->user)
                    ->sendToDatabase($epg->user);
            })->dispatch();

        Log::info("Dispatched {$chunkCount} parallel cache chunk jobs for EPG {$epg->name}");
    }

    /**
     * Handle successful synchronous cache generation.
     */
    private function handleSyncSuccess(Epg $epg, float $duration): void
    {
        $epg->update([
            'status' => Status::Completed,
            'is_cached' => true,
            'cache_progress' => 100,
            'processing_started_at' => null,
            'processing_phase' => null,
        ]);

        // Clear playlist EPG cache files AFTER new cache is generated
        foreach ($epg->getAllPlaylists() as $playlist) {
            EpgCacheService::clearPlaylistEpgCacheFile($playlist);
        }

        if ($this->notify) {
            $msg = 'Cache generated successfully in '.round($duration, 2).' seconds';
            Notification::make()
                ->success()
                ->title("EPG cache created for \"{$epg->name}\"")
                ->body($msg)
                ->broadcast($epg->user)
                ->sendToDatabase($epg->user);
        }

        app(PluginHookDispatcher::class)->dispatch('epg.cache.generated', [
            'epg_id' => $epg->id,
            'playlist_ids' => $epg->getAllPlaylists()->pluck('id')->all(),
            'user_id' => $epg->user_id,
        ], [
            'user_id' => $epg->user_id,
            'dry_run' => true,
        ]);
    }

    /**
     * Handle cache generation failure.
     */
    private function handleFailure(Epg $epg): void
    {
        $epg->update([
            'status' => Status::Failed,
            'is_cached' => false,
            'cache_progress' => 100,
            'processing_started_at' => null,
            'processing_phase' => null,
        ]);
        $error = 'Failed to generate cache. You can try to run the cache generation again manually from the EPG management page.';
        Notification::make()
            ->danger()
            ->title("Error creating cache for \"{$epg->name}\"")
            ->body($error)
            ->broadcast($epg->user)
            ->sendToDatabase($epg->user);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        $epg = Epg::where('uuid', $this->uuid)->first();
        if ($epg) {
            // We'll just log a warning since this is likely an false-positive ("Job has been tried too many times") as the job typically finishes successfully on retry
            Log::warning("EPG cache generation failed for {$epg->name}: {$exception->getMessage()}");
        }
    }
}
