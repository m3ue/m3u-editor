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

    public $user;
    public $playlistId;

    /**
     * Create a new job instance.
     */
    public function __construct(public Collection $channels, $user, $playlistId = null)
    {
        $this->user = $user;
        $this->playlistId = $playlistId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $processed = 0;
        if ($this->channels->count() > 1) {
            $preferredChannels = $this->channels;
            if ($this->playlistId) {
                $preferredChannels = $this->channels->where('playlist_id', $this->playlistId);
            }

            // Find the channel with the highest resolution in the preferred playlist
            $master = $preferredChannels->reduce(function ($highest, $channel) {
                if (!$highest) {
                    return $channel;
                }

                $highestResolution = $this->getResolution($highest);
                $currentResolution = $this->getResolution($channel);

                return ($currentResolution > $highestResolution) ? $channel : $highest;
            });

            // The rest are failovers
            foreach ($this->channels as $failover) {
                if ($failover->id !== $master->id) {
                    ChannelFailover::updateOrCreate(
                        [
                            'channel_id' => $master->id,
                            'channel_failover_id' => $failover->id,
                        ],
                        [
                            'user_id' => $master->user_id,
                        ]
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
            ->sendToDatabase($this->user);
    }
}
