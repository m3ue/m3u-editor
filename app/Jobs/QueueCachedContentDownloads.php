<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Series;
use App\Models\User;
use App\Services\CachedContentDispatchService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fans a bulk Cache Now (VODs or episodes) or Cache all episodes (series)
 * selection out into individual DownloadCachedContentFile jobs, then notifies
 * the user with one summary. Runs in the background because a large
 * selection (every episode of many series) is too much work for a single
 * web request.
 *
 * Runs on the `default` queue, not `cache`: the cache workers are tied up by
 * long-running downloads and this job only creates rows and dispatches.
 */
class QueueCachedContentDownloads implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 600;

    /**
     * @param  'vod'|'episodes'|'series'  $type
     * @param  array<int, int>  $ids  Channel ids for `vod`, Episode ids for `episodes`, Series ids for `series`
     */
    public function __construct(
        public string $type,
        public array $ids,
        public int $userId,
    ) {}

    public function handle(CachedContentDispatchService $service): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        // lazyById() (not cursor()) so the playlist relation is eager loaded
        // per chunk instead of once per item.
        $counts = match ($this->type) {
            'series' => $service->dispatchManySeries(
                Series::query()
                    ->whereIn('id', $this->ids)
                    ->where('user_id', $user->id)
                    ->with('playlist')
                    ->lazyById(50)
            ),
            'episodes' => $service->dispatchMany(
                Episode::query()
                    ->whereIn('id', $this->ids)
                    ->where('user_id', $user->id)
                    ->with(['series', 'playlist'])
                    ->lazyById(200)
            ),
            default => $service->dispatchMany(
                Channel::query()
                    ->whereIn('id', $this->ids)
                    ->where('user_id', $user->id)
                    ->with('playlist')
                    ->lazyById(200)
            ),
        };

        CachedContentDispatchService::summaryNotification($counts, episodes: $this->type !== 'vod')
            ->broadcast($user)
            ->sendToDatabase($user);
    }
}
