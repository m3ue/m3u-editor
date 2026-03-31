<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProbeChannelStreams implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 60 * 60 * 4;

    public $deleteWhenMissingModels = true;

    /**
     * @param  int|null  $playlistId  Probe all enabled live channels for this playlist
     * @param  array<int>|null  $channelIds  Probe specific channel IDs (overrides playlistId)
     * @param  int  $concurrency  Max parallel ffprobe processes
     */
    public function __construct(
        public ?int $playlistId = null,
        public ?array $channelIds = null,
        public int $concurrency = 3,
    ) {}

    public function handle(): void
    {
        $query = Channel::query();

        if ($this->channelIds) {
            $query->whereIn('id', $this->channelIds);
        } elseif ($this->playlistId) {
            $query->where('playlist_id', $this->playlistId)
                ->where('enabled', true)
                ->where('is_vod', false)
                ->where('probe_enabled', true);
        } else {
            Log::warning('ProbeChannelStreams: No playlist or channel IDs provided.');

            return;
        }

        $channels = $query->get();
        $total = $channels->count();

        if ($total === 0) {
            return;
        }

        $probed = 0;
        $failed = 0;

        foreach ($channels->chunk($this->concurrency) as $chunk) {
            foreach ($chunk as $channel) {
                $stats = $channel->probeStreamStats();

                if (! empty($stats)) {
                    $channel->updateQuietly([
                        'stream_stats' => $stats,
                        'stream_stats_probed_at' => now(),
                    ]);
                    $probed++;
                } else {
                    $failed++;
                }
            }
        }

        Log::info("ProbeChannelStreams: Completed. Probed: {$probed}, Failed: {$failed}, Total: {$total}");

        // Notify the playlist owner
        $playlist = $this->playlistId ? Playlist::find($this->playlistId) : null;
        $user = $playlist?->user ?? ($this->channelIds ? Channel::find($this->channelIds[0])?->user : null);

        if ($user) {
            Notification::make()
                ->success()
                ->title('Stream probing completed')
                ->body("Probed {$probed} of {$total} channels".($failed > 0 ? " ({$failed} failed)" : '').'.')
                ->broadcast($user)
                ->sendToDatabase($user);
        }
    }
}
