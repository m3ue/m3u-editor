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

class UnmergeChannels implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public $user,
        public $playlistId = null,
        public $groupId = null,
        public bool $reactivateChannels = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $reactivatedCount = 0;

        if ($this->playlistId) {
            // Get the playlist channels IDs
            $channelIds = Channel::where('playlist_id', $this->playlistId);

            // Need to efficiently work through potentially 100s of thousands of channels
            // so we use cursor() to avoid loading everything into memory at once
            $idsToDelete = [];
            foreach ($channelIds->cursor() as $channel) {
                // Bulk delete in chunks of 100
                $idsToDelete[] = $channel->id;
                if (count($idsToDelete) >= 100) {
                    // Reactivate failover channels if requested
                    if ($this->reactivateChannels) {
                        $reactivatedCount += $this->reactivateFailoverChannels($idsToDelete);
                    }
                    ChannelFailover::whereIn('channel_id', $idsToDelete)->delete();
                    $idsToDelete = [];
                }
            }

            // Clean up any remaining IDs
            if (count($idsToDelete) > 0) {
                if ($this->reactivateChannels) {
                    $reactivatedCount += $this->reactivateFailoverChannels($idsToDelete);
                }
                ChannelFailover::whereIn('channel_id', $idsToDelete)->delete();
            }
        } elseif ($this->groupId) {
            // Get the group channels IDs
            $channelIds = Channel::where('group_id', $this->groupId);

            // Should be much less channels than playlist unmerge but still use cursor() for safety
            $idsToDelete = [];
            foreach ($channelIds->cursor() as $channel) {
                // Bulk delete in chunks of 100
                $idsToDelete[] = $channel->id;
                if (count($idsToDelete) >= 100) {
                    if ($this->reactivateChannels) {
                        $reactivatedCount += $this->reactivateFailoverChannels($idsToDelete);
                    }
                    ChannelFailover::whereIn('channel_id', $idsToDelete)->delete();
                    $idsToDelete = [];
                }
            }

            // Clean up any remaining IDs
            if (count($idsToDelete) > 0) {
                if ($this->reactivateChannels) {
                    $reactivatedCount += $this->reactivateFailoverChannels($idsToDelete);
                }
                ChannelFailover::whereIn('channel_id', $idsToDelete)->delete();
            }
        } else {
            // Delete all user failovers if no playlist is specified
            if ($this->reactivateChannels) {
                // Reactivate all failover channels for this user
                $failoverChannelIds = ChannelFailover::where('user_id', $this->user->id)
                    ->pluck('channel_failover_id')
                    ->toArray();
                if (! empty($failoverChannelIds)) {
                    $reactivatedCount = Channel::whereIn('id', $failoverChannelIds)
                        ->where('enabled', false)
                        ->update(['enabled' => true]);
                }
            }
            ChannelFailover::where('user_id', $this->user->id)->delete();
        }

        $this->sendCompletionNotification($reactivatedCount);
    }

    /**
     * Reactivate failover channels that were disabled during merge
     */
    protected function reactivateFailoverChannels(array $masterChannelIds): int
    {
        // Get all failover channel IDs for the given master channels
        $failoverChannelIds = ChannelFailover::whereIn('channel_id', $masterChannelIds)
            ->pluck('channel_failover_id')
            ->toArray();

        if (empty($failoverChannelIds)) {
            return 0;
        }

        // Reactivate disabled failover channels
        return Channel::whereIn('id', $failoverChannelIds)
            ->where('enabled', false)
            ->update(['enabled' => true]);
    }

    protected function sendCompletionNotification(int $reactivatedCount = 0)
    {
        if ($this->playlistId) {
            $message = 'Channels in the specified playlist have been unmerged successfully.';
        } elseif ($this->groupId) {
            $message = 'Channels in the specified group have been unmerged successfully.';
        } else {
            $message = 'All channels have been unmerged successfully.';
        }

        if ($reactivatedCount > 0) {
            $message .= " {$reactivatedCount} channel(s) were reactivated.";
        }

        Notification::make()
            ->title('Unmerge complete')
            ->body($message)
            ->success()
            ->broadcast($this->user)
            ->sendToDatabase($this->user);
    }
}
