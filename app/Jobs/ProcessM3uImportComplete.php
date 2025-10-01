<?php

namespace App\Jobs;

use Exception;
use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Job;
use App\Models\PlaylistSyncStatus;
use App\Models\PlaylistSyncStatusLog;
use App\Models\User;
use App\Services\EpgCacheService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessM3uImportComplete implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    // Giving a timeout of 10 minutes to the Job to process the file
    public $timeout = 60 * 10;

    // Make sure the process logs are cleaned up
    public int $maxLogs = 25;

    // Delete the job when the model is missing
    public $deleteWhenMissingModels = true;

    // Whether to invalidate the import if the number of new channels is less than the current count
    public $invalidateImport = false;
    public $invalidateImportThreshold = 100; // Default threshold for invalidating import

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
    ) {
        // Set the invalidate import settings from config
        $this->invalidateImport = config('dev.invalidate_import', null);
        $this->invalidateImportThreshold = config('dev.invalidate_import_threshold', 100);
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
        }

        $user = User::find($this->userId);
        $playlist = $user->playlists()->find($this->playlistId);

        // Get the removed groups
        $removedGroups = Group::where([
            ['custom', false],
            ['playlist_id', $playlist->id],
            ['import_batch_no', '!=', $this->batchNo],
        ]);

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

        // Get the newly added channels
        $newChannels = $playlist->channels()->where([
            ['import_batch_no', $this->batchNo],
            ['new', true],
        ]);

        // See if sync logs are disabled
        $syncLogsDisabled = config('dev.disable_sync_logs', false);
        if (!$playlist->sync_logs_enabled) {
            $syncLogsDisabled = true;
        }

        // If not a new playlist create a new playlst sync status!
        if (!$this->isNew) {
            // Get counts for removed and new groups/channels
            $removedGroupCount = $removedGroups->count();
            $newGroupCount = $newGroups->count();
            $removedChannelCount = $removedChannels->count();
            $newChannelCount = $newChannels->count();

            // Check if we need to invalidate the import before proceeding
            if ($this->invalidateImport) {
                // Only invalidate if there are channels being removed
                if ($removedChannelCount > 0) {
                    $currentCount = $playlist->channels()
                        ->where('is_custom', false)
                        ->count();

                    // See how many new channels there will be after the import
                    $newCount = $currentCount + $newChannelCount - $removedChannelCount;

                    // If the new count will be less than the current count (minus the threshold), invalidate the import
                    if ($newCount < ($currentCount - $this->invalidateImportThreshold)) {
                        $message = "Playlist Sync Invalidated: The channel count would have been {$newCount} after import, which is less than the current count of {$currentCount} minus the threshold of {$this->invalidateImportThreshold}.";
                        if (!$syncLogsDisabled) {
                            $sync = PlaylistSyncStatus::create([
                                'name' => $playlist->name,
                                'user_id' => $user->id,
                                'playlist_id' => $playlist->id,
                                'sync_stats' => [
                                    'time' => $completedIn,
                                    'time_rounded' => $completedInRounded,
                                    'removed_groups' => $removedGroupCount,
                                    'added_groups' => $newGroupCount,
                                    'removed_channels' => $removedChannelCount,
                                    'added_channels' => $newChannelCount,
                                    'max_hit' => $this->maxHit,
                                    'message' => $message,
                                    'status' => 'canceled',
                                ]
                            ]);
                            $this->createSyncLogEntries(
                                $sync,
                                $newChannels,
                                $removedChannels,
                                $newGroups,
                                $removedGroups
                            );
                        }

                        // Invalidate the import
                        $playlist->update([
                            'status' => Status::Failed,
                            'errors' => $message,
                            'processing' => false,
                        ]);

                        // Cleanup the any new groups/channels
                        $newGroups->delete();
                        $newChannels->delete();

                        // Clear out the jobs
                        Job::where('batch_no', $this->batchNo)->delete();

                        // Notify the user
                        Notification::make()
                            ->danger()
                            ->title('Playlist Sync Invalidated')
                            ->body($message)
                            ->broadcast($user)
                            ->sendToDatabase($user);
                        return;
                    }
                }
            }

            if (!$syncLogsDisabled) {
                $sync = PlaylistSyncStatus::create([
                    'name' => $playlist->name,
                    'user_id' => $user->id,
                    'playlist_id' => $playlist->id,
                    'sync_stats' => [
                        'time' => $completedIn,
                        'time_rounded' => $completedInRounded,
                        'removed_groups' => $removedGroupCount,
                        'added_groups' => $newGroupCount,
                        'removed_channels' => $removedChannelCount,
                        'added_channels' => $newChannelCount,
                        'max_hit' => $this->maxHit,
                        'status' => 'success',
                    ]
                ]);
                $this->createSyncLogEntries(
                    $sync,
                    $newChannels,
                    $removedChannels,
                    $newGroups,
                    $removedGroups
                );
            }
        }

        // Clear out invalid groups/channels (if any)
        $removedGroups->delete();
        $removedChannels->delete();

        // Flag new groups and channels as not new
        $newGroups->update(['new' => false]);
        $newChannels->update(['new' => false]);

        // Clear out the jobs
        Job::where('batch_no', $this->batchNo);

        // Check if creating EPG
        $createEpg = $playlist->xtream
            ? ($playlist->xtream_config['import_epg'] ?? false)
            : null;
        if ($createEpg) {
            // Configure the EPG url
            try {
                $baseUrl = str($playlist->xtream_config['url'])->replace(' ', '%20')->toString();
                $username = urlencode($playlist->xtream_config['username']);
                $password = $playlist->xtream_config['password'];
                $epgUrl = "$baseUrl/xmltv.php?username=$username&password=$password";

                // Make sure EPG doesn't already exist
                $epg = $user->epgs()->where('url', $epgUrl)->first();
                if (!$epg) {
                    $headers = @get_headers($epgUrl);
                    if (
                        strpos($headers[0], '200') !== false ||
                        strpos($headers[0], '301') !== false ||
                        strpos($headers[0], '302') !== false
                    ) {
                        // EPG found, create it
                        $epg = $user->epgs()->create([
                            'name' => $playlist->name . ' EPG',
                            'url' => $epgUrl,
                            'user_id' => $user->id,
                            'user_agent' => $playlist->user_agent
                        ]);
                        $msg = "\"{$playlist->name}\" EPG was created and is syncing now.";
                        Notification::make()
                            ->success()
                            ->title('EPG found for Playlist')
                            ->body($msg)
                            ->broadcast($playlist->user)
                            ->sendToDatabase($playlist->user);
                    } else {
                        $msg = "\"{$playlist->name}\" EPG not found. Playlist was configured to auto-download EPG but no EPG was found using at the following url: \"$epgUrl\"";
                        Notification::make()
                            ->warning()
                            ->title('No EPG found for Playlist')
                            ->body($msg)
                            ->broadcast($playlist->user)
                            ->sendToDatabase($playlist->user);
                    }
                }
            } catch (Exception $e) {
                // Handle any exceptions that occur during EPG creation
                Notification::make()
                    ->danger()
                    ->title('EPG Creation Failed')
                    ->body("Failed to create EPG for \"{$playlist->name}\". Error: {$e->getMessage()}")
                    ->broadcast($playlist->user)
                    ->sendToDatabase($playlist->user);
            }
        }

        // Update the playlist
        $playlist->update([
            'status' => Status::Completed,
            'channels' => 0, // not using...
            'synced' => now(),
            'errors' => null,
            'sync_time' => $completedIn,
            'progress' => 100,
            'processing' => false
        ]);

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

        // Clean up sync logs
        $syncStatusQuery = $playlist->syncStatusesUnordered();
        if ($syncStatusQuery->count() > $this->maxLogs) {
            $syncStatusQuery
                ->orderBy('created_at', 'asc')
                ->limit($syncStatusQuery->count() - $this->maxLogs)
                ->delete();
        }

        // Determine if syncing series metadata as well
        $syncSeriesMetadata = $playlist->auto_fetch_series_metadata
            && $playlist->series()->where('enabled', true)->exists();

        if ($syncSeriesMetadata) {
            // Process series import
            dispatch(new ProcessM3uImportSeries(
                playlist: $playlist,
                force: true,
                isNew: $this->isNew,
                batchNo: $this->batchNo,
            ));
            Notification::make()
                ->info()
                ->title('Fetching Series Metadata')
                ->body('Fetching series metadata now. This may take a while depending on how many series you have enabled. If stream file syncing is enabled, it will also be ran. Please check back later.')
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        }

        $syncVod = ($playlist->auto_sync_vod_stream_files || $playlist->auto_fetch_vod_metadata)
            && $playlist->channels()->where([
                ['enabled', true],
                ['is_vod', true]
            ])->exists();

        if ($syncVod) {
            // Check if syncing stream files too
            $syncStreamFiles = $playlist->auto_sync_vod_stream_files;
            $syncMetaData = $playlist->auto_fetch_vod_metadata;
            if ($syncStreamFiles && $syncMetaData) {
                $message = "Syncing VOD stream files and fetching VOD metadata now. Please check back later.";
            } elseif ($syncStreamFiles) {
                $message = "Syncing VOD stream files now. Please check back later.";
            } elseif ($syncMetaData) {
                $message = "Fetching VOD metadata now. Please check back later.";
            }

            // Process VOD import
            dispatch(new ProcessM3uImportVod(
                playlist: $playlist,
                isNew: $this->isNew,
                batchNo: $this->batchNo,
            ));
            Notification::make()
                ->info()
                ->title('Syncing VOD Channels')
                ->body($message)
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
        }

        if ($syncSeriesMetadata) {
            return; // Exit early if series import is enabled, sync complete event will be fired after series import completes
        }

        // Fire the playlist synced event
        event(new SyncCompleted($playlist));
    }

    /**
     * Create the sync log entries for the import.
     *
     * @param PlaylistSyncStatus $sync
     * @param $newChannels
     * @param $removedChannels
     * @param $newGroups
     * @param $removedGroups
     */
    private function createSyncLogEntries(
        $sync,
        $newChannels,
        $removedChannels,
        $newGroups,
        $removedGroups,
    ) {
        // Limit logged entries
        $limit = config('dev.max_channels');

        // Create the sync log entries
        $bulk = [];
        $removedGroups->limit($limit)->cursor()->each(function ($group) use ($sync, &$bulk) {
            $bulk[] = [
                'playlist_sync_status_id' => $sync->id,
                'name' => $group->name,
                'type' => 'group',
                'status' => 'removed',
                'meta' => $group,
                'playlist_id' => $group->playlist_id,
                'user_id' => $group->user_id,
            ];
            if (count($bulk) >= 100) {
                PlaylistSyncStatusLog::insert($bulk);
                $bulk = [];
            }
        });
        $newGroups->limit($limit)->cursor()->each(function ($group) use ($sync, &$bulk) {
            $bulk[] = [
                'playlist_sync_status_id' => $sync->id,
                'name' => $group->name,
                'type' => 'group',
                'status' => 'added',
                'meta' => $group,
                'playlist_id' => $group->playlist_id,
                'user_id' => $group->user_id,
            ];
            if (count($bulk) >= 100) {
                PlaylistSyncStatusLog::insert($bulk);
                $bulk = [];
            }
        });
        $removedChannels->limit($limit)->cursor()->each(function ($channel) use ($sync, &$bulk) {
            $bulk[] = [
                'playlist_sync_status_id' => $sync->id,
                'name' => $channel->title,
                'type' => 'channel',
                'status' => 'removed',
                'meta' => $channel,
                'playlist_id' => $channel->playlist_id,
                'user_id' => $channel->user_id,
            ];
            if (count($bulk) >= 100) {
                PlaylistSyncStatusLog::insert($bulk);
                $bulk = [];
            }
        });
        $newChannels->limit($limit)->cursor()->each(function ($channel) use ($sync, &$bulk) {
            $bulk[] = [
                'playlist_sync_status_id' => $sync->id,
                'name' => $channel->title,
                'type' => 'channel',
                'status' => 'added',
                'meta' => $channel,
                'playlist_id' => $channel->playlist_id,
                'user_id' => $channel->user_id,
            ];
            if (count($bulk) >= 100) {
                PlaylistSyncStatusLog::insert($bulk);
                $bulk = [];
            }
        });
        if (count($bulk) > 0) {
            PlaylistSyncStatusLog::insert($bulk);
        }
    }
}
