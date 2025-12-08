<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\Job;
use App\Services\SimilaritySearchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class MapPlaylistChannelsToEpgChunk implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    public $deleteWhenMissingModels = true;

    // Timeout of 10 minutes per chunk
    public $timeout = 60 * 10;

    // Similarity search service
    protected SimilaritySearchService $similaritySearch;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $channelIds,
        public int $epgId,
        public int $epgMapId,
        public array $settings,
        public string $batchNo,
        public int $totalChannels,
    ) {
        $this->similaritySearch = new SimilaritySearchService();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Fetch the EPG
        $epg = Epg::find($this->epgId);
        if (!$epg) {
            Log::error("EPG not found: {$this->epgId}");
            return;
        }

        // Fetch the map
        $map = EpgMap::find($this->epgMapId);
        if (!$map) {
            Log::error("EPG Map not found for ID: {$this->epgMapId}");
            return;
        }

        // Fetch the channels
        $channels = Channel::whereIn('id', $this->channelIds);
        dump($channels->get());

        // Process each channel
        $patterns = $this->settings['exclude_prefixes'] ?? [];
        $useRegex = $this->settings['use_regex'] ?? false;
        $mappedChannels = [];

        foreach ($channels->cursor() as $channel) {
            // Get the title and stream id - sanitize UTF-8 immediately
            $streamId = $this->sanitizeUtf8(trim($channel->stream_id_custom ?? $channel->stream_id));
            $name = $this->sanitizeUtf8(trim($channel->name_custom ?? $channel->name));
            $title = $this->sanitizeUtf8(trim($channel->title_custom ?? $channel->title));

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

            // Get matching priority setting
            $prioritizeNameMatch = $this->settings['prioritize_name_match'] ?? false;

            // Prepare search terms
            $search1 = mb_strtolower(trim($streamId), 'UTF-8');
            $search2 = mb_strtolower(trim($name), 'UTF-8');
            $search3 = mb_strtolower(trim($title), 'UTF-8');

            // Build search terms array (only non-empty values)
            $searchTerms = array_filter([$search1, $search2, $search3], fn($term) => !empty($term));

            if ($prioritizeNameMatch) {
                // Step 1: Try exact match on name/display_name FIRST (highest priority - most specific)
                if (!empty($searchTerms)) {
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

                // Step 2: Try exact match on channel_id if no name/display_name match
                if (!$epgChannel && !empty($searchTerms)) {
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
            } else {
                // Original behavior: Try channel_id first, then name/display_name
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
            }

            // Step 3: If no exact match, attempt a similarity search (only for channels with significant content)
            if (!$epgChannel) {
                // Only run similarity search if the channel name has enough content
                $channelNameForSearch = trim($title ?: $name);
                if (strlen($channelNameForSearch) >= 3) {
                    // Pass the settings to the similarity search
                    $removeQualityIndicators = $this->settings['remove_quality_indicators'] ?? false;
                    $similarityThreshold = $this->settings['similarity_threshold'] ?? 70;
                    $fuzzyMaxDistance = $this->settings['fuzzy_max_distance'] ?? 25;
                    $exactMatchDistance = $this->settings['exact_match_distance'] ?? 8;

                    $epgChannel = $this->similaritySearch->findMatchingEpgChannel(
                        $channel,
                        $epg,
                        $removeQualityIndicators,
                        $similarityThreshold,
                        $fuzzyMaxDistance,
                        $exactMatchDistance
                    );
                }
            }

            // If EPG channel found, link it to the Playlist channel
            if ($epgChannel) {
                $mappedChannels[] = [
                    'title' => $this->sanitizeUtf8($channel->title),
                    'name' => $this->sanitizeUtf8($channel->name),
                    'group_internal' => $this->sanitizeUtf8($channel->group_internal),
                    'user_id' => $channel->user_id,
                    'playlist_id' => $channel->playlist_id,
                    'source_id' => $channel->source_id,
                    'epg_channel_id' => $epgChannel->id,
                ];
            }
        }

        // Store the mapped channels in Job records for the next stage
        if (!empty($mappedChannels)) {
            // Store in chunks of 50
            foreach (array_chunk($mappedChannels, 50) as $chunk) {
                Job::create([
                    'title' => "Processing EPG channel mapping for: {$epg->name}",
                    'batch_no' => $this->batchNo,
                    'payload' => $chunk,
                    'variables' => [
                        'epgId' => $epg->id,
                    ]
                ]);
            }
        }

        // Update progress
        $progressIncrement = (count($this->channelIds) / $this->totalChannels) * 95; // Reserve 5% for completion
        $map->update(['progress' => min(99, $map->progress + $progressIncrement)]);
    }

    /**
     * Sanitize a string to ensure valid UTF-8 encoding for PostgreSQL.
     * 
     * @param string|null $value
     * @return string|null
     */
    private function sanitizeUtf8(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Remove invalid UTF-8 sequences
        // mb_convert_encoding with 'UTF-8' to 'UTF-8' forces re-encoding and drops invalid bytes
        $sanitized = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // Alternative: Use iconv with //IGNORE to skip invalid characters
        // $sanitized = iconv('UTF-8', 'UTF-8//IGNORE', $value);

        // Remove any remaining control characters except newlines, tabs, and carriage returns
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $sanitized);

        return $sanitized;
    }
}
