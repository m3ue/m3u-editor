<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Episode;
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

class ProbeVodStreams implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 60 * 60;

    public $deleteWhenMissingModels = true;

    public function __construct(
        public int $playlistId,
    ) {}

    public function handle(): void
    {
        $start = now();

        $playlist = Playlist::find($this->playlistId);
        if (! $playlist) {
            Log::warning("ProbeVodStreams: Playlist {$this->playlistId} not found.");

            return;
        }

        $probeTimeout = $playlist->probe_timeout ?? 15;
        $useBatching = (bool) ($playlist->probe_use_batching ?? false);

        $vodChannelIds = Channel::where('playlist_id', $this->playlistId)
            ->where('enabled', true)
            ->where('is_vod', true)
            ->pluck('id')
            ->toArray();

        $episodeIds = Episode::where('playlist_id', $this->playlistId)
            ->where('enabled', true)
            ->pluck('id')
            ->toArray();

        $totalChannels = count($vodChannelIds);
        $totalEpisodes = count($episodeIds);
        $total = $totalChannels + $totalEpisodes;

        if ($total === 0) {
            Log::info("ProbeVodStreams: No probe-eligible VOD channels or episodes found for playlist {$this->playlistId}.");

            return;
        }

        Log::info("ProbeVodStreams: Starting. playlist={$this->playlistId}, channels={$totalChannels}, episodes={$totalEpisodes}, batching=".($useBatching ? 'yes' : 'no'));

        $user = $playlist->user;
        if ($user) {
            Notification::make()
                ->info()
                ->title(__('VOD stream probing started'))
                ->body(__('Probing :total VOD channel(s) and episode(s). You will be notified when complete.', ['total' => $total]))
                ->broadcast($user)
                ->sendToDatabase($user);
        }

        $chunkJobs = [];

        foreach (array_chunk($vodChannelIds, 50) as $chunk) {
            $chunkJobs[] = new ProbeVodStreamsChunk(
                channelIds: $chunk,
                episodeIds: [],
                probeTimeout: $probeTimeout,
            );
        }

        foreach (array_chunk($episodeIds, 50) as $chunk) {
            $chunkJobs[] = new ProbeVodStreamsChunk(
                channelIds: [],
                episodeIds: $chunk,
                probeTimeout: $probeTimeout,
            );
        }

        try {
            if ($useBatching) {
                $this->dispatchAsBatch($chunkJobs, $this->playlistId, $total, $start, $playlist);
            } else {
                $this->dispatchAsChain($chunkJobs, $this->playlistId, $total, $start, $playlist);
            }

            Log::info('ProbeVodStreams: Dispatch complete.');
        } catch (Throwable $e) {
            Log::error("ProbeVodStreams: Dispatch failed — {$e->getMessage()}", [
                'exception' => $e,
                'playlist_id' => $this->playlistId,
            ]);
            $this->notifyFailed($playlist, $e->getMessage());
        }
    }

    private function dispatchAsBatch(
        array $chunkJobs,
        int $playlistId,
        int $total,
        Carbon $start,
        Playlist $playlist,
    ): void {
        $userId = $playlist->user?->id;

        $batch = Bus::batch($chunkJobs)
            ->then(function () use ($playlistId, $total, $start) {
                dispatch(new ProbeVodStreamsComplete(
                    playlistId: $playlistId,
                    total: $total,
                    start: $start,
                ));
            })
            ->catch(function (Batch $batch, Throwable $e) use ($userId) {
                Log::error("ProbeVodStreams batch failed: {$e->getMessage()}");
                self::notifyUserOfFailure($userId, $e->getMessage());
            })
            ->onConnection('redis')
            ->onQueue('import')
            ->allowFailures()
            ->dispatch();

        Log::info("ProbeVodStreams: Batch dispatched. id={$batch->id}, total={$batch->totalJobs}");
    }

    private function dispatchAsChain(
        array $chunkJobs,
        int $playlistId,
        int $total,
        Carbon $start,
        Playlist $playlist,
    ): void {
        $userId = $playlist->user?->id;

        Bus::chain([
            ...$chunkJobs,
            new ProbeVodStreamsComplete(
                playlistId: $playlistId,
                total: $total,
                start: $start,
            ),
        ])
            ->onConnection('redis')
            ->onQueue('import')
            ->catch(function (Throwable $e) use ($userId) {
                Log::error("ProbeVodStreams chain failed: {$e->getMessage()}");
                self::notifyUserOfFailure($userId, $e->getMessage());
            })
            ->dispatch();

        Log::info('ProbeVodStreams: Chain dispatched.');
    }

    private function notifyFailed(Playlist $playlist, string $message): void
    {
        Log::error("ProbeVodStreams failed: {$message}");
        self::notifyUserOfFailure($playlist->user?->id, $message);
    }

    private static function notifyUserOfFailure(?int $userId, string $message): void
    {
        if (! $userId) {
            return;
        }

        $user = User::find($userId);
        if (! $user) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('VOD stream probing failed'))
            ->body($message)
            ->broadcast($user)
            ->sendToDatabase($user);
    }

    public function failed(Throwable $exception): void
    {
        Log::error("ProbeVodStreams orchestrator failed: {$exception->getMessage()}");
    }
}
