<?php

namespace App\Jobs;

use App\Facades\SortFacade;
use App\Models\CustomPlaylist;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunCustomPlaylistProcessing implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(
        public CustomPlaylist $customPlaylist,
    ) {}

    public function handle(): void
    {
        $rules = $this->customPlaylist->enabledProcessingRules();

        if ($rules->isEmpty()) {
            return;
        }

        $start = now();
        $sortRulesRun = 0;
        $recountRulesRun = 0;

        try {
            foreach ($rules as $rule) {
                $action = $rule['action'] ?? 'sort_alpha';
                $type = $rule['type'] ?? 'all';
                $selectedGroups = (array) ($rule['groups'] ?? ['all']);
                $isAllGroups = empty($selectedGroups) || \in_array('all', $selectedGroups);

                $query = match ($type) {
                    'live' => $this->customPlaylist->live_channels(),
                    'vod' => $this->customPlaylist->vod_channels(),
                    default => $this->customPlaylist->channels(),
                };

                if (! $isAllGroups) {
                    $tagType = $this->customPlaylist->uuid;
                    $query->whereHas('tags', function ($q) use ($selectedGroups, $tagType): void {
                        $q->whereIn('name', $selectedGroups)->where('type', $tagType);
                    });
                }

                $channels = $query->get();

                if ($channels->isEmpty()) {
                    continue;
                }

                if ($action === 'sort_alpha') {
                    $column = $rule['column'] ?? 'title';
                    $order = $rule['sort'] ?? 'ASC';
                    SortFacade::bulkSortAlphaCustomPlaylistChannels($this->customPlaylist, $channels, $order, $column);
                    $sortRulesRun++;
                } elseif ($action === 'recount') {
                    $startNumber = (int) ($rule['start'] ?? 1);
                    SortFacade::bulkRecountCustomPlaylistChannels($this->customPlaylist, $channels, $startNumber);
                    $recountRulesRun++;
                }
            }
        } catch (Throwable $e) {
            Log::error("RunCustomPlaylistProcessing failed for custom playlist {$this->customPlaylist->id}: {$e->getMessage()}");
            $this->notifyFailure();

            return;
        }

        $completedIn = round($start->diffInSeconds(now()), 2);
        $user = User::find($this->customPlaylist->user_id);

        if (! $user) {
            return;
        }

        $parts = [];
        if ($sortRulesRun > 0) {
            $parts[] = "{$sortRulesRun} sort ".($sortRulesRun === 1 ? 'rule' : 'rules');
        }
        if ($recountRulesRun > 0) {
            $parts[] = "{$recountRulesRun} recount ".($recountRulesRun === 1 ? 'rule' : 'rules');
        }
        $summary = implode(' and ', $parts);

        Notification::make()
            ->success()
            ->title(__('Processing completed'))
            ->body("Ran {$summary} for \"{$this->customPlaylist->name}\" in {$completedIn}s.")
            ->broadcast($user)
            ->sendToDatabase($user);
    }

    public function failed(Throwable $exception): void
    {
        Log::error("RunCustomPlaylistProcessing job failed for custom playlist {$this->customPlaylist->id}: {$exception->getMessage()}");
        $this->notifyFailure();
    }

    private function notifyFailure(): void
    {
        $user = User::find($this->customPlaylist->user_id);

        if (! $user) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('Processing failed'))
            ->body(__('An error occurred while processing ":playlist". Please check the logs for details.', ['playlist' => $this->customPlaylist->name]))
            ->broadcast($user)
            ->sendToDatabase($user);
    }
}
