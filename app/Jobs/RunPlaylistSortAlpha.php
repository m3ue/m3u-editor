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
     * column => [playlist-wide SortFacade method, per-group SortFacade method].
     * Drives both the vod_groups and series_categories dispatch branches so a
     * future sortable column is one new entry instead of a new if/elseif arm.
     *
     * @var array<string, array{playlist: string, scoped: string}>
     */
    private const VOD_SORT_METHODS = [
        'release_date' => ['playlist' => 'bulkSortPlaylistVodByReleaseDate', 'scoped' => 'bulkSortGroupChannelsByReleaseDate'],
        'rating' => ['playlist' => 'bulkSortPlaylistVodByRating', 'scoped' => 'bulkSortGroupChannelsByRating'],
    ];

    /**
     * @var array<string, array{playlist: string, scoped: string}>
     */
    private const SERIES_SORT_METHODS = [
        'release_date' => ['playlist' => 'bulkSortPlaylistSeriesByReleaseDate', 'scoped' => 'bulkSortCategorySeriesByReleaseDate'],
        'rating' => ['playlist' => 'bulkSortPlaylistSeriesByRating', 'scoped' => 'bulkSortCategorySeriesByRating'],
        'recent_activity' => ['playlist' => 'bulkSortPlaylistSeriesByRecentActivity', 'scoped' => 'bulkSortCategorySeriesByRecentActivity'],
    ];

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
                // Defensive guard: live channels aren't VOD/series entries, so
                // they never carry a meaningful rating value (channels.rating
                // is only populated during VOD import). The Sort By dropdown
                // prevents users from selecting this combo, but a hand-edited
                // sort_alpha_config or a future UI change could route rating
                // here - fail loudly so the bad rule is visible instead of
                // silently no-op'ing deep inside SortService.
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
                if (isset(self::VOD_SORT_METHODS[$column])) {
                    $methods = self::VOD_SORT_METHODS[$column];

                    if ($isAll) {
                        SortFacade::{$methods['playlist']}($this->playlist, $order);
                    } else {
                        $this->playlist->vodGroups()
                            ->whereIn('name_internal', $selectedGroups)
                            ->each(fn ($group) => SortFacade::{$methods['scoped']}($group, $order));
                    }
                } else {
                    $query = $this->playlist->vodGroups();
                    if (! $isAll) {
                        $query = $query->whereIn('name_internal', $selectedGroups);
                    }
                    $query->each(fn ($group) => SortFacade::bulkSortGroupChannels($group, $order, $column));
                }
                $vodRulesRun++;
            } elseif ($target === 'series_categories') {
                if (isset(self::SERIES_SORT_METHODS[$column])) {
                    $methods = self::SERIES_SORT_METHODS[$column];

                    if ($isAll) {
                        SortFacade::{$methods['playlist']}($this->playlist, $order);
                    } else {
                        $this->playlist->categories()
                            ->whereIn('name_internal', $selectedGroups)
                            ->each(fn ($category) => SortFacade::{$methods['scoped']}($category, $order));
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
