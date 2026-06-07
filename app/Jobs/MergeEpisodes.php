<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Models\EpisodeFailover;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class MergeEpisodes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public $user,
        public Collection $playlists,
        public int $playlistId,
        public bool $deactivateFailoverEpisodes = false,
        public bool $forceCompleteRemerge = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $playlistIds = $this->playlists->map(function ($item) {
            return is_array($item) ? $item['playlist_failover_id'] : $item;
        })->values();

        if ($this->playlistId) {
            $playlistIds->prepend($this->playlistId);
        }

        $playlistIds = $playlistIds->filter()->unique()->values()->toArray();
        $playlistPriority = $playlistIds ? array_flip($playlistIds) : [];

        $existingFailoverEpisodeIds = EpisodeFailover::where('user_id', $this->user->id)
            ->whereHas('episodeFailover', function ($query) use ($playlistIds) {
                $query->whereIn('playlist_id', $playlistIds);
            })
            ->pluck('episode_failover_id')
            ->toArray();

        $episodes = Episode::query()
            ->with(['series', 'playlist'])
            ->where('user_id', $this->user->id)
            ->whereIn('playlist_id', $playlistIds)
            ->when(! empty($existingFailoverEpisodeIds) && ! $this->forceCompleteRemerge && ! $this->deactivateFailoverEpisodes, function ($query) use ($existingFailoverEpisodeIds) {
                $query->whereNotIn('id', $existingFailoverEpisodeIds);
            })
            ->get();

        $processed = 0;
        $deactivatedCount = 0;

        $episodes
            ->filter(fn (Episode $episode): bool => $this->getEpisodeMergeKey($episode) !== null)
            ->groupBy(fn (Episode $episode): string => $this->getEpisodeMergeKey($episode))
            ->each(function (Collection $group) use ($playlistPriority, &$processed, &$deactivatedCount): void {
                if ($group->count() <= 1) {
                    return;
                }

                $sorted = $this->sortEpisodesByPlaylistPriority($group, $playlistPriority);
                $master = $sorted->first();
                if (! $master) {
                    return;
                }

                $sortOrder = 1;
                foreach ($sorted->where('id', '!=', $master->id) as $failover) {
                    EpisodeFailover::updateOrCreate(
                        [
                            'episode_id' => $master->id,
                            'episode_failover_id' => $failover->id,
                        ],
                        [
                            'user_id' => $this->user->id,
                            'sort' => $sortOrder++,
                        ]
                    );

                    if ($this->deactivateFailoverEpisodes && array_key_exists('enabled', $failover->getAttributes()) && $failover->enabled) {
                        $failover->update(['enabled' => false]);
                        $deactivatedCount++;
                    }

                    $processed++;
                }
            });

        Notification::make()
            ->success()
            ->title('Episode merge completed')
            ->body($processed.' episode failover relationship(s) created or updated.'.($deactivatedCount > 0 ? ' '.$deactivatedCount.' failover episode(s) deactivated.' : ''))
            ->broadcast($this->user)
            ->sendToDatabase($this->user);
    }

    protected function getEpisodeMergeKey(Episode $episode): ?string
    {
        if (! empty($episode->tmdb_id)) {
            return 'episode:'.(int) $episode->tmdb_id;
        }

        $seriesTmdbId = $episode->series?->tmdb_id;
        $season = $episode->season;
        $episodeNumber = $episode->episode_num;

        if (empty($seriesTmdbId) || empty($season) || empty($episodeNumber)) {
            return null;
        }

        return 'series:'.(int) $seriesTmdbId.':s'.(int) $season.':e'.(int) $episodeNumber;
    }

    protected function sortEpisodesByPlaylistPriority(Collection $episodes, array $playlistPriority): Collection
    {
        return $episodes->sortBy([
            fn (Episode $a, Episode $b): int => ($playlistPriority[$a->playlist_id] ?? PHP_INT_MAX) <=> ($playlistPriority[$b->playlist_id] ?? PHP_INT_MAX),
            fn (Episode $a, Episode $b): int => $a->id <=> $b->id,
        ])->values();
    }
}
