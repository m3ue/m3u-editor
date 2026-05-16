<?php

namespace App\Jobs;

use App\Enums\SyncRunPhase;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SyncPipelineService;
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
        public ?int $playlistId,
        public int $total,
        public Carbon $start,
        public ?array $channelIds = null,
        public ?array $episodeIds = null,
        public ?int $notifyUserId = null,
        public ?int $syncRunId = null,
        public bool $isSeriesProbe = false,
    ) {}

    public function handle(): void
    {
        $channelQuery = Channel::query()->where('stream_stats_probed_at', '>=', $this->start);
        $episodeQuery = Episode::query()->where('stream_stats_probed_at', '>=', $this->start);

        if ($this->playlistId) {
            $channelQuery->where('playlist_id', $this->playlistId);
            $episodeQuery->where('playlist_id', $this->playlistId);
        } else {
            $channelQuery->whereIn('id', $this->channelIds ?? []);
            $episodeQuery->whereIn('id', $this->episodeIds ?? []);
        }

        $probed = $channelQuery->count() + $episodeQuery->count();
        $failed = max(0, $this->total - $probed);

        Log::info("ProbeVodStreams: Completed. Probed: {$probed}, Failed: {$failed}, Total: {$this->total}");

        $user = $this->playlistId
            ? Playlist::find($this->playlistId)?->user
            : User::find($this->notifyUserId);

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

        if ($this->syncRunId) {
            $phase = $this->isSeriesProbe ? SyncRunPhase::SeriesProbe : SyncRunPhase::VodProbe;
            app(SyncPipelineService::class)->completePhase($this->syncRunId, $phase);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error("VOD stream probe complete job failed: {$exception->getMessage()}");
    }
}
