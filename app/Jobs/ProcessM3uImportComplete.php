<?php

namespace App\Jobs;

use App\Enums\EpgSourceType;
use App\Enums\Status;
use App\Enums\SyncRunPhase;
use App\Models\Channel;
use App\Models\EpgMap;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\PlaylistSyncStatus;
use App\Models\PlaylistSyncStatusLog;
use App\Models\Series;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\AlertService;
use App\Services\EpgCacheService;
use App\Services\SyncPipelineService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessM3uImportComplete implements ShouldQueue
{
    use Queueable;

    public bool $runningSeriesImport = false;

    // Don't retry the job on failure
    public $tries = 1;

    // Giving a timeout of 10 minutes to the Job to process the file
    public $timeout = 60 * 10;

    // Delete the job when the model is missing
    public $deleteWhenMissingModels = true;

    // Whether to invalidate the import if the number of new channels is less than the current count
    public $invalidateImport = false;

    public $invalidateImportThreshold = 100;

    public $invalidateImportSeriesThreshold = 100;

    public $invalidateImportGroupThreshold = 50;

    // Default user agent to use for HTTP requests
    // Used when user agent is not set in the playlist
    public $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36';

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId,
        public int $playlistId,
        public string $batchNo,
        public Carbon $start,
        public bool $maxHit = false,
        public bool $isNew = false,
        public bool $runningLiveImport = true, // Default to true for live imports
        public bool $runningVodImport = true, // Default to true for VOD imports
        public ?int $syncRunId = null,
        bool $runningSeriesImport = false,
    ) {
        $this->runningSeriesImport = $runningSeriesImport;

        // Set the invalidate import settings from config
        $this->invalidateImport = config('dev.invalidate_import', null);
        $this->invalidateImportThreshold = config('dev.invalidate_import_threshold', 100);
        $this->invalidateImportGroupThreshold = config('dev.invalidate_import_group_threshold', 50);
        $this->invalidateImportSeriesThreshold = config('dev.invalidate_import_series_threshold', 100);
    }

    /**
     * Execute the job.
     */
    public function handle(GeneralSettings $settings): void
    {
        // Calculate the time taken to complete the import
        $completedIn = $this->start->diffInSeconds(now());
        $completedInRounded = round($completedIn, 2);

        // Check invalidation settings
        if ($this->invalidateImport === null) {
            // If not set via config, check the settings
            $this->invalidateImport = $settings->invalidate_import ?? false;
            $this->invalidateImportThreshold = $settings->invalidate_import_threshold ?? 100;
            $this->invalidateImportGroupThreshold = $settings->invalidate_import_group_threshold ?? 50;
            $this->invalidateImportSeriesThreshold = $settings->invalidate_import_series_threshold ?? 100;
        }

        $user = User::find($this->userId);
        $playlist = $user->playlists()->find($this->playlistId);

        // Get the removed groups (also catches groups created without a batch number, e.g. by CopyAttributesToPlaylist,
        // since NULL != $batchNo evaluates to NULL in SQL and would otherwise escape cleanup)
        $removedGroups = Group::where('custom', false)
            ->where('playlist_id', $playlist->id)
            ->where(function ($q) {
                $q->whereNull('import_batch_no')
                    ->orWhere('import_batch_no', '!=', $this->batchNo);
            });

        // Get the newly added groups
        $newGroups = $playlist->groups()->where([
            ['import_batch_no', $this->batchNo],
            ['new', true],
        ]);

        // Get the removed channels
        $removedChannels = Channel::where([
            ['playlist_id', $playlist->id],
            ['is_custom', false],
            ['import_batch_no', '!=', $this->batchNo],
        ]);

        // Null safety guard: if VOD was enabled for this run but zero VOD channels landed in the
        // new batch, the provider API likely returned an empty response rather than genuinely
        // removing all VOD content. Exclude VOD from the removal queries to prevent mass
        // churn on a temporary provider glitch.
        // --
        // NOTE: This runs regardless of sync invalidation settings, since it's a safety measure
        //       to prevent zeroing out channels on provider/API issues.
        if ($this->runningVodImport) {
            $hasVodInBatch = $playlist->channels()
                ->where('is_custom', false)
                ->where('is_vod', true)
                ->where('import_batch_no', $this->batchNo)
                ->exists();

            if (! $hasVodInBatch && $playlist->channels()->where('is_custom', false)->where('is_vod', true)->exists()) {
                Log::warning("No VOD channels found in batch {$this->batchNo} for playlist {$playlist->id}, excluding VOD from removal to prevent accidental mass deletion.");
                // For simplicity, we assume all VOD channels have is_vod=true and exclude them from removal.
                // If there are other types in the future, this logic may need to be adjusted.
                $removedGroups->where('type', '!=', 'vod');
                $removedChannels->where('is_vod', false);
            }
        }

        // Apply the same safety guard for live channels if the live import was run
        if ($this->runningLiveImport) {
            $hasLiveInBatch = $playlist->channels()
                ->where('is_custom', false)
                ->where('is_vod', false)
                ->where('import_batch_no', $this->batchNo)
                ->exists();

            if (! $hasLiveInBatch && $playlist->channels()->where('is_custom', false)->where('is_vod', false)->exists()) {
                Log::warning("No live channels found in batch {$this->batchNo} for playlist {$playlist->id}, excluding live from removal to prevent accidental mass deletion.");
                // Inverse of the VOD logic: we assume live channels are those with is_vod=false, so we exclude those from removal if the live import ran but resulted in zero live channels.
                $removedGroups->where('type', '!=', 'live');
                $removedChannels->where('is_vod', true);
            }
        }

        // Get the newly added channels
        $newChannels = $playlist->channels()->where([
            ['import_batch_no', $this->batchNo],
            ['new', true],
        ]);

        // Get the added and removed series, only when series import ran this sync (matches the seriesCleanup gate).
        // Existing series keep their original batch number, so only rows inserted this sync carry the current one.
        // Series are removed along with their whole category, so a stale category batch means its series are going.
        $newSeries = null;
        $removedSeries = null;
        if ($this->runningSeriesImport) {
            $newSeries = Series::where([
                ['playlist_id', $playlist->id],
                ['import_batch_no', $this->batchNo],
            ]);
            $removedSeries = Series::whereHas('category', function ($q) use ($playlist) {
                $q->where('playlist_id', $playlist->id)
                    ->where('import_batch_no', '!=', $this->batchNo);
            });
        }

        // See if sync logs are disabled
        $syncLogsDisabled = config('dev.disable_sync_logs', false);
        if (! $playlist->sync_logs_enabled) {
            $syncLogsDisabled = true;
        }

        // If not a new playlist create a new playlst sync status!
        if (! $this->isNew) {
            // Get counts for removed and new groups/channels
            $removedGroupCount = $removedGroups->count();
            $newGroupCount = $newGroups->count();
            $removedChannelCount = $removedChannels->count();
            $newChannelCount = $newChannels->count();
            $removedSeriesCount = $removedSeries?->count() ?? 0;
            $newSeriesCount = $newSeries?->count() ?? 0;

            $syncStats = [
                'time' => $completedIn,
                'time_rounded' => $completedInRounded,
                'removed_groups' => $removedGroupCount,
                'added_groups' => $newGroupCount,
                'removed_channels' => $removedChannelCount,
                'added_channels' => $newChannelCount,
                'removed_series' => $removedSeriesCount,
                'added_series' => $newSeriesCount,
                'max_hit' => $this->maxHit,
            ];
            $logQueries = [
                'newChannels' => $newChannels,
                'removedChannels' => $removedChannels,
                'newGroups' => $newGroups,
                'removedGroups' => $removedGroups,
                'newSeries' => $newSeries,
                'removedSeries' => $removedSeries,
            ];

            // Check if we need to invalidate the import before proceeding
            if ($this->invalidateImport) {
                // Channel threshold: only fires when the net result drops below current − threshold.
                if ($removedChannelCount > 0) {
                    $currentCount = $playlist->channels()->where('is_custom', false)->count();
                    $newCount = $currentCount + $newChannelCount - $removedChannelCount;

                    if ($newCount < ($currentCount - $this->invalidateImportThreshold)) {
                        $this->cancelImport(
                            "The channel count would have been {$newCount} after import, which is less than the current count of {$currentCount} minus the threshold of {$this->invalidateImportThreshold}.",
                            $user, $playlist, $syncLogsDisabled, $syncStats, $logQueries,
                        );

                        return;
                    }
                }

                // Group/category threshold.
                if ($removedGroupCount > $this->invalidateImportGroupThreshold) {
                    $this->cancelImport(
                        "{$removedGroupCount} groups/categories would have been removed, which exceeds the threshold of {$this->invalidateImportGroupThreshold}.",
                        $user, $playlist, $syncLogsDisabled, $syncStats, $logQueries,
                    );

                    return;
                }

                // Series threshold. Only counted when series import ran this sync: otherwise no category
                // carries the current batch and every series would look removed (seriesCleanup skips them too).
                if ($removedSeriesCount > $this->invalidateImportSeriesThreshold) {
                    $this->cancelImport(
                        "{$removedSeriesCount} series would have been removed, which exceeds the threshold of {$this->invalidateImportSeriesThreshold}.",
                        $user, $playlist, $syncLogsDisabled, $syncStats, $logQueries,
                    );

                    return;
                }
            }

            if (! $syncLogsDisabled) {
                $sync = PlaylistSyncStatus::create([
                    'name' => $playlist->name,
                    'user_id' => $user->id,
                    'playlist_id' => $playlist->id,
                    'sync_stats' => [
                        ...$syncStats,
                        'status' => 'success',
                    ],
                ]);
                $this->createSyncLogEntries($sync, $logQueries);
            }
        }

        // Clear out invalid groups/channels (if any).
        // Auto-sync config pruning and ghost-tag cleanup are intentionally deferred to
        // AutoSyncGroupsToCustomPlaylist, which runs after this job in the pipeline.
        // Pruning here would empty the groups array before that job dispatches, causing
        // the empty-groups guard to skip the job and leave ghost tags on custom playlists.
        $removedGroups->delete();
        $removedChannels->delete();

        // Finally, clean up orphaned channels (non-custom channels with null or non-existent group_id)
        Channel::where('playlist_id', $playlist->id)
            ->where('is_custom', false)
            ->whereNull('group_id')
            ->delete();

        // Clean up groups that have been soft-deleted for over 30 days
        Group::onlyTrashed()
            ->where('playlist_id', $playlist->id)
            ->where('deleted_at', '<', now()->subDays(30))
            ->forceDelete();

        // Clear out the jobs
        Job::where('batch_no', $this->batchNo)->delete();

        // Check if creating EPG
        $createEpg = $playlist->xtream
            ? ($playlist->xtream_config['import_epg'] ?? false)
            : null;
        if ($createEpg) {
            // Configure the EPG url
            try {
                $baseUrl = str($playlist->xtream_config['url'])->replace(' ', '%20')->toString();
                $username = urlencode($playlist->xtream_config['username']);
                $password = urlencode($playlist->xtream_config['password']);
                $epgUrl = "$baseUrl/xmltv.php?username=$username&password=$password";

                // Make sure EPG doesn't already exist. Prefer the provider tie:
                // a URL EPG already linked to this playlist is the one we would
                // have created, regardless of what host DNS failover left its URL
                // pointing at. Fall back to a URL match so an EPG the user
                // deliberately unlinked is not recreated on the next sync.
                $epg = $user->epgs()
                    ->where('playlist_id', $playlist->id)
                    ->where('source_type', EpgSourceType::URL)
                    ->first()
                    ?? $user->epgs()->where('url', $epgUrl)->first();
                if (! $epg) {
                    // Create EPG to trigger sync
                    $epg = $user->epgs()->create([
                        'name' => $playlist->name.' EPG',
                        'url' => $epgUrl,
                        'user_id' => $user->id,
                        'playlist_id' => $playlist->id,
                        'user_agent' => $playlist->user_agent,
                        'disable_ssl_verification' => $playlist->disable_ssl_verification,
                    ]);

                    // Create a mapping too so that once sync is complete, the EPG will be mapped to the playlist channels
                    $epg->epgMaps()->create([
                        'name' => EpgMap::buildName($epg->name, $playlist->name),
                        'playlist_id' => $playlist->id,
                        'user_id' => $user->id,
                        'uuid' => Str::orderedUuid()->toString(),
                        'status' => Status::Completed, // Mark as completed so that the EPG sync can run and map to the playlist channels once EPG sync completes
                        'progress' => 100,
                        'processing' => false,
                        'override' => false,
                        'recurring' => true, // Flag as recurring so that the EPG will be mapped to the playlist channels on every EPG sync
                        'mapped_at' => now(),
                    ]);

                    $msg = "\"{$playlist->name}\" EPG was created and will sync and map shortly.";
                    Notification::make()
                        ->success()
                        ->title('EPG created for Playlist')
                        ->body($msg)
                        ->broadcast($playlist->user)
                        ->sendToDatabase($playlist->user);
                }
            } catch (Throwable $e) {
                // EPG creation is a best-effort post-import step: surface it
                // to the user via the Filament notification center and let
                // the import complete. We intentionally do not rethrow.
                Notification::make()
                    ->danger()
                    ->title('EPG Creation Failed')
                    ->body("Failed to create EPG for \"{$playlist->name}\". Error: {$e->getMessage()}")
                    ->broadcast($playlist->user)
                    ->sendToDatabase($playlist->user);
            }
        }

        // Update the playlist
        $update = [
            'status' => Status::Completed,
            'channels' => 0,
            'synced' => now(),
            'errors' => null,
            'sync_time' => $completedIn,
            'auto_retry_503_count' => 0,
            'auto_retry_503_last_at' => null,
            'resync_attempt' => 0,
            'processing' => [
                ...$playlist->processing ?? [],
                'live_processing' => false,
                'vod_processing' => false,
                'series_processing' => false,
            ],
        ];
        if ($this->runningLiveImport) {
            $update['progress'] = 100; // Only set if Live import was run
        }
        if ($this->runningVodImport) {
            $update['vod_progress'] = 100; // Only set if VOD import was run
        }
        if ($this->runningSeriesImport) {
            $update['series_progress'] = 100; // Only set if Series import was run
        }
        $playlist->update($update);

        // Send notification
        if ($this->maxHit) {
            $limit = config('dev.max_channels');
            Notification::make()
                ->warning()
                ->title('Playlist Synced with Limit Reached')
                ->body("\"{$playlist->name}\" has been synced successfully, but the maximum import limit of {$limit} channels was reached.")
                ->broadcast($playlist->user);
            Notification::make()
                ->warning()
                ->title('Playlist Synced with Limit Reached')
                ->body("\"{$playlist->name}\" has been synced successfully, but the maximum import limit of {$limit} channels was reached. Some channels may not have been imported. Import completed in {$completedInRounded} seconds.")
                ->sendToDatabase($playlist->user);
        } else {
            Notification::make()
                ->success()
                ->title('Playlist Synced')
                ->body("\"{$playlist->name}\" has been synced successfully.")
                ->broadcast($playlist->user);
            Notification::make()
                ->success()
                ->title('Playlist Synced')
                ->body("\"{$playlist->name}\" has been synced successfully. Import completed in {$completedInRounded} seconds.")
                ->sendToDatabase($playlist->user);
        }

        // Cleanup cached EPG files
        EpgCacheService::clearPlaylistEpgCacheFile($playlist);

        // Clean up old series/categories from previous imports to prevent orphaned data.
        // This runs regardless of sync invalidation settings since it's a housekeeping step.
        //
        // Only run it when series import actually ran this sync (at least one series
        // category was dispatched). If series import is disabled, or the provider's
        // get_series_categories came back empty, skip cleanup entirely - deleting the
        // user's whole series library on a disabled import or a provider blip is exactly
        // the churn we want to avoid. A ProcessM3uImportSeriesChunk failure aborts the
        // chain before this job runs, so a partial series import can never reach here.
        if ($this->runningSeriesImport) {
            $this->seriesCleanup($playlist);
        } else {
            Log::info('ProcessM3uImportComplete: skipping seriesCleanup (no series import ran this sync)', [
                'playlist_id' => $playlist->id,
            ]);
        }

        // Hand off to the SyncPipeline.
        //
        // Two paths:
        //   1. Modern (syncRunId set): a SyncRun was created at sync kickoff with
        //      phases = [Import]. We now resolve the real post-import phases (using
        //      the populated DB) and replace the phases array, then mark Import
        //      complete so the next phase dispatches.
        //   2. Legacy (syncRunId null): one-off partial actions that dispatched
        //      ProcessM3uImport directly without going through startImport(). We
        //      build and start a pipeline from scratch here.
        //
        // By the time this job runs, both VOD channels and Series rows are populated
        // (series-discovery chunks run earlier in the chain), so FindReplace can safely
        // target series titles before STRM filenames are generated.
        $pipeline = app(SyncPipelineService::class);

        if ($this->syncRunId !== null) {
            $run = SyncRun::find($this->syncRunId);
            if ($run) {
                $pipeline->expandPipelineAfterImport($run, $playlist, $settings);
                $pipeline->completePhase($this->syncRunId, SyncRunPhase::Import);

                return;
            }

            // SyncRun vanished (shouldn't normally happen) — fall through to legacy path.
        }

        $run = $pipeline->buildPipeline($playlist, $settings);
        $pipeline->startRun($run);
    }

    /**
     * Cancel the current sync run: write a canceled log entry, reset the playlist status,
     * delete all new content from this batch, and notify the user.
     *
     * @param  array<string, mixed>  $syncStats  Base stats array (without message/status).
     * @param  array<string, Builder|Relation|null>  $logQueries  Added/removed item queries, see createSyncLogEntries().
     */
    private function cancelImport(
        string $reason,
        User $user,
        Playlist $playlist,
        bool $syncLogsDisabled,
        array $syncStats,
        array $logQueries,
    ): void {
        $message = "Playlist Sync Invalidated: {$reason}";

        if (! $syncLogsDisabled) {
            $sync = PlaylistSyncStatus::create([
                'name' => $playlist->name,
                'user_id' => $user->id,
                'playlist_id' => $playlist->id,
                'sync_stats' => [
                    ...$syncStats,
                    'message' => $message,
                    'status' => 'canceled',
                ],
            ]);

            $this->createSyncLogEntries($sync, $logQueries);
        }

        // Invalidated syncs skip the auto resync loop: an immediate retry would most likely be
        // invalidated too, so use up the attempts and wait for the next scheduled sync instead.
        $playlist->update([
            'status' => Status::Failed,
            'errors' => $message,
            'resync_attempt' => $playlist->auto_resync_retries,
            'processing' => [
                ...$playlist->processing ?? [],
                'live_processing' => false,
                'vod_processing' => false,
            ],
        ]);

        $logQueries['newGroups']->forceDelete();
        $logQueries['newChannels']->delete();
        Job::where('batch_no', $this->batchNo)->delete();

        Notification::make()
            ->danger()
            ->title('Playlist Sync Invalidated')
            ->body($message)
            ->broadcast($user)
            ->sendToDatabase($user);

        $this->sendInvalidatedAlert($playlist, $reason);
    }

    /**
     * Forward an invalidated sync to the enabled alert channels (Discord, Slack and/or Telegram).
     */
    private function sendInvalidatedAlert(Playlist $playlist, string $reason): void
    {
        try {
            if (! app(GeneralSettings::class)->alerts_on_sync_invalidated) {
                return;
            }

            $alertService = app(AlertService::class);

            if (! $alertService->isEnabled()) {
                return;
            }

            $context = ['playlist' => $playlist->name, 'playlist_id' => $playlist->id];
            $encoded = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $alertService->send("[SYNC INVALIDATED] Playlist \"{$playlist->name}\" sync was canceled: {$reason}\n```\n{$encoded}\n```");
        } catch (Throwable) {
            // Silently ignore.
        }
    }

    /**
     * Remove orphaned series categories/series/episodes from a previous import batch.
     * Series dispatch is handled in handle() so the VOD→Series ordering can be enforced.
     */
    private function seriesCleanup($playlist): void
    {
        foreach ($playlist->categories()->where('import_batch_no', '!=', $this->batchNo)->cursor() as $category) {
            $category->series()->delete(); // will cascade to episodes
            $category->delete();
        }
    }

    /**
     * Create the sync log entries for the import.
     *
     * Each query is cloned before use, so the caller can still run its deletes afterwards.
     *
     * @param  array{newChannels: Builder|Relation, removedChannels: Builder|Relation, newGroups: Builder|Relation, removedGroups: Builder|Relation, newSeries: Builder|null, removedSeries: Builder|null}  $logQueries
     */
    private function createSyncLogEntries(PlaylistSyncStatus $sync, array $logQueries): void
    {
        // Limit logged entries
        $limit = config('dev.max_channels');
        $now = now();

        $entries = [
            ['query' => $logQueries['removedGroups'], 'type' => 'group', 'status' => 'removed'],
            ['query' => $logQueries['newGroups'], 'type' => 'group', 'status' => 'added'],
            ['query' => $logQueries['removedChannels'], 'type' => 'channel', 'status' => 'removed'],
            ['query' => $logQueries['newChannels'], 'type' => 'channel', 'status' => 'added'],
            ['query' => $logQueries['removedSeries'], 'type' => 'series', 'status' => 'removed'],
            ['query' => $logQueries['newSeries'], 'type' => 'series', 'status' => 'added'],
        ];

        // Create the sync log entries
        $bulk = [];
        foreach ($entries as $entry) {
            if ($entry['query'] === null) {
                continue;
            }

            foreach ($entry['query']->clone()->limit($limit)->cursor() as $item) {
                $bulk[] = [
                    'playlist_sync_status_id' => $sync->id,
                    'name' => $entry['type'] === 'channel' ? ($item->title ?? $item->name) : $item->name,
                    'type' => $entry['type'],
                    'content_type' => match ($entry['type']) {
                        'channel' => $item->is_vod ? 'vod' : 'live',
                        'group' => $item->type === 'vod' ? 'vod' : 'live',
                        'series' => 'series',
                    },
                    'status' => $entry['status'],
                    'meta' => $item,
                    'playlist_id' => $item->playlist_id,
                    'user_id' => $item->user_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (count($bulk) >= 100) {
                    PlaylistSyncStatusLog::insert($bulk);
                    $bulk = [];
                }
            }
        }
        if (count($bulk) > 0) {
            PlaylistSyncStatusLog::insert($bulk);
        }
    }
}
