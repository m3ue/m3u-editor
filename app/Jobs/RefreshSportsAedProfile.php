<?php

namespace App\Jobs;

use App\Models\AedProfile;
use App\Services\Sports\SportsAedRefresher;
use App\Services\Sports\SportsService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;

/**
 * Fetches a sports-matching AED profile's games in the background
 * (a wide league set is hundreds of requests), so EPG requests only ever
 * read the cache. See {@see SportsAedRefresher}.
 */
class RefreshSportsAedProfile implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** A cold all-leagues fetch takes about half a minute. */
    public int $timeout = 600;

    public int $tries = 1;

    /** One queued refresh per profile covers every request that saw gaps. */
    public int $uniqueFor = 600;

    /** How long to wait for team lists: well inside [$timeout]. */
    private const int TEAM_FILL_MINUTES = 8;

    public function __construct(
        public int $profileId,
        public bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        return "sports_aed_refresh_{$this->profileId}";
    }

    public function handle(SportsService $sports, SportsAedRefresher $refresher): void
    {
        $profile = AedProfile::find($this->profileId);
        if ($profile === null || ! $profile->sports_matching || ! $sports->isEnabled()) {
            return;
        }

        $refresher->refresh($profile, $this->force);

        // A first run can have more team lists to fetch than a rate limit
        // allows at once: wait for them here, where nothing else is held up,
        // until they're done or stop coming.
        $missing = $refresher->fetchMissingTeams($profile);
        for ($minute = 0; $minute < self::TEAM_FILL_MINUTES && $missing > 0; $minute++) {
            Sleep::for(1)->minute();
            $previouslyMissing = $missing;
            $missing = $refresher->fetchMissingTeams($profile);
            if ($missing >= $previouslyMissing) {
                return;
            }
        }
    }
}
