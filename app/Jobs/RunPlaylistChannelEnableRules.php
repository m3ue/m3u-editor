<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RunPlaylistChannelEnableRules implements ShouldQueue
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
     *
     * Re-evaluates every non-custom channel of the playlist against the saved
     * enable/disable rules. Rules run in order and the last matching rule wins;
     * channels that match no rule keep their current enabled state. This runs
     * on every sync so channels whose provider renames them between syncs
     * (e.g. "LIVE EVENT 01 | NO EVENT TODAY" placeholders) flip automatically.
     */
    public function handle(): void
    {
        $rules = collect($this->playlist->channel_enable_rules ?? [])
            ->filter(fn (array $rule): bool => ($rule['enabled'] ?? false) && filled($rule['pattern'] ?? null))
            ->values();

        if ($rules->isEmpty()) {
            return;
        }

        $start = now();

        // Pre-compile the patterns, skipping any that fail to compile so one
        // bad rule doesn't abort the rest.
        $compiled = [];
        $invalidRules = [];
        foreach ($rules as $rule) {
            $pattern = '/'.str_replace('/', '\\/', $rule['pattern']).'/ui';
            if (@preg_match($pattern, '') === false) {
                $invalidRules[] = $rule['name'] ?? $rule['pattern'];

                continue;
            }
            $compiled[] = [
                'pattern' => $pattern,
                'column' => $rule['column'] ?? 'title',
                'is_vod' => ($rule['target'] ?? 'channels') === 'vod_channels',
                'enable' => ($rule['action'] ?? 'disable') === 'enable',
            ];
        }

        if (! empty($invalidRules)) {
            Log::warning('Skipping invalid channel enable/disable rules for playlist '.$this->playlist->id, [
                'rules' => $invalidRules,
            ]);
        }

        $enabledCount = 0;
        $disabledCount = 0;

        if (! empty($compiled)) {
            Channel::query()
                ->where('playlist_id', $this->playlist->id)
                ->where('is_custom', false)
                ->select(['id', 'title', 'title_custom', 'name', 'name_custom', 'enabled', 'is_vod'])
                ->chunkById(1000, function ($channels) use ($compiled, &$enabledCount, &$disabledCount) {
                    $toEnable = [];
                    $toDisable = [];

                    foreach ($channels as $channel) {
                        $targetState = null;
                        foreach ($compiled as $rule) {
                            if ($rule['is_vod'] !== (bool) $channel->is_vod) {
                                continue;
                            }
                            $value = $rule['column'] === 'name'
                                ? ($channel->name_custom ?? $channel->name)
                                : ($channel->title_custom ?? $channel->title);
                            if ($value !== null && preg_match($rule['pattern'], $value)) {
                                // Last matching rule wins
                                $targetState = $rule['enable'];
                            }
                        }

                        if ($targetState === null || $targetState === (bool) $channel->enabled) {
                            continue;
                        }

                        if ($targetState) {
                            $toEnable[] = $channel->id;
                        } else {
                            $toDisable[] = $channel->id;
                        }
                    }

                    if (! empty($toEnable)) {
                        Channel::whereIn('id', $toEnable)->update(['enabled' => true]);
                        $enabledCount += count($toEnable);
                    }
                    if (! empty($toDisable)) {
                        Channel::whereIn('id', $toDisable)->update(['enabled' => false]);
                        $disabledCount += count($toDisable);
                    }
                });
        }

        if ($enabledCount > 0 || $disabledCount > 0) {
            SyncPlexDvrJob::dispatchIfConfigured(trigger: 'channel_enable_rules');
        }

        $completedIn = round($start->diffInSeconds(now()), 2);
        $user = User::find($this->playlist->user_id);

        $body = "Enabled {$enabledCount} and disabled {$disabledCount} channels for \"{$this->playlist->name}\" in {$completedIn}s.";
        if (! empty($invalidRules)) {
            $body .= ' Skipped invalid rules: '.implode(', ', $invalidRules).'.';
        }

        Notification::make()
            ->success()
            ->title('Channel enable/disable rules completed')
            ->body($body)
            ->broadcast($user)
            ->sendToDatabase($user);
    }
}
