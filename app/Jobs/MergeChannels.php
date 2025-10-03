<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelFailover;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class MergeChannels implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public $user,
        public Collection $playlists,
        public int $playlistId,
        public bool $checkResolution = false,
        public bool $deactivateFailoverChannels = false,
        public bool $forceCompleteRemerge = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $processed = 0;
        $deactivatedCount = 0;

        // Build unified playlist IDs array and create priority lookup
        $playlistIds = $this->playlists->map(function ($item) {
            return is_array($item) ? $item['playlist_failover_id'] : $item;
        })->values();

        if ($this->playlistId) {
            $playlistIds->prepend($this->playlistId); // Add preferred playlist at the beginning
        }

        $playlistIds = $playlistIds->unique()->toArray();

        // Create playlist priority lookup for efficient sorting
        $playlistPriority = $playlistIds ? array_flip($playlistIds) : [];

        // Get existing failover channel IDs to exclude them from being masters
        $existingFailoverChannelIds = ChannelFailover::where('user_id', $this->user->id)
            ->whereHas('channelFailover', function ($query) use ($playlistIds) {
                $query->whereIn('playlist_id', $playlistIds);
            })
            ->pluck('channel_failover_id')
            ->toArray();

        // Get all channels with stream IDs in a single efficient query
        // Exclude channels that are already configured as failovers (unless we're re-merging everything)
        $shouldExcludeExistingFailovers = ! empty($existingFailoverChannelIds) && ! $this->forceCompleteRemerge;

        $allChannels = Channel::where('user_id', $this->user->id)
            ->whereIn('playlist_id', $playlistIds)
            ->where(function ($query) {
                $query->where('stream_id_custom', '!=', '')
                    ->orWhere('stream_id', '!=', '');
            })
            ->when($shouldExcludeExistingFailovers, function ($query) use ($existingFailoverChannelIds) {
                // Only exclude existing failovers if we're not forcing a complete re-merge
                $query->whereNotIn('id', $existingFailoverChannelIds);
            })
            ->cursor();

        // Group channels by stream ID using LazyCollection
        $groupedChannels = $allChannels->groupBy(function ($channel) {
            $streamId = $channel->stream_id_custom ?: $channel->stream_id;

            return strtolower(trim($streamId));
        });

        // Process each group of channels with the same stream ID
        foreach ($groupedChannels as $streamId => $group) {
            if ($group->count() <= 1) {
                continue; // Skip single channels
            }

            // Select master channel based on criteria
            $master = $this->selectMasterChannel($group, $playlistPriority);
            if (! $master) {
                continue; // Skip if no valid master found
            }

            // Create failover relationships for remaining channels
            $failoverChannels = $group->where('id', '!=', $master->id);
            if ($this->checkResolution) {
                // Sort failovers by resolution (highest first), then by playlist priority, then by ID for consistency
                $failoverChannels = $failoverChannels->sortBy([
                    fn ($channel) => -$this->getResolution($channel), // Negative for desc sort
                    fn ($channel) => $playlistPriority[$channel->playlist_id] ?? 999,
                    fn ($channel) => $channel->id,
                ]);
            } else {
                // Sort failovers by playlist priority, then by ID for consistency
                $failoverChannels = $failoverChannels->sortBy([
                    fn ($channel) => $playlistPriority[$channel->playlist_id] ?? 999,
                    fn ($channel) => $channel->id,
                ]);
            }

            // Create failover relationships using updateOrCreate for compatibility
            foreach ($failoverChannels as $failover) {
                ChannelFailover::updateOrCreate(
                    [
                        'channel_id' => $master->id,
                        'channel_failover_id' => $failover->id,
                    ],
                    ['user_id' => $this->user->id]
                );

                // Deactivate failover channel if requested
                if ($this->deactivateFailoverChannels && $failover->enabled) {
                    $failover->update(['enabled' => false]);
                    $deactivatedCount++;
                }

                $processed++;
            }
        }

        $this->sendCompletionNotification($processed, $deactivatedCount);
    }

    /**
     * Select the master channel from a group based on priority rules
     */
    private function selectMasterChannel($group, array $playlistPriority)
    {
        if ($this->checkResolution) {
            // Resolution-based selection: Find channel(s) with highest resolution
            $channelsWithResolution = $group->map(function ($channel) {
                return [
                    'channel' => $channel,
                    'resolution' => $this->getResolution($channel),
                ];
            });

            $maxResolution = $channelsWithResolution->max('resolution');
            $highestResChannels = $channelsWithResolution->where('resolution', $maxResolution)->pluck('channel');

            // If preferred playlist is set, prioritize it among highest resolution channels
            if ($this->playlistId) {
                $preferredHighRes = $highestResChannels->where('playlist_id', $this->playlistId);
                if ($preferredHighRes->isNotEmpty()) {
                    // Return first channel from preferred playlist with highest resolution (sorted by ID for consistency)
                    return $preferredHighRes->sortBy('id')->first();
                }
            }

            // No preferred playlist or none found with highest res: return first highest resolution channel
            return $highestResChannels->sortBy('id')->first();
        } else {
            // Simple selection without resolution check

            // If preferred playlist is set, try to use it first
            if ($this->playlistId) {
                $preferredChannels = $group->where('playlist_id', $this->playlistId);
                if ($preferredChannels->isNotEmpty()) {
                    // Return first channel from preferred playlist (sorted by ID for consistency)
                    return $preferredChannels->sortBy('id')->first();
                }
            }

            // No preferred playlist or none found: use playlist priority order, then ID for consistency
            return $group->sortBy([
                fn ($channel) => $playlistPriority[$channel->playlist_id] ?? 999,
                fn ($channel) => $channel->id,
            ])->first();
        }
    }

    private function getResolution($channel)
    {
        $streamStats = $channel->stream_stats;
        foreach ($streamStats as $stream) {
            if (isset($stream['stream']['codec_type']) && $stream['stream']['codec_type'] === 'video') {
                return ($stream['stream']['width'] ?? 0) * ($stream['stream']['height'] ?? 0);
            }
        }

        return 0;
    }

    protected function sendCompletionNotification($processed, $deactivatedCount = 0)
    {
        if ($processed > 0) {
            $body = "Merged {$processed} channels successfully.";
            if ($deactivatedCount > 0) {
                $body .= " {$deactivatedCount} failover channels were deactivated.";
            }
        } else {
            $body = 'No channels were merged.';
        }

        Notification::make()
            ->title('Channel merge complete')
            ->body($body)
            ->success()
            ->broadcast($this->user)
            ->sendToDatabase($this->user);
    }
}
