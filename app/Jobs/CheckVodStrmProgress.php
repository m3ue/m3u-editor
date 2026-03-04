<?php

namespace App\Jobs;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Checks if there are more VOD STRM files to sync and dispatches the next chain if needed.
 */
class CheckVodStrmProgress implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public const JOBS_PER_CHAIN = 10;

    public function __construct(
        public int $currentOffset,
        public int $totalChannels,
        public bool $notify = true,
        public bool $all_playlists = false,
        public ?int $playlist_id = null,
        public ?int $user_id = null,
        public bool $needsCleanup = false,
    ) {
        $this->onQueue('file_sync');
    }

    public function handle(): void
    {
        $batchSize = SyncVodStrmFiles::BATCH_SIZE;
        $channelsProcessed = $this->currentOffset;
        $channelsRemaining = $this->totalChannels - $channelsProcessed;

        Log::info('STRM Sync: VOD progress check', [
            'processed' => $channelsProcessed,
            'total' => $this->totalChannels,
            'remaining' => $channelsRemaining,
            'progress_pct' => round(($channelsProcessed / $this->totalChannels) * 100, 1),
        ]);

        if ($channelsRemaining <= 0) {
            Log::info('STRM Sync: All VOD batches complete', [
                'total_vod_channels' => $this->totalChannels,
            ]);

            if ($this->needsCleanup) {
                Log::info('STRM Sync: Dispatching VOD cleanup job');
                dispatch(new SyncVodStrmFiles(
                    notify: $this->notify,
                    all_playlists: $this->all_playlists,
                    playlist_id: $this->playlist_id,
                    user_id: $this->user_id,
                    isCleanupJob: true,
                ));
            } else {
                if ($this->notify && $this->user_id) {
                    $user = User::find($this->user_id);
                    if ($user) {
                        Notification::make()
                            ->success()
                            ->title('STRM File Sync Complete')
                            ->body("Successfully synced {$this->totalChannels} VOD channels.")
                            ->broadcast($user)
                            ->sendToDatabase($user);
                    }
                }
            }

            return;
        }

        $jobs = [];
        $jobsInThisChain = min(self::JOBS_PER_CHAIN, (int) ceil($channelsRemaining / $batchSize));

        for ($i = 0; $i < $jobsInThisChain; $i++) {
            $offset = $channelsProcessed + ($i * $batchSize);
            $batchNumber = (int) floor($offset / $batchSize) + 1;
            $totalBatches = (int) ceil($this->totalChannels / $batchSize);

            $jobs[] = new SyncVodStrmFiles(
                notify: false,
                all_playlists: $this->all_playlists,
                playlist_id: $this->playlist_id,
                user_id: $this->user_id,
                batchOffset: $offset,
                totalBatches: $totalBatches,
                currentBatch: $batchNumber,
            );
        }

        $nextOffset = $channelsProcessed + ($jobsInThisChain * $batchSize);
        $jobs[] = new self(
            currentOffset: $nextOffset,
            totalChannels: $this->totalChannels,
            notify: $this->notify,
            all_playlists: $this->all_playlists,
            playlist_id: $this->playlist_id,
            user_id: $this->user_id,
            needsCleanup: $this->needsCleanup,
        );

        Log::info('STRM Sync: Dispatching next VOD chain', [
            'jobs_in_chain' => $jobsInThisChain,
            'next_offset' => $nextOffset,
        ]);

        Bus::chain($jobs)->dispatch();
    }
}
