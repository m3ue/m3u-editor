<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProbeVodStreamsComplete implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    public function __construct(
        public int $playlistId,
        public int $total,
        public Carbon $start,
    ) {}

    public function handle(): void
    {
        $probedChannels = Channel::where('playlist_id', $this->playlistId)
            ->where('stream_stats_probed_at', '>=', $this->start)
            ->count();

        $probedEpisodes = Episode::where('playlist_id', $this->playlistId)
            ->where('stream_stats_probed_at', '>=', $this->start)
            ->count();

        $probed = $probedChannels + $probedEpisodes;
        $failed = max(0, $this->total - $probed);

        Log::info("ProbeVodStreams: Completed. Probed: {$probed}, Failed: {$failed}, Total: {$this->total}");

        $user = Playlist::find($this->playlistId)?->user;
        if (! $user) {
            return;
        }

        $body = "Probed {$probed} of {$this->total} VOD channel(s) and episode(s).";
        if ($failed > 0) {
            $body .= " ({$failed} failed)";
        }

        Notification::make()
            ->success()
            ->title(__('VOD stream probing completed'))
            ->body($body)
            ->broadcast($user)
            ->sendToDatabase($user);
    }

    public function failed(Throwable $exception): void
    {
        Log::error("VOD stream probe complete job failed: {$exception->getMessage()}");
    }
}
