<?php

namespace App\Jobs;

use App\Enums\Status;
use Throwable;
use Exception;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MapPlaylistChannelsToEpg implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    public $deleteWhenMissingModels = true;

    // Giving a timeout of 120 minutes to the Job to process the mapping
    public $timeout = 60 * 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int    $epg,
        public ?int   $playlist = null,
        public ?array $channels = null,
        public ?bool  $force = false,
        public ?bool  $recurring = false,
        public ?int   $epgMapId = null,
        public ?array $settings = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Flag job start time
        $start = now();
        $batchNo = Str::orderedUuid()->toString();

        // Fetch the EPG
        $epg = Epg::find($this->epg);
        if (!$epg) {
            $error = "Unable to map to the selected EPG, it no longer exists. Please select a different EPG and try again.";
            Log::error("Error processing EPG mapping: {$error}");
            return;
        }

        // Create the record
        $playlist = $this->playlist ? Playlist::find($this->playlist) : null;
        $subtext = $playlist ? ' -> ' . $playlist->name . ' mapping' : ' custom channel mapping';
        if ($this->epgMapId) {
            // Fetch and update existing map record
            $map = EpgMap::find($this->epgMapId);
            $map->update([
                'uuid' => $batchNo,
                'progress' => 0,
                'status' => Status::Processing,
                'processing' => true,
                'mapped_at' => now(),
            ]);

            // Set force to the existing map override setting if not explicitly set
            $this->force = $map->override;

            // Set channels, if set on mapping
            if ($map->channels) {
                $this->channels = $map->channels;
            }
        } else {
            $map = EpgMap::create([
                'name' => $epg->name . $subtext,
                'epg_id' => $epg->id,
                'playlist_id' => $playlist ? $playlist->id : null,
                'user_id' => $epg->user_id,
                'uuid' => $batchNo,
                'status' => Status::Processing,
                'processing' => true,
                'override' => $this->force,
                'recurring' => $this->recurring,
                'settings' => $this->settings,
                'channels' => $this->channels,
                'mapped_at' => now(),
            ]);
        }

        $settings = $map->settings ?? [];
        try {
            // Fetch the playlist (if set)
            $channels = [];
            if ($this->channels) {
                $channels = Channel::whereIn('id', $this->channels);
                $totalChannelCount = $channels->where('is_vod', false)->count();
                $mappedCount = $channels
                    ->where('is_vod', false)
                    ->whereNotNull('epg_channel_id')
                    ->count();
                $channels = Channel::whereIn('id', $this->channels)
                    ->where('is_vod', false)
                    ->when(!$this->force, function ($query) {
                        $query->where('epg_channel_id', null);
                    });
            } else if ($playlist) {
                $totalChannelCount = $playlist->channels()->where('is_vod', false)->count();
                $mappedCount = $playlist->channels()
                    ->where('is_vod', false)
                    ->whereNotNull('epg_channel_id')
                    ->count();
                $channels = $playlist->channels()
                    ->where('is_vod', false)
                    ->when(!$this->force, function ($query) {
                        $query->where('epg_channel_id', null);
                    });
            } else {
                // Error, somehow ended up here without a playlist or channels
                $error = "No channels or playlist specified for EPG mapping.";
                Log::error("Error processing EPG mapping: {$error}");
                $map->update([
                    'status' => Status::Failed,
                    'errors' => $error,
                    'total_channel_count' => 0,
                    'current_mapped_count' => 0,
                    'progress' => 100,
                    'processing' => false,
                ]);

                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$epg->name}\" mapping")
                    ->body($error)
                    ->broadcast($epg->user)
                    ->sendToDatabase($epg->user);

                return;
            }

            // Update the progress
            $progress = 0;
            $map->update([
                'total_channel_count' => $totalChannelCount,
                'current_mapped_count' => $mappedCount,
                'progress' => $progress += 3, // start at 3%
            ]);

            // Get the total channel count for processing
            $channelCount = $channels->count();

            // Create jobs array for batch processing
            $jobs = [];

            // Process channels in chunks of 50
            $chunkSize = 50;
            $channelIds = $channels->pluck('id')->toArray();
            $chunks = array_chunk($channelIds, $chunkSize);

            // Create a processing job for each chunk
            foreach ($chunks as $chunk) {
                $jobs[] = new MapPlaylistChannelsToEpgChunk(
                    channelIds: $chunk,
                    epgId: $epg->id,
                    epgMapId: $map->id,
                    settings: $settings,
                    batchNo: $batchNo,
                    totalChannels: $channelCount
                );
            }

            // After all chunks are processed, we need to process the Job records they created
            // Add a job that will gather and process those Job records
            $jobs[] = new MapEpgToChannelsBatch($batchNo, $epg->id);

            // Last job in the batch - completion
            $jobs[] = new MapEpgToChannelsComplete($epg, 0, $channelCount, 0, $batchNo, $start);

            // Dispatch the batch
            Bus::chain($jobs)
                ->onConnection('redis') // force to use redis connection
                ->onQueue('import')
                ->catch(function (Throwable $e) use ($epg, $map) {
                    $error = "Error processing \"{$epg->name}\" mapping: {$e->getMessage()}";
                    Notification::make()
                        ->danger()
                        ->title("Error processing \"{$epg->name}\" mapping")
                        ->body('Please view your notifications for details.')
                        ->broadcast($epg->user);
                    Notification::make()
                        ->danger()
                        ->title("Error processing \"{$epg->name}\" mapping")
                        ->body($error)
                        ->sendToDatabase($epg->user);
                    $map->update([
                        'status' => Status::Failed,
                        'channels' => 0, // not using...
                        'errors' => $error,
                        'progress' => 100,
                        'processing' => false,
                    ]);
                })->dispatch();
        } catch (Exception $e) {
            // Log the exception
            logger()->error("Error processing \"{$epg->name}\" mapping: {$e->getMessage()}");

            // Send notification
            Notification::make()
                ->danger()
                ->title("Error processing \"{$epg->name}\" mapping")
                ->body('Please view your notifications for details.')
                ->broadcast($epg->user);
            Notification::make()
                ->danger()
                ->title("Error processing \"{$epg->name}\" mapping")
                ->body($e->getMessage())
                ->sendToDatabase($epg->user);

            // Update the playlist
            $map->update([
                'status' => Status::Failed,
                'errors' => $e->getMessage(),
                'progress' => 100,
                'processing' => false,
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("EPG mapping job failed: {$exception->getMessage()}");
    }
}
