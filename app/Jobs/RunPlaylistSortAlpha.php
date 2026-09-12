<?php

namespace App\Jobs;

use App\Facades\SortFacade;
use App\Models\Playlist;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunPlaylistSortAlpha implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 900;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $rules = collect($this->playlist->sort_alpha_config ?? [])
            ->filter(fn (array $rule): bool => $rule['enabled'] ?? false);

        if ($rules->isEmpty()) {
            return;
        }

        $start = now();
        $liveRulesRun = 0;
        $vodRulesRun = 0;
        $seriesRulesRun = 0;

        foreach ($rules as $rule) {
            $target = $rule['target'] ?? 'live_groups';
            $column = $rule['column'] ?? 'title';
            $order = $rule['sort'] ?? 'ASC';
            $selectedGroups = (array) ($rule['group'] ?? ['all']);
            $isAll = empty($selectedGroups) || in_array('all', $selectedGroups);

            if ($target === 'live_groups') {
                // Defensive guard: live channels no longer carry a rating source
                // (the channels.rating column was dropped in 2025_06_23). The
                // Sort By dropdown prevents users from selecting this combo, but
                // a hand-edited sort_alpha_config or a future UI change could
                // route rating here — fail loudly so the bad rule is visible
                // instead of silently throwing deep inside SortService.
                if ($column === 'rating') {
                    throw new \InvalidArgumentException("Column 'rating' is not supported for target 'live_groups' (no rating source on live channels).");
                }

                $query = $this->playlist->liveGroups();
                if (! $isAll) {
                    $query = $query->whereIn('name_internal', $selectedGroups);
                }
                $query->each(function ($group) use ($column, $order): void {
                    SortFacade::bulkSortGroupChannels($group, $order, $column);
                });
                $liveRulesRun++;
            } elseif ($target === 'vod_groups') {
                if ($column === 'release_date' && $isAll) {
                    SortFacade::bulkSortPlaylistVodByReleaseDate($this->playlist, $order);
                    $vodRulesRun++;

                    continue;
                }

                if ($column === 'rating' && $isAll) {
                    SortFacade::bulkSortPlaylistVodByRating($this->playlist, $order);
                    $vodRulesRun++;

                    continue;
                }

                $query = $this->playlist->vodGroups();
                if (! $isAll) {
                    $query = $query->whereIn('name_internal', $selectedGroups);
                }
                $query->each(function ($group) use ($column, $order): void {
                    if ($column === 'release_date') {
                        SortFacade::bulkSortGroupChannelsByReleaseDate($group, $order);
                    } elseif ($column === 'rating') {
                        SortFacade::bulkSortGroupChannelsByRating($group, $order);
                    } else {
                        SortFacade::bulkSortGroupChannels($group, $order, $column);
                    }
                });
                $vodRulesRun++;
            } elseif ($target === 'series_categories') {
                if ($column === 'release_date') {
                    if ($isAll) {
                        SortFacade::bulkSortPlaylistSeriesByReleaseDate($this->playlist, $order);
                    } else {
                        $this->playlist->categories()
                            ->whereIn('name_internal', $selectedGroups)
                            ->each(function ($category) use ($order): void {
                                SortFacade::bulkSortCategorySeriesByReleaseDate($category, $order);
                            });
                    }
                } elseif ($column === 'rating') {
                    if ($isAll) {
                        SortFacade::bulkSortPlaylistSeriesByRating($this->playlist, $order);
                    } else {
                        $this->playlist->categories()
                            ->whereIn('name_internal', $selectedGroups)
                            ->each(function ($category) use ($order): void {
                                SortFacade::bulkSortCategorySeriesByRating($category, $order);
                            });
                    }
                }
                $seriesRulesRun++;
            }
        }

        if (($liveRulesRun + $vodRulesRun + $seriesRulesRun) === 0) {
            return;
        }

        $completedIn = round($start->diffInSeconds(now()), 2);
        $user = User::find($this->playlist->user_id);

        $parts = [];
        if ($liveRulesRun > 0) {
            $parts[] = "{$liveRulesRun} live ".($liveRulesRun === 1 ? 'rule' : 'rules');
        }
        if ($vodRulesRun > 0) {
            $parts[] = "{$vodRulesRun} VOD ".($vodRulesRun === 1 ? 'rule' : 'rules');
        }
        if ($seriesRulesRun > 0) {
            $parts[] = "{$seriesRulesRun} Series ".($seriesRulesRun === 1 ? 'rule' : 'rules');
        }
        $summary = implode(' and ', $parts);

        Notification::make()
            ->success()
            ->title('Sort Alpha completed')
            ->body("Ran {$summary} for \"{$this->playlist->name}\" in {$completedIn}s.")
            ->broadcast($user)
            ->sendToDatabase($user);
    }
}
