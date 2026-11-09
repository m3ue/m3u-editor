<?php

namespace App\Jobs;

use App\Models\AedProfile;
use App\Services\Sports\SportsAedRefresher;
use App\Services\Sports\SportsService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Fetches a sports-matching AED profile's games in the background (a wide
 * league set is hundreds of requests), so EPG requests only ever read the cache.
 * See {@see SportsAedRefresher}.
 *
 * It shares a queue with playlist and EPG syncs, and SQLite installs have a
 * single worker for it, so it never holds that worker for long or waits on one.
 * Each attempt does one step and releases the job back to the queue, which gives
 * other jobs their turn. A release can't carry state, so the attempt number says
 * which step is next.
 */
class RefreshSportsAedProfile implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** One step, not the whole refresh. */
    public int $timeout = 300;

    /** A step that throws ends the refresh; the next scheduled one starts over. */
    public int $maxExceptions = 1;

    /** One refresh per profile covers every request that saw gaps. */
    public int $uniqueFor = 1800;

    /** The gap between batches, in which a queued sync can start. */
    private const int BATCH_PAUSE_SECONDS = 5;

    /** Team lists come a rate limit's worth at a time. */
    private const int TEAM_RETRY_SECONDS = 60;

    private const int MAX_TEAM_RETRIES = 8;

    public function __construct(
        public int $profileId,
        public bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        return "sports_aed_refresh_{$this->profileId}";
    }

    /**
     * Releases count as attempts, so a limit on time replaces one on tries.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(SportsService $sports, SportsAedRefresher $refresher): void
    {
        $profile = AedProfile::find($this->profileId);
        if ($profile === null || ! $profile->sports_matching || ! $sports->isEnabled()) {
            return;
        }

        $batches = $refresher->gameBatches($profile);
        $step = $this->attempts();

        if ($step === 1 && $this->force) {
            $refresher->forgetGames($profile);
        }

        if ($step <= count($batches)) {
            $refresher->fetchGames($profile, $batches[$step - 1]);
            if ($step < count($batches)) {
                $this->release(self::BATCH_PAUSE_SECONDS);

                return;
            }
        }

        $firstTeamStep = max(1, count($batches));
        $missing = $refresher->fetchMissingTeams($profile);
        if ($step === $firstTeamStep) {
            $refresher->finishRefresh($profile, $this->force);
        }

        $this->waitForTeams($missing, $step - $firstTeamStep);
    }

    /**
     * A first run can have more team lists to fetch than a rate limit allows at
     * once. Comes back for them until they're done or stop coming.
     */
    private function waitForTeams(int $missing, int $retries): void
    {
        $key = "sports:aed:missing-teams:{$this->profileId}";
        $previouslyMissing = $retries === 0 ? null : Cache::get($key);

        if ($missing === 0 || $retries >= self::MAX_TEAM_RETRIES || ($previouslyMissing !== null && $missing >= $previouslyMissing)) {
            Cache::forget($key);

            return;
        }

        Cache::put($key, $missing, now()->addHour());
        $this->release(self::TEAM_RETRY_SECONDS);
    }
}
