<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Playlist;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProbeChannelStreamsComplete implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?int $playlistId,
        public ?array $channelIds,
        public int $total,
        public Carbon $start,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Count channels that were successfully probed during this run.
        $probedQuery = Channel::query()
            ->where('stream_stats_probed_at', '>=', $this->start);

        if ($this->playlistId) {
            $probedQuery->where('playlist_id', $this->playlistId);
        } elseif ($this->channelIds) {
            $probedQuery->whereIn('id', $this->channelIds);
        }

        $probed = $probedQuery->count();
        $failed = max(0, $this->total - $probed);

        Log::info("ProbeChannelStreams: Completed. Probed: {$probed}, Failed: {$failed}, Total: {$this->total}");

        $user = $this->playlistId
            ? Playlist::find($this->playlistId)?->user
            : (! empty($this->channelIds) ? Channel::find($this->channelIds[0])?->user : null);

        if (! $user) {
            return;
        }

        $body = "Probed {$probed} of {$this->total} channel(s).";
        if ($failed > 0) {
            $body .= " ({$failed} failed)";
        }

        Notification::make()
            ->success()
            ->title(__('Stream probing completed'))
            ->body($body)
            ->broadcast($user)
            ->sendToDatabase($user);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("Stream probe complete job failed: {$exception->getMessage()}");
    }
}
