<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Job;
use App\Models\PlaylistSyncStatus;
use App\Models\PlaylistSyncStatusLog;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessM3uImportComplete implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    // Make sure the process logs are cleaned up
    public int $maxLogs = 25;

    public $deleteWhenMissingModels = true;

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
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Calculate the time taken to complete the import
        $completedIn = $this->start->diffInSeconds(now());
        $completedInRounded = round($completedIn, 2);

        $user = User::find($this->userId);
        $playlist = $user->playlists()->find($this->playlistId);

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

        // If not a new playlist, create a new playlst sync status!
        if (!$this->isNew) {
            $sync = PlaylistSyncStatus::create([
                'name' => $playlist->name,
                'user_id' => $user->id,
                'playlist_id' => $playlist->id,
                'sync_stats' => [
                    'time' => $completedIn,
                    'time_rounded' => $completedInRounded,
                    'removed_groups' => $removedGroups->count(),
                    'added_groups' => $newGroups->count(),
                    'removed_channels' => $removedChannels->count(),
                    'added_channels' => $newChannels->count(),
                    'max_hit' => $this->maxHit,
                ]
            ]);

            // Create the sync log entries
            $bulk = [];
            $removedGroups->cursor()->each(function ($group) use ($sync, &$bulk) {
                $bulk[] = [
                    'playlist_sync_status_id' => $sync->id,
                    'name' => $group->name,
                    'type' => 'group',
                    'status' => 'removed',
                    'meta' => $group,
                    'playlist_id' => $group->playlist_id,
                    'user_id' => $group->user_id,
                ];
            });
            $newGroups->cursor()->each(function ($group) use ($sync, &$bulk) {
                $bulk[] = [
                    'playlist_sync_status_id' => $sync->id,
                    'name' => $group->name,
                    'type' => 'group',
                    'status' => 'added',
                    'meta' => $group,
                    'playlist_id' => $group->playlist_id,
                    'user_id' => $group->user_id,
                ];
            });
            $removedChannels->cursor()->each(function ($channel) use ($sync, &$bulk) {
                $bulk[] = [
                    'playlist_sync_status_id' => $sync->id,
                    'name' => $channel->title,
                    'type' => 'channel',
                    'status' => 'removed',
                    'meta' => $channel,
                    'playlist_id' => $channel->playlist_id,
                    'user_id' => $channel->user_id,
                ];
            });
            $newChannels->cursor()->each(function ($channel) use ($sync, &$bulk) {
                $bulk[] = [
                    'playlist_sync_status_id' => $sync->id,
                    'name' => $channel->title,
                    'type' => 'channel',
                    'status' => 'added',
                    'meta' => $channel,
                    'playlist_id' => $channel->playlist_id,
                    'user_id' => $channel->user_id,
                ];
            });
            if (!empty($bulk)) {
                foreach (array_chunk($bulk, 1000) as $chunk) {
                    usleep(10000); // Reduce the load on the database
                    PlaylistSyncStatusLog::insert($chunk);
                }
            }
        }

        // Clear out invalid groups/channels (if any)
        $removedGroups->delete();
        $removedChannels->delete();

        // Flag new groups and channels as not new
        $newGroups->update(['new' => false]);
        $newChannels->update(['new' => false]);

        // Clear out the jobs
        Job::where(['batch_no', $this->batchNo])->delete();

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
                    if (strpos($headers[0], '200') !== false) {
                        // EPG found, create it
                        $epg = $user->epgs()->create([
                            'name' => $playlist->name . ' EPG',
                            'url' => $epgUrl,
                            'user_id' => $user->id,
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
            } catch (\Exception $e) {
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

        // Clean up sync logs
        $syncStatusQuery = $playlist->syncStatuses();
        if ($syncStatusQuery->count() > $this->maxLogs) {
            $syncStatusQuery
                ->orderBy('created_at', 'asc')
                ->limit($syncStatusQuery->count() - $this->maxLogs)
                ->delete();
        }

        // Determine if syncing series metadata as well
        if ($playlist->series()->where('enabled', true)->exists()) {
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
                ->body('Fetching series metadata now. This may take a while depending on how many series you have enabled. Please check back later.')
                ->broadcast($playlist->user)
                ->sendToDatabase($playlist->user);
            return; // Exit early if series import is enabled, sync complete event will be fired after series import completes
        }

        // Fire the playlist synced event
        event(new SyncCompleted($playlist));
    }
}
