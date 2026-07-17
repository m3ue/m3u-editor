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
        $this->similaritySearch = new SimilaritySearchService;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Fetch the EPG
        $epg = Epg::find($this->epgId);
        if (! $epg) {
            Log::error("EPG not found: {$this->epgId}");

            return;
        }

        // Fetch the map
        $map = EpgMap::find($this->epgMapId);
        if (! $map) {
            Log::error("EPG Map not found for ID: {$this->epgMapId}");

            return;
        }

        // Fetch the channels
        $channels = Channel::whereIn('id', $this->channelIds);

        // Process each channel
        $skipMissing = $this->settings['skip_missing'] ?? false;
        $setEpgIcon = $this->settings['set_epg_icon'] ?? false;
        $mappedChannels = [];

        foreach ($channels->cursor() as $channel) {
            // Get the title and stream id - sanitize UTF-8 immediately
            $streamId = $this->similaritySearch->cleanNameForMatching(
                $channel->stream_id_custom ?? $channel->stream_id,
                $this->settings,
            );

            if ($skipMissing && empty($streamId)) {
                // Skip channels without stream ID if the setting is enabled
                continue;
            }

            $result = $this->similaritySearch->findEpgChannelCandidatesUsingSettings($channel, $epg, $this->settings);
            $epgChannel = $result['automatic_match'];

            // If EPG channel found, link it to the Playlist channel
            if ($epgChannel) {
                if ($setEpgIcon) {
                    $channel->logo_type = 'epg';
                }

                $mappedChannels[] = [
                    'title' => $this->sanitizeUtf8($channel->title),
                    'name' => $this->sanitizeUtf8($channel->name),
                    'group_internal' => $this->sanitizeUtf8($channel->group_internal),
                    'user_id' => $channel->user_id,
                    'playlist_id' => $channel->playlist_id,
                    'source_id' => $channel->source_id,
                    'epg_channel_id' => $epgChannel->id,
                    'logo_type' => $channel->logo_type,
                ];
            }
        }

        // Store the mapped channels in Job records for the next stage
        if (! empty($mappedChannels)) {
            // Store in chunks of 50
            foreach (array_chunk($mappedChannels, 50) as $chunk) {
                Job::create([
                    'title' => "Processing EPG channel mapping for: {$epg->name}",
                    'batch_no' => $this->batchNo,
                    'payload' => $chunk,
                    'variables' => [
                        'epgId' => $epg->id,
                    ],
                ]);
            }
        }

        // Update progress
        $progressIncrement = (count($this->channelIds) / $this->totalChannels) * 95; // Reserve 5% for completion
        $map->update(['progress' => min(99, $map->progress + $progressIncrement)]);
    }

    /**
     * Extract a North American TV station callsign from parentheses in a channel name.
     *
     * Matches FCC-format callsigns (US: K/W prefix) and CRTC-format (Canada: C prefix),
     * with optional digital suffixes (-DT, -LD, -CD, -HD, -TV and subchannel variants
     * like -DT2). This allowlist approach avoids maintaining a blocklist of non-callsign
     * tokens (quality flags, country codes, feed labels, etc.).
     *
     * Examples:
     *   "US: CBS 13 (KOVR) STOCKTON HD"  → "KOVR"
     *   "US: CBS 6 (WKMG-DT) ORLANDO HD" → "WKMG-DT"
     *   "US: FOX (WLOX-DT2) BILOXI HD"   → "WLOX-DT2"
     */
    protected function extractCallsign(string $name): ?string
    {
        return $this->similaritySearch->extractCallsign($name);
    }

    /**
     * Sanitize a string to ensure valid UTF-8 encoding for PostgreSQL.
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
