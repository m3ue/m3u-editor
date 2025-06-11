<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Services\SimilaritySearchService;
use Throwable;
use Exception;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\Job;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

class MapPlaylistChannelsToEpg implements ShouldQueue
{
    use Queueable;

    public $deleteWhenMissingModels = true;

    // Giving a timeout of 30 minutes to the Job to process the mapping
    public $timeout = 60 * 30;

    // Similarity search service
    protected SimilaritySearchService $similaritySearch;

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
    ) {
        $this->similaritySearch = new SimilaritySearchService();
    }

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
            $map = EpgMap::find($this->epgMapId);
            $map->update([
                'uuid' => $batchNo,
                'progress' => 0,
                'status' => Status::Processing,
                'processing' => true,
                'mapped_at' => now(),
            ]);
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
                'mapped_at' => now(),
            ]);
        }

        $settings = $map->settings ?? [];
        dump($settings);
        try {
            // Fetch the playlist (if set)
            $channels = [];
            if ($this->channels) {
                $channels = Channel::whereIn('id', $this->channels)
                    ->when(!$this->force, function ($query) {
                        $query->where('epg_channel_id', null);
                    })->cursor();
            } else if ($playlist) {
                $channels = $playlist->channels()
                    ->when(!$this->force, function ($query) {
                        $query->where('epg_channel_id', null);
                    })->cursor();
            }

            // Update the progress
            $progress = 0;
            $map->update(['progress' => $progress += 2]); // start at 2%

            // Map the channels
            $channelCount = 0;
            $mappedCount = 0;
            LazyCollection::make(function () use ($channels, $epg, $settings, &$channelCount, &$mappedCount) {
                $patterns = $settings['exclude_prefixes'] ?? [];
                $useRegex = $settings['use_regex'] ?? false;
                foreach ($channels as $channel) {
                    // Increment counter
                    $channelCount++;

                    // Get the title and stream id
                    $streamId = trim($channel->stream_id);
                    $name = trim($channel->name);
                    $title = trim($channel->title ?? $channel->title);

                    dump([
                        'stream_id' => $streamId,
                        'name' => $name,
                        'title' => $title,
                        'patterns' => $patterns,
                        'useRegex' => $useRegex,
                    ]);

                    // Get cleaned title and stream id
                    if (!empty($patterns)) {
                        foreach ($patterns as $pattern) {
                            if ($useRegex) {
                                // Escape existing delimiters in user input
                                $delimiter = '/';
                                $escapedPattern = str_replace($delimiter, '\\' . $delimiter, $pattern);
                                $finalPattern = $delimiter . $escapedPattern . $delimiter . 'u';

                                // Use regex to remove the prefix
                                if (preg_match($finalPattern, $streamId, $matches)) {
                                    $streamId = preg_replace($finalPattern, '', $streamId);
                                }
                                if (preg_match($finalPattern, $name, $matches)) {
                                    $name = preg_replace($finalPattern, '', $name);
                                }
                                if (preg_match($finalPattern, $title, $matches)) {
                                    $title = preg_replace($finalPattern, '', $title);
                                }
                            } else {
                                // Use simple string prefix matching
                                if (str_starts_with($streamId, $pattern)) {
                                    $streamId = substr($streamId, strlen($pattern));
                                }
                                if (str_starts_with($name, $pattern)) {
                                    $name = substr($name, strlen($pattern));
                                }
                                if (str_starts_with($title, $pattern)) {
                                    $title = substr($title, strlen($pattern));
                                }
                            }
                        }
                    }

                    dump([
                        'cleaned_stream_id' => $streamId,
                        'cleaned_name' => $name,
                        'cleaned_title' => $title,
                    ]);

                    // Get the EPG channel (check for direct match first)
                    $epgChannel = $epg->channels()
                        ->where('channel_id', '!=', '')
                        ->where(function ($sub) use ($streamId, $name, $title) {
                            $search1 = strtolower($streamId);
                            $search2 = strtolower($name);
                            $search3 = strtolower($title);
                            return $sub
                                ->whereRaw('LOWER(channel_id) = ?', [$search1])
                                ->orWhereRaw('LOWER(channel_id) = ?', [$search2])
                                ->orWhereRaw('LOWER(channel_id) = ?', [$search3]);
                        })
                        ->select('id', 'channel_id')
                        ->first();

                    // Of no direct match, attempt a similarity search
                    if (!$epgChannel) {
                        $epgChannel = $this->similaritySearch->findMatchingEpgChannel($channel, $epg);
                    }

                    // If EPG channel found, link it to the Playlist channel
                    if ($epgChannel) {
                        $mappedCount++;
                        yield [
                            'title' => $channel->title,
                            'name' => $channel->name,
                            'group_internal' => $channel->group_internal,
                            'playlist_id' => $channel->playlist_id,
                            'user_id' => $channel->user_id,
                            'epg_channel_id' => $epgChannel->id,
                        ];
                    }
                }
            })->chunk(50)->each(function ($chunk) use ($epg, $batchNo) {
                Job::create([
                    'title' => "Processing channel import for EPG: {$epg->name}",
                    'batch_no' => $batchNo,
                    'payload' => $chunk->toArray(),
                    'variables' => [
                        'epgId' => $epg->id,
                    ]
                ]);
            });

            // Update the progress
            $map->update(['progress' => $progress += 20]);

            // Get the jobs for the batch
            $jobs = [];
            $batchCount = Job::where('batch_no', $batchNo)->count();
            $jobsBatch = Job::where('batch_no', $batchNo)->select('id')->cursor();
            $jobsBatch->chunk(50)->each(function ($chunk) use (&$jobs, $batchCount, $batchNo) {
                $jobs[] = new MapEpgToChannels($chunk->pluck('id')->toArray(), $batchCount, $batchNo);
            });

            // Last job in the batch
            $jobs[] = new MapEpgToChannelsComplete($epg, $batchCount, $channelCount, $mappedCount, $batchNo, $start);

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
}
