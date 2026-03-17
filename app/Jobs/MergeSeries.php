<?php

namespace App\Jobs;

use App\Models\Episode;
use App\Models\EpisodeFailover;
use App\Models\Series;
use App\Services\TitleNormalizer;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class MergeSeries implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public $user,
        public Collection $playlists,
        public int $playlistId,
        public float $titleSimilarityThreshold = 85.0,
        public bool $deactivateFailoverEpisodes = false,
        public bool $forceCompleteRemerge = false,
        public ?int $categoryId = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $normalizer = app(TitleNormalizer::class);
        $processed = 0;
        $deactivatedCount = 0;

        $playlistIds = $this->playlists->map(function ($item) {
            return is_array($item) ? ($item['playlist_failover_id'] ?? $item) : $item;
        })->values();

        if ($this->playlistId) {
            $playlistIds->prepend($this->playlistId);
        }

        $playlistIds = $playlistIds->unique()->values()->toArray();
        $playlistPriority = array_flip($playlistIds);

        // Get all series across the selected playlists
        $allSeries = Series::where('user_id', $this->user->id)
            ->whereIn('playlist_id', $playlistIds)
            ->when($this->categoryId, function ($query) {
                $query->where('category_id', $this->categoryId);
            })
            ->with(['episodes'])
            ->get();

        if ($allSeries->count() < 2) {
            $this->sendCompletionNotification(0, 0);

            return;
        }

        // Build items for title matching
        $items = $allSeries->map(fn (Series $s) => [
            'id' => $s->id,
            'title' => $s->name ?? '',
        ])->filter(fn ($item) => $item['title'] !== '')->values()->toArray();

        $groups = $normalizer->groupBySimilarity($items, $this->titleSimilarityThreshold);

        foreach ($groups as $group) {
            if (count($group) <= 1) {
                continue;
            }

            $groupSeriesIds = collect($group)->pluck('id')->toArray();
            $groupSeries = $allSeries->whereIn('id', $groupSeriesIds);

            // Select the "master" series based on playlist priority
            $masterSeries = $this->selectMasterSeries($groupSeries, $playlistPriority);
            if (! $masterSeries) {
                continue;
            }

            // Match episodes across series by season + episode number
            $failoverSeriesCollection = $groupSeries->where('id', '!=', $masterSeries->id);

            foreach ($failoverSeriesCollection as $failoverSeries) {
                foreach ($failoverSeries->episodes as $failoverEpisode) {
                    // Find matching master episode by season + episode number
                    $masterEpisode = $masterSeries->episodes
                        ->where('season', $failoverEpisode->season)
                        ->where('episode_num', $failoverEpisode->episode_num)
                        ->first();

                    if (! $masterEpisode) {
                        continue;
                    }

                    // Skip if already linked (unless force remerge)
                    if (! $this->forceCompleteRemerge) {
                        $exists = EpisodeFailover::where('episode_id', $masterEpisode->id)
                            ->where('episode_failover_id', $failoverEpisode->id)
                            ->exists();

                        if ($exists) {
                            continue;
                        }
                    }

                    $maxSort = EpisodeFailover::where('episode_id', $masterEpisode->id)
                        ->max('sort') ?? 0;

                    EpisodeFailover::updateOrCreate(
                        [
                            'episode_id' => $masterEpisode->id,
                            'episode_failover_id' => $failoverEpisode->id,
                        ],
                        [
                            'user_id' => $this->user->id,
                            'sort' => $maxSort + 1,
                        ]
                    );

                    if ($this->deactivateFailoverEpisodes && $failoverEpisode->enabled) {
                        $failoverEpisode->update(['enabled' => false]);
                        $deactivatedCount++;
                    }

                    $processed++;
                }
            }
        }

        $this->sendCompletionNotification($processed, $deactivatedCount);
    }

    /**
     * Select the master series based on playlist priority.
     */
    protected function selectMasterSeries(Collection $seriesGroup, array $playlistPriority): ?Series
    {
        if ($this->playlistId) {
            $preferred = $seriesGroup->where('playlist_id', $this->playlistId)->first();
            if ($preferred) {
                return $preferred;
            }
        }

        return $seriesGroup->sortBy(fn (Series $s) => $playlistPriority[$s->playlist_id] ?? 999)->first();
    }

    protected function sendCompletionNotification(int $processed, int $deactivatedCount): void
    {
        if ($processed > 0) {
            $body = "Merged {$processed} episodes across series successfully.";
            if ($deactivatedCount > 0) {
                $body .= " {$deactivatedCount} failover episodes were deactivated.";
            }
        } else {
            $body = 'No series episodes were merged.';
        }

        Notification::make()
            ->title('Series merge complete')
            ->body($body)
            ->success()
            ->broadcast($this->user)
            ->sendToDatabase($this->user);
    }
}
