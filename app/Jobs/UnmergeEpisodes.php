<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Models\EpisodeFailover;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UnmergeEpisodes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly User $user,
        public ?int $playlistId = null,
        public bool $reactivateEpisodes = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $reactivatedCount = 0;

        if ($this->playlistId) {
            $episodeIds = Episode::where('playlist_id', $this->playlistId)
                ->where('user_id', $this->user->id)
                ->cursor();

            $idsToDelete = [];
            foreach ($episodeIds as $episode) {
                $idsToDelete[] = $episode->id;
                if (count($idsToDelete) >= 100) {
                    if ($this->reactivateEpisodes) {
                        $reactivatedCount += $this->reactivateFailoverEpisodes($idsToDelete);
                    }
                    EpisodeFailover::whereIn('episode_id', $idsToDelete)->delete();
                    $idsToDelete = [];
                }
            }

            if (count($idsToDelete) > 0) {
                if ($this->reactivateEpisodes) {
                    $reactivatedCount += $this->reactivateFailoverEpisodes($idsToDelete);
                }
                EpisodeFailover::whereIn('episode_id', $idsToDelete)->delete();
            }
        } else {
            if ($this->reactivateEpisodes) {
                $failoverEpisodeIds = EpisodeFailover::where('user_id', $this->user->id)
                    ->pluck('episode_failover_id')
                    ->toArray();

                if (! empty($failoverEpisodeIds)) {
                    $reactivatedCount = Episode::whereIn('id', $failoverEpisodeIds)
                        ->where('enabled', false)
                        ->update(['enabled' => true]);
                }
            }

            EpisodeFailover::where('user_id', $this->user->id)->delete();
        }

        $this->sendCompletionNotification($reactivatedCount);
    }

    /**
     * Reactivate failover episodes that were disabled during merge.
     */
    protected function reactivateFailoverEpisodes(array $masterEpisodeIds): int
    {
        $failoverEpisodeIds = EpisodeFailover::whereIn('episode_id', $masterEpisodeIds)
            ->pluck('episode_failover_id')
            ->toArray();

        if (empty($failoverEpisodeIds)) {
            return 0;
        }

        return Episode::whereIn('id', $failoverEpisodeIds)
            ->where('enabled', false)
            ->update(['enabled' => true]);
    }

    protected function sendCompletionNotification(int $reactivatedCount = 0): void
    {
        $message = $this->playlistId
            ? __('Episodes in the specified playlist have been unmerged successfully.')
            : __('All episodes have been unmerged successfully.');

        if ($reactivatedCount > 0) {
            $message .= ' '.__(':count episode(s) were reactivated.', ['count' => $reactivatedCount]);
        }

        Notification::make()
            ->title(__('Unmerge complete'))
            ->body($message)
            ->success()
            ->broadcast($this->user)
            ->sendToDatabase($this->user);
    }
}
