<?php

namespace App\Jobs;

use App\Models\AedProfile;
use App\Services\EspnSportsService;
use App\Services\Sports\SportsAedRefresher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetches a sports-matching AED profile's games from ESPN in the background
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

    public function __construct(
        public int $profileId,
        public bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        return "sports_aed_refresh_{$this->profileId}";
    }

    public function handle(EspnSportsService $espn, SportsAedRefresher $refresher): void
    {
        $profile = AedProfile::find($this->profileId);
        if ($profile === null || ! $profile->sports_matching || ! $espn->isEnabled()) {
            return;
        }

        $refresher->refresh($profile, $this->force);
    }
}
