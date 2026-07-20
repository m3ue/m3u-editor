<?php

namespace App\Jobs;

use App\Services\DvrSchedulerService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * DvrDeepScan — Match enabled DVR rules against the EPG lookahead window.
 *
 * Dispatched by GenerateEpgCache after a successful EPG cache regen, scoped to
 * the playlists/custom playlists/merged playlists that consume that EPG. Picks
 * up new EPG data added since the previous scan so airings more than 30 minutes
 * from now still get scheduled. The per-minute tick only handles trigger/stop of
 * already-scheduled recordings; this is the rescan that catches EPG refreshes.
 *
 * With no scope arguments, falls back to matching every enabled rule — kept for
 * manual/ad-hoc full rescans (e.g. `dispatch(new DvrDeepScan)`).
 */
class DvrDeepScan implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<int, int>  $playlistIds
     * @param  array<int, int>  $customPlaylistIds
     * @param  array<int, int>  $mergedPlaylistIds
     */
    public function __construct(
        public array $playlistIds = [],
        public array $customPlaylistIds = [],
        public array $mergedPlaylistIds = [],
    ) {
        $this->onQueue('dvr');
    }

    /**
     * Scope uniqueness per owner set so scoped scans for different playlists
     * (dispatched from concurrent EPG cache regens) don't block each other.
     */
    public function uniqueId(): string
    {
        return implode('|', [
            'p:'.implode(',', $this->playlistIds),
            'c:'.implode(',', $this->customPlaylistIds),
            'm:'.implode(',', $this->mergedPlaylistIds),
        ]);
    }

    public function handle(DvrSchedulerService $scheduler): void
    {
        $lookaheadDays = max(1, (int) config('dvr.initial_lookahead_days', 14));
        $lookaheadMinutes = $lookaheadDays * 24 * 60;

        $isScoped = ! empty($this->playlistIds) || ! empty($this->customPlaylistIds) || ! empty($this->mergedPlaylistIds);

        Log::info('DVR deep scan starting', [
            'lookahead_days' => $lookaheadDays,
            'scoped' => $isScoped,
            'playlist_ids' => $this->playlistIds,
            'custom_playlist_ids' => $this->customPlaylistIds,
            'merged_playlist_ids' => $this->mergedPlaylistIds,
        ]);

        if ($isScoped) {
            $scheduler->matchAndScheduleForOwners(
                $lookaheadMinutes,
                $this->playlistIds,
                $this->customPlaylistIds,
                $this->mergedPlaylistIds,
            );
        } else {
            $scheduler->matchAndSchedule($lookaheadMinutes);
        }

        Log::info('DVR deep scan complete');
    }
}
