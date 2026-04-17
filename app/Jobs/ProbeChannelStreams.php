<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Carbon\Carbon;
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
        $start = now();

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
            Log::info("ProbeChannelStreams: No probe-eligible channels found for playlist {$this->playlistId}.");

            return;
        }

        $playlist = $this->playlistId ? Playlist::find($this->playlistId) : null;
        $probeTimeout = $playlist?->probe_timeout ?? 15;
        $useBatching = (bool) ($playlist?->probe_use_batching ?? false);

        Log::info("ProbeChannelStreams: Starting. playlist={$this->playlistId}, total={$total}, batching=".($useBatching ? 'yes' : 'no'));

        // Notify user that probing has started.
        $user = $playlist?->user;
        if ($user) {
            Notification::make()
                ->info()
                ->title(__('Stream probing started'))
                ->body(__('Probing :total channel(s). You will be notified when complete.', ['total' => $total]))
                ->broadcast($user)
                ->sendToDatabase($user);
        }

        $chunkJobs = collect(array_chunk($channelIds, 50))
            ->map(fn (array $chunk) => new ProbeChannelStreamsChunk(
                channelIds: $chunk,
                probeTimeout: $probeTimeout,
            ))
            ->all();

        try {
            if ($useBatching) {
                $this->dispatchAsBatch($chunkJobs, $this->playlistId, $this->channelIds, $total, $start, $playlist);
            } else {
                $this->dispatchAsChain($chunkJobs, $this->playlistId, $this->channelIds, $total, $start, $playlist);
            }

            Log::info('ProbeChannelStreams: Dispatch complete.');
        } catch (Throwable $e) {
            Log::error("ProbeChannelStreams: Dispatch failed — {$e->getMessage()}", [
                'exception' => $e,
                'playlist_id' => $this->playlistId,
            ]);
            $this->notifyFailed($playlist, $e->getMessage());
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
        ?int $playlistId,
        ?array $channelIds,
        int $total,
        Carbon $start,
        ?Playlist $playlist,
    ): void {
        // Capture only a scalar so the closure does not bind $this (the running
        // job). $this carries an active RedisJob connection via InteractsWithQueue
        // which makes PHP hang when SerializableClosure tries to serialize it.
        $userId = $playlist?->user?->id;

        $batch = Bus::batch($chunkJobs)
            ->then(function () use ($playlistId, $channelIds, $total, $start) {
                dispatch(new ProbeChannelStreamsComplete(
                    playlistId: $playlistId,
                    channelIds: $channelIds,
                    total: $total,
                    start: $start,
                ));
            })
            ->catch(function (Batch $batch, Throwable $e) use ($userId) {
                Log::error("ProbeChannelStreams batch failed: {$e->getMessage()}");

                if (! $userId) {
                    return;
                }

                $user = User::find($userId);

                if (! $user) {
                    return;
                }

                Notification::make()
                    ->danger()
                    ->title(__('Stream probing failed'))
                    ->body($e->getMessage())
                    ->broadcast($user)
                    ->sendToDatabase($user);
            })
            ->onConnection('redis')
            ->onQueue('import')
            ->allowFailures()
            ->dispatch();

        Log::info("ProbeChannelStreams: Batch dispatched. id={$batch->id}, total={$batch->totalJobs}");
    }

    /**
     * Dispatch chunk jobs as a Bus::chain() so chunks run one after another,
     * with ProbeChannelStreamsComplete appended as the final step.
     *
     * @param  array<ProbeChannelStreamsChunk>  $chunkJobs
     */
    private function dispatchAsChain(
        array $chunkJobs,
        ?int $playlistId,
        ?array $channelIds,
        int $total,
        Carbon $start,
        ?Playlist $playlist,
    ): void {
        $userId = $playlist?->user?->id;

        Bus::chain([
            ...$chunkJobs,
            new ProbeChannelStreamsComplete(
                playlistId: $playlistId,
                channelIds: $channelIds,
                total: $total,
                start: $start,
            ),
        ])
            ->onConnection('redis')
            ->onQueue('import')
            ->catch(function (Throwable $e) use ($userId) {
                Log::error("ProbeChannelStreams chain failed: {$e->getMessage()}");

                if (! $userId) {
                    return;
                }

                $user = User::find($userId);

                if (! $user) {
                    return;
                }

                Notification::make()
                    ->danger()
                    ->title(__('Stream probing failed'))
                    ->body($e->getMessage())
                    ->broadcast($user)
                    ->sendToDatabase($user);
            })
            ->dispatch();

        Log::info('ProbeChannelStreams: Chain dispatched.');
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
