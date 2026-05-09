<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aggregated completion notification for manual bulk-probe actions.
 *
 * Bulk-probe UI actions dispatch many ProbeVodStreamsChunk jobs via Bus::batch().
 * The batch's then() callback queues this job once all chunks finish, so we count
 * how many of the originally selected records were actually persisted with fresh
 * stream stats during this run.
 *
 * This replaces the previous per-chunk notification, which fired on the last
 * dispatched chunk and reported only that chunk's count, producing incorrect
 * totals like "3 of 156" while the rest of the batch was still running.
 */
class ProbeManualComplete implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $deleteWhenMissingModels = true;

    /**
     * @param  array<int>  $channelIds  Channel IDs included in the batch (may be empty)
     * @param  array<int>  $episodeIds  Episode IDs included in the batch (may be empty)
     */
    public function __construct(
        public int $notifyUserId,
        public string $notifyLabel,
        public int $total,
        public Carbon $start,
        public array $channelIds = [],
        public array $episodeIds = [],
    ) {}

    public function handle(): void
    {
        $probedChannels = $this->channelIds
            ? Channel::whereIn('id', $this->channelIds)
                ->where('stream_stats_probed_at', '>=', $this->start)
                ->count()
            : 0;

        $probedEpisodes = $this->episodeIds
            ? Episode::whereIn('id', $this->episodeIds)
                ->where('stream_stats_probed_at', '>=', $this->start)
                ->count()
            : 0;

        $probed = $probedChannels + $probedEpisodes;
        $failed = max(0, $this->total - $probed);

        Log::info("ProbeManualComplete[{$this->notifyLabel}]: Probed {$probed}/{$this->total} (failed={$failed})");

        $user = User::find($this->notifyUserId);
        if (! $user) {
            return;
        }

        $body = __(':probed of :total stream(s) probed successfully.', [
            'probed' => $probed,
            'total' => $this->total,
        ]);
        if ($failed > 0) {
            $body .= ' ('.__(':failed failed', ['failed' => $failed]).')';
        }

        Notification::make()
            ->success()
            ->title($this->notifyLabel.' '.__('complete'))
            ->body($body)
            ->broadcast($user)
            ->sendToDatabase($user);
    }

    public function failed(Throwable $exception): void
    {
        Log::error("ProbeManualComplete failed: {$exception->getMessage()}");

        $user = User::find($this->notifyUserId);
        if ($user) {
            Notification::make()
                ->danger()
                ->title(__('Stream probing failed'))
                ->body($exception->getMessage())
                ->broadcast($user)
                ->sendToDatabase($user);
        }
    }

    /**
     * Dispatch a manual bulk-probe batch for VOD channels and/or episodes.
     *
     * Splits the IDs into 50-item chunks, wraps them in Bus::batch() so the
     * aggregated completion notification is sent only after every chunk has
     * finished. This replaces the old "notify on the last dispatched chunk"
     * approach which produced incorrect "X of Y" counts when chunks ran
     * out of order.
     *
     * @param  array<int>  $channelIds  VOD channel IDs to probe (may be empty)
     * @param  array<int>  $episodeIds  Episode IDs to probe (may be empty)
     */
    public static function dispatchBulk(
        int $notifyUserId,
        string $notifyLabel,
        int $probeTimeout = 15,
        array $channelIds = [],
        array $episodeIds = [],
    ): ?Batch {
        $total = count($channelIds) + count($episodeIds);
        if ($total === 0) {
            return null;
        }

        $start = now();
        $jobs = [];

        foreach (array_chunk($channelIds, 50) as $chunk) {
            $jobs[] = new ProbeVodStreamsChunk(
                channelIds: $chunk,
                probeTimeout: $probeTimeout,
            );
        }

        foreach (array_chunk($episodeIds, 50) as $chunk) {
            $jobs[] = new ProbeVodStreamsChunk(
                episodeIds: $chunk,
                probeTimeout: $probeTimeout,
            );
        }

        return Bus::batch($jobs)
            ->name($notifyLabel)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($notifyUserId, $notifyLabel, $total, $start, $channelIds, $episodeIds): void {
                dispatch(new self(
                    notifyUserId: $notifyUserId,
                    notifyLabel: $notifyLabel,
                    total: $total,
                    start: $start,
                    channelIds: $channelIds,
                    episodeIds: $episodeIds,
                ));
            })
            ->dispatch();
    }
}
