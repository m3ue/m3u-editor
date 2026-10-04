<?php

namespace App\Jobs;

use App\Enums\SyncRunPhase;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SyncPipelineService;
use App\Support\ProbeCircuitBreaker;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProbeStreamsComplete implements ShouldQueue
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
        public ?string $probeRunKey = null,
        public int $failureThreshold = 0,
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

        // Failed probes and inferred (copied) series stats also stamp stream_stats_probed_at,
        // so only rows with stats of their own count as probed.
        $probed = (clone $channelQuery)->whereNotNull('stream_stats')->count()
            + (clone $episodeQuery)->whereNotNull('stream_stats')->whereNull('stream_stats_inferred_from_id')->count();
        $inferred = (clone $episodeQuery)->whereNotNull('stream_stats_inferred_from_id')->count();
        $failed = max(0, $this->total - $probed);

        $breaker = ProbeCircuitBreaker::forRun($this->probeRunKey, $this->failureThreshold);
        $breakerSummary = $breaker?->summary();
        $breaker?->forget();

        Log::info("ProbeStreams: Completed. Probed: {$probed}, Failed: {$failed}, Inferred: {$inferred}, Total: {$this->total}, Paused: ".(($breakerSummary['tripped'] ?? false) ? 'yes' : 'no'));

        $user = $this->playlistId
            ? Playlist::find($this->playlistId)?->user
            : User::find($this->notifyUserId);

        if ($user) {
            $this->notify($user, $probed, $failed, $inferred, $breakerSummary);
        }

        if ($this->syncRunId) {
            $phase = $this->isSeriesProbe ? SyncRunPhase::SeriesProbe : SyncRunPhase::VodProbe;
            app(SyncPipelineService::class)->completePhase($this->syncRunId, $phase);
        }
    }

    /**
     * @param  array{attempted: int, failed: int, tripped: bool}|null  $breakerSummary
     */
    private function notify(User $user, int $probed, int $failed, int $inferred, ?array $breakerSummary): void
    {
        $inferredNote = $inferred > 0
            ? ' '.__(':count episode(s) reused stream info from a probed episode of the same season or series.', ['count' => $inferred])
            : '';

        if ($breakerSummary['tripped'] ?? false) {
            Notification::make()
                ->warning()
                ->title(__('VOD stream probing paused'))
                ->body(__('Stopped after :failed of :attempted probes failed (more than :threshold%). The provider may be unreachable. Streams that were not probed will be tried again on the next sync.', [
                    'failed' => $breakerSummary['failed'],
                    'attempted' => $breakerSummary['attempted'],
                    'threshold' => $this->failureThreshold,
                ]).$inferredNote)
                ->broadcast($user)
                ->sendToDatabase($user);

            return;
        }

        $body = $failed > 0
            ? __('Probed :probed of :total VOD channel(s) and episode(s). (:failed failed)', ['probed' => $probed, 'total' => $this->total, 'failed' => $failed])
            : __('Probed :probed of :total VOD channel(s) and episode(s).', ['probed' => $probed, 'total' => $this->total]);

        Notification::make()
            ->success()
            ->title(__('VOD stream probing completed'))
            ->body($body.$inferredNote)
            ->broadcast($user)
            ->sendToDatabase($user);
    }

    public function failed(Throwable $exception): void
    {
        Log::error("Stream probe complete job failed: {$exception->getMessage()}");
    }
}
