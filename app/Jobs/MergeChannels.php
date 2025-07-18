<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelFailover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Filament\Notifications\Notification;

class MergeChannels implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public $user,
        public Collection $playlists,
        public $playlistId,
        public bool $checkResolution = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $processed = 0;

        // Build playlist IDs array similar to MergeChannelsKaka
        $playlistIds = $this->playlists->map(function ($item) {
            if (is_array($item)) {
                return $item['playlist_failover_id'];
            }
            return $item;
        })->toArray();

        if ($this->playlistId) {
            $playlistIds[] = $this->playlistId;
        }

        // Get all channels from the specified playlists with user filter
        $allChannels = Channel::where('user_id', $this->user->id)
            ->whereIn('playlist_id', array_unique($playlistIds))
            ->where(function ($query) {
                // Only include channels that have a stream ID or custom stream ID
                $query->whereNotNull('stream_id_custom')->orWhereNotNull('stream_id');
            })->cursor(); // Use cursor for memory efficiency

        // Group channels by stream ID using LazyCollection to maintain memory efficiency
        $groupedChannels = $allChannels
            ->filter(function ($channel) {
                return !empty($channel->stream_id_custom) || !empty($channel->stream_id);
            })
            ->groupBy(function ($channel) {
                $streamId = $channel->stream_id_custom ?: $channel->stream_id;
                return strtolower($streamId);
            });

        $failoverPlaylistIds = $this->playlists->map(function ($item) {
            if (is_array($item)) {
                return $item['playlist_failover_id'];
            }
            return $item;
        })->toArray();

        if ($this->playlistId && !in_array($this->playlistId, $failoverPlaylistIds)) {
            $failoverPlaylistIds[] = $this->playlistId;
        }

        // Process each group of channels with the same stream ID
        foreach ($groupedChannels as $group) {
            if ($group->count() > 1) {
                $master = null;

                // Try to find master channel from preferred playlist first
                $preferredChannels = $group->where('playlist_id', $this->playlistId);
                if ($preferredChannels->isNotEmpty()) {
                    if ($this->checkResolution) {
                        // Select highest resolution from preferred playlist
                        $master = $preferredChannels->reduce(function ($highest, $channel) {
                            if (!$highest) return $channel;
                            $highestResolution = $this->getResolution($highest);
                            $currentResolution = $this->getResolution($channel);
                            return ($currentResolution > $highestResolution) ? $channel : $highest;
                        });
                    } else {
                        // Use playlist order priority - find channel from earliest playlist in order
                        $master = $preferredChannels->sortBy(function ($channel) {
                            return $this->playlists->search($channel->playlist_id);
                        })->first();
                    }
                }

                // If no master found from preferred playlist, select from all channels
                if (!$master) {
                    if ($this->checkResolution) {
                        // Select highest resolution overall
                        $master = $group->reduce(function ($highest, $channel) {
                            if (!$highest) return $channel;
                            $highestResolution = $this->getResolution($highest);
                            $currentResolution = $this->getResolution($channel);
                            return ($currentResolution > $highestResolution) ? $channel : $highest;
                        });
                    } else {
                        // Use playlist order priority
                        $master = $group->sortBy(function ($channel) {
                            return $this->playlists->search($channel->playlist_id);
                        })->first();
                    }
                }

                // Create failover relationships for remaining channels
                $failoverChannels = $group->where('id', '!=', $master->id)
                    ->whereIn('playlist_id', $failoverPlaylistIds);

                if ($this->checkResolution) {
                    $failoverChannels = $failoverChannels->sortByDesc(function ($channel) {
                        return $this->getResolution($channel);
                    });
                } else {
                    // Sort failovers by playlist order priority
                    $failoverChannels = $failoverChannels->sortBy(function ($channel) {
                        return $this->playlists->search($channel->playlist_id);
                    });
                }

                foreach ($failoverChannels as $failover) {
                    ChannelFailover::updateOrCreate(
                        ['channel_id' => $master->id, 'channel_failover_id' => $failover->id],
                        ['user_id' => $this->user->id]
                    );
                    $processed++;
                }
            }
        }
        $this->sendCompletionNotification($processed);
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

    protected function sendCompletionNotification($processed)
    {
        $body = $processed > 0 ? "Merged {$processed} channels successfully." : 'No channels were merged.';

        Notification::make()
            ->title('Merge complete')
            ->body($body)
            ->success()
            ->broadcast($this->user)
            ->sendToDatabase($this->user);
    }
}
