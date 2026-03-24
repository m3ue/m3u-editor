<?php

namespace App\Jobs;

use App\Models\Playlist;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunPlaylistFindReplaceRules implements ShouldQueue
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
        $rules = collect($this->playlist->find_replace_rules ?? [])
            ->filter(fn (array $rule): bool => $rule['enabled'] ?? false);

        if ($rules->isEmpty()) {
            return;
        }

        $start = now();
        $liveChannelRulesRun = 0;
        $vodChannelRulesRun = 0;
        $seriesRulesRun = 0;
        $liveGroupRulesRun = 0;
        $vodGroupRulesRun = 0;
        $categoryRulesRun = 0;

        foreach ($rules as $rule) {
            $target = $rule['target'] ?? 'channels';

            // Skip if no find & replace value is set
            if (empty($rule['find_replace'])) {
                continue;
            }
            if ($target === 'channels') {
                (new ChannelFindAndReplace(
                    user_id: $this->playlist->user_id,
                    use_regex: $rule['use_regex'] ?? true,
                    column: $rule['column'] ?? 'title',
                    find_replace: $rule['find_replace'] ?? '',
                    replace_with: $rule['replace_with'] ?? '',
                    all_playlists: false,
                    playlist_id: $this->playlist->id,
                    silent: true,
                    is_vod: false,
                ))->handle();
                $liveChannelRulesRun++;
            } elseif ($target === 'vod_channels') {
                (new ChannelFindAndReplace(
                    user_id: $this->playlist->user_id,
                    use_regex: $rule['use_regex'] ?? true,
                    column: $rule['column'] ?? 'title',
                    find_replace: $rule['find_replace'] ?? '',
                    replace_with: $rule['replace_with'] ?? '',
                    all_playlists: false,
                    playlist_id: $this->playlist->id,
                    silent: true,
                    is_vod: true,
                ))->handle();
                $vodChannelRulesRun++;
            } elseif ($target === 'series') {
                (new SeriesFindAndReplace(
                    user_id: $this->playlist->user_id,
                    use_regex: $rule['use_regex'] ?? true,
                    column: $rule['column'] ?? 'name',
                    find_replace: $rule['find_replace'] ?? '',
                    replace_with: $rule['replace_with'] ?? '',
                    all_series: false,
                    playlist_id: $this->playlist->id,
                    silent: true,
                ))->handle();
                $seriesRulesRun++;
            } elseif ($target === 'groups') {
                (new GroupFindAndReplace(
                    user_id: $this->playlist->user_id,
                    use_regex: $rule['use_regex'] ?? true,
                    find_replace: $rule['find_replace'] ?? '',
                    replace_with: $rule['replace_with'] ?? '',
                    playlist_id: $this->playlist->id,
                    silent: true,
                    group_type: 'live',
                ))->handle();
                $liveGroupRulesRun++;
            } elseif ($target === 'vod_groups') {
                (new GroupFindAndReplace(
                    user_id: $this->playlist->user_id,
                    use_regex: $rule['use_regex'] ?? true,
                    find_replace: $rule['find_replace'] ?? '',
                    replace_with: $rule['replace_with'] ?? '',
                    playlist_id: $this->playlist->id,
                    silent: true,
                    group_type: 'vod',
                ))->handle();
                $vodGroupRulesRun++;
            } elseif ($target === 'categories') {
                (new CategoryFindAndReplace(
                    user_id: $this->playlist->user_id,
                    use_regex: $rule['use_regex'] ?? true,
                    find_replace: $rule['find_replace'] ?? '',
                    replace_with: $rule['replace_with'] ?? '',
                    playlist_id: $this->playlist->id,
                    silent: true,
                ))->handle();
                $categoryRulesRun++;
            }
        }

        $completedIn = round($start->diffInSeconds(now()), 2);
        $user = User::find($this->playlist->user_id);

        $parts = [];
        if ($liveChannelRulesRun > 0) {
            $parts[] = "{$liveChannelRulesRun} live channel ".($liveChannelRulesRun === 1 ? 'rule' : 'rules');
        }
        if ($vodChannelRulesRun > 0) {
            $parts[] = "{$vodChannelRulesRun} VOD channel ".($vodChannelRulesRun === 1 ? 'rule' : 'rules');
        }
        if ($seriesRulesRun > 0) {
            $parts[] = "{$seriesRulesRun} series ".($seriesRulesRun === 1 ? 'rule' : 'rules');
        }
        if ($liveGroupRulesRun > 0) {
            $parts[] = "{$liveGroupRulesRun} live group ".($liveGroupRulesRun === 1 ? 'rule' : 'rules');
        }
        if ($vodGroupRulesRun > 0) {
            $parts[] = "{$vodGroupRulesRun} VOD group ".($vodGroupRulesRun === 1 ? 'rule' : 'rules');
        }
        if ($categoryRulesRun > 0) {
            $parts[] = "{$categoryRulesRun} category ".($categoryRulesRun === 1 ? 'rule' : 'rules');
        }
        $summary = implode(' and ', $parts);

        Notification::make()
            ->success()
            ->title('Saved Find & Replace rules completed')
            ->body("Ran {$summary} for \"{$this->playlist->name}\" in {$completedIn}s.")
            ->broadcast($user)
            ->sendToDatabase($user);
    }
}
