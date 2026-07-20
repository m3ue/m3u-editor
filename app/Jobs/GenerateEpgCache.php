<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Plugins\PluginHookDispatcher;
use App\Services\EpgCacheService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection as SupportCollection;
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
        $duration = microtime(true) - $start;
        if ($result) {
            $epg->update([
                'status' => Status::Completed,
                'is_cached' => true,
                'cache_progress' => 100,
                'processing_started_at' => null,
                'processing_phase' => null,
            ]);

            $playlists = $epg->getAllPlaylists();

            // Clear playlist EPG cache files AFTER new cache is generated
            // This ensures users can still get cached EPG files during regeneration
            foreach ($playlists as $playlist) {
                EpgCacheService::clearPlaylistEpgCacheFile($playlist);
            }

            $this->dispatchDvrRescan($playlists);

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
                'playlist_ids' => $playlists->pluck('id')->all(),
                'user_id' => $epg->user_id,
            ], [
                'user_id' => $epg->user_id,
                'dry_run' => true,
            ]);
        } else {
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
    }

    /**
     * Dispatch a DVR deep scan scoped to the playlists that consume this EPG, so
     * existing DVR rules get rescanned against the freshly-cached programme data.
     * Replaces the old daily unscoped DvrDeepScan cron — this runs on every EPG
     * sync instead, scoped to just the affected playlists.
     *
     * @param  SupportCollection<int, Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias>  $playlists
     */
    private function dispatchDvrRescan(SupportCollection $playlists): void
    {
        if (! config('proxy.proxy_integration_enabled', true) || ! config('dvr.dvr_enabled', true)) {
            return;
        }

        $playlistIds = [];
        $customPlaylistIds = [];
        $mergedPlaylistIds = [];

        foreach ($playlists as $playlist) {
            match (true) {
                $playlist instanceof Playlist => $playlistIds[] = $playlist->id,
                $playlist instanceof CustomPlaylist => $customPlaylistIds[] = $playlist->id,
                $playlist instanceof MergedPlaylist => $mergedPlaylistIds[] = $playlist->id,
                default => null,
            };
        }

        if (empty($playlistIds) && empty($customPlaylistIds) && empty($mergedPlaylistIds)) {
            return;
        }

        dispatch(new DvrDeepScan($playlistIds, $customPlaylistIds, $mergedPlaylistIds));
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
