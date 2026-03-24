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

        foreach ($rules as $rule) {
            $target = $rule['target'] ?? 'live_groups';
            $column = $rule['column'] ?? 'title';
            $order = $rule['sort'] ?? 'ASC';
            $selectedGroups = (array) ($rule['group'] ?? ['all']);
            $isAll = empty($selectedGroups) || in_array('all', $selectedGroups);

            if ($target === 'live_groups') {
                $query = $this->playlist->liveGroups();
                if (! $isAll) {
                    $query = $query->whereIn('name_internal', $selectedGroups);
                }
                $query->each(function ($group) use ($column, $order): void {
                    SortFacade::bulkSortGroupChannels($group, $order, $column);
                });
                $liveRulesRun++;
            } elseif ($target === 'vod_groups') {
                $query = $this->playlist->vodGroups();
                if (! $isAll) {
                    $query = $query->whereIn('name_internal', $selectedGroups);
                }
                $query->each(function ($group) use ($column, $order): void {
                    SortFacade::bulkSortGroupChannels($group, $order, $column);
                });
                $vodRulesRun++;
            }
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
        $summary = implode(' and ', $parts);

        Notification::make()
            ->success()
            ->title('Sort Alpha completed')
            ->body("Ran {$summary} for \"{$this->playlist->name}\" in {$completedIn}s.")
            ->broadcast($user)
            ->sendToDatabase($user);
    }
}
