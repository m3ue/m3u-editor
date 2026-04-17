<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Playlist;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProbeChannelStreams implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 60 * 60;

    public $deleteWhenMissingModels = true;

    /**
     * @param  int|null  $playlistId  Probe all enabled live channels for this playlist
     * @param  array<int>|null  $channelIds  Probe specific channel IDs (overrides playlistId)
     */
    public function __construct(
        public ?int $playlistId = null,
        public ?array $channelIds = null,
    ) {}

    /**
     * Execute the job.
     */
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

        $channelIds = $query->pluck('id')->toArray();
        $total = count($channelIds);

        if ($total === 0) {
            return;
        }

        $playlist = $this->playlistId ? Playlist::find($this->playlistId) : null;
        $probeTimeout = $playlist?->probe_timeout ?? 15;
        $useBatching = (bool) ($playlist?->probe_use_batching ?? false);

        $start = now();

        $chunkJobs = collect(array_chunk($channelIds, 50))
            ->map(fn (array $chunk) => new ProbeChannelStreamsChunk(
                channelIds: $chunk,
                probeTimeout: $probeTimeout,
            ))
            ->all();

        $completeJob = new ProbeChannelStreamsComplete(
            playlistId: $this->playlistId,
            channelIds: $this->channelIds,
            total: $total,
            start: $start,
        );

        if ($useBatching) {
            $this->dispatchAsBatch($chunkJobs, $completeJob, $playlist);
        } else {
            $this->dispatchAsChain($chunkJobs, $completeJob, $playlist);
        }
    }

    /**
     * Dispatch chunk jobs as a Bus::batch() so all chunks run in parallel.
     * ProbeChannelStreamsComplete is dispatched via the batch's then() callback.
     *
     * @param  array<ProbeChannelStreamsChunk>  $chunkJobs
     */
    private function dispatchAsBatch(
        array $chunkJobs,
        ProbeChannelStreamsComplete $completeJob,
        ?Playlist $playlist,
    ): void {
        Bus::batch($chunkJobs)
            ->then(function () use ($completeJob) {
                dispatch($completeJob);
            })
            ->catch(function (Batch $batch, Throwable $e) use ($playlist) {
                $this->notifyFailed($playlist, $e->getMessage());
            })
            ->onConnection('redis')
            ->onQueue('import')
            ->allowFailures()
            ->dispatch();
    }

    /**
     * Dispatch chunk jobs as a Bus::chain() so chunks run one after another,
     * with ProbeChannelStreamsComplete appended as the final step.
     *
     * @param  array<ProbeChannelStreamsChunk>  $chunkJobs
     */
    private function dispatchAsChain(
        array $chunkJobs,
        ProbeChannelStreamsComplete $completeJob,
        ?Playlist $playlist,
    ): void {
        Bus::chain([...$chunkJobs, $completeJob])
            ->onConnection('redis')
            ->onQueue('import')
            ->catch(function (Throwable $e) use ($playlist) {
                $this->notifyFailed($playlist, $e->getMessage());
            })
            ->dispatch();
    }

    /**
     * Notify the playlist owner of a probing failure.
     */
    private function notifyFailed(?Playlist $playlist, string $message): void
    {
        Log::error("ProbeChannelStreams failed: {$message}");

        $user = $playlist?->user;
        if (! $user) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('Stream probing failed'))
            ->body($message)
            ->broadcast($user)
            ->sendToDatabase($user);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("ProbeChannelStreams orchestrator failed: {$exception->getMessage()}");
    }
}
