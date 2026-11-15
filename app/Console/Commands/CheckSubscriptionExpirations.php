<?php

namespace App\Console\Commands;

use App\Models\Playlist;
use App\Services\SubscriptionExpiryService;
use App\Settings\GeneralSettings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class CheckSubscriptionExpirations extends Command
{
    protected $signature = 'app:check-subscription-expirations';

    protected $description = 'Notify playlist owners when Xtream subscriptions are nearing expiry';

    public function handle(GeneralSettings $settings, SubscriptionExpiryService $expiryService): int
    {
        if (! $settings->alerts_on_subscription_expiring) {
            return self::SUCCESS;
        }

        Playlist::query()
            ->where('xtream', true)
            ->with(['user', 'profiles.user'])
            ->chunkById(100, function (Collection $playlists) use ($expiryService): void {
                foreach ($playlists as $playlist) {
                    try {
                        $this->checkPlaylist($playlist, $expiryService);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });

        return self::SUCCESS;
    }

    /**
     * Pooled playlists are checked per provider profile (the primary profile holds the playlist's
     * own account). Disabled profiles are included because expired profiles are auto-disabled by
     * RefreshPlaylistProfiles before this runs. Stored provider info is read directly, since the
     * xtream_status accessor would queue a provider refresh for every playlist.
     */
    private function checkPlaylist(Playlist $playlist, SubscriptionExpiryService $expiryService): void
    {
        if ($playlist->profiles_enabled && $playlist->profiles->isNotEmpty()) {
            foreach ($playlist->profiles as $profile) {
                $expiryService->notifyIfDue(
                    $profile->provider_info,
                    $profile->user ?? $playlist->user,
                    $playlist->name.' / '.$profile->name,
                    'profile:'.$profile->id,
                );
            }

            return;
        }

        $expiryService->notifyIfDue(
            json_decode($playlist->getRawOriginal('xtream_status') ?? '', true),
            $playlist->user,
            $playlist->name,
            'playlist:'.$playlist->id,
        );
    }
}
