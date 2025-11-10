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

    // Don't retry the job on failure
    public $tries = 1;

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
                $channels = Channel::whereIn('id', $channels->pluck('id'))
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
                'progress' => $progress += 2, // start at 2%
            ]);

            // Map the channels
            $channelCount = $channels->count();
            $mappedCount = 0;
            LazyCollection::make(function () use ($channels, $epg, $settings, &$mappedCount) {
                $patterns = $settings['exclude_prefixes'] ?? [];
                $useRegex = $settings['use_regex'] ?? false;
                foreach ($channels->cursor() as $channel) {

                    // Get the title and stream id
                    $streamId = trim($channel->stream_id_custom ?? $channel->stream_id);
                    $name = trim($channel->name_custom ?? $channel->name);
                    $title = trim($channel->title_custom ?? $channel->title);

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

                    // Get the EPG channel (check for direct match first with improved logic)
                    $epgChannel = null;
                    
                    // Step 1: Try exact match on channel_id (highest priority)
                    $search1 = strtolower(trim($streamId));
                    $search2 = strtolower(trim($name));
                    $search3 = strtolower(trim($title));
                    
                    // Build search terms array (only non-empty values)
                    $searchTerms = array_filter([$search1, $search2, $search3], fn($term) => !empty($term));
                    
                    if (!empty($searchTerms)) {
                        $epgChannel = $epg->channels()
                            ->where('channel_id', '!=', '')
                            ->where(function ($query) use ($searchTerms) {
                                $first = true;
                                foreach ($searchTerms as $term) {
                                    if ($first) {
                                        $query->whereRaw('LOWER(channel_id) = ?', [$term]);
                                        $first = false;
                                    } else {
                                        $query->orWhereRaw('LOWER(channel_id) = ?', [$term]);
                                    }
                                }
                            })
                            ->select('id', 'channel_id', 'name', 'display_name')
                            ->first();
                    }

                    // Step 2: Try exact match on name/display_name if no channel_id match
                    if (!$epgChannel && !empty($searchTerms)) {
                        $epgChannel = $epg->channels()
                            ->where(function ($query) use ($searchTerms) {
                                $first = true;
                                foreach ($searchTerms as $term) {
                                    if ($first) {
                                        $query->whereRaw('LOWER(name) = ?', [$term])
                                            ->orWhereRaw('LOWER(display_name) = ?', [$term]);
                                        $first = false;
                                    } else {
                                        $query->orWhereRaw('LOWER(name) = ?', [$term])
                                            ->orWhereRaw('LOWER(display_name) = ?', [$term]);
                                    }
                                }
                            })
                            ->select('id', 'channel_id', 'name', 'display_name')
                            ->first();
                    }

                    // Step 3: If no exact match, attempt a similarity search (only for channels with significant content)
                    if (!$epgChannel) {
                        // Only run similarity search if the channel name has enough content
                        $channelNameForSearch = trim($title ?: $name);
                        if (strlen($channelNameForSearch) >= 3) {
                            // Pass the remove_quality_indicators setting to the similarity search
                            $removeQualityIndicators = $settings['remove_quality_indicators'] ?? false;
                            $epgChannel = $this->similaritySearch->findMatchingEpgChannel($channel, $epg, $removeQualityIndicators);
                        }
                    }

                    // If EPG channel found, link it to the Playlist channel
                    if ($epgChannel) {
                        $mappedCount++;
                        yield [
                            'title' => $channel->title,
                            'name' => $channel->name,
                            'group_internal' => $channel->group_internal,
                            'user_id' => $channel->user_id,
                            'playlist_id' => $channel->playlist_id,
                            'source_id' => $channel->source_id,
                            'epg_channel_id' => $epgChannel->id,
                        ];
                    }
                }
            })->chunk(50)->each(function ($chunk) use ($epg, $map, $channelCount, $batchNo) {
                $map->update(['progress' => min(99, $map->progress + (50 / $channelCount * 100))]);
                Job::create([
                    'title' => "Processing channel import for EPG: {$epg->name}",
                    'batch_no' => $batchNo,
                    'payload' => $chunk->toArray(),
                    'variables' => [
                        'epgId' => $epg->id,
                    ]
                ]);
            });

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

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("EPG mapping job failed: {$exception->getMessage()}");
    }
}
