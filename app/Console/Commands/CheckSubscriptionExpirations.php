<?php

namespace App\Console\Commands;

use App\Models\Playlist;
use App\Models\User;
use App\Notifications\Notification;
use App\Services\AlertService;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckSubscriptionExpirations extends Command
{
    protected $signature = 'app:check-subscription-expirations';

    protected $description = 'Notify playlist owners when Xtream subscriptions are nearing expiry';

    public function __construct(
        private readonly GeneralSettings $settings,
        private readonly AlertService $alertService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->settings->alerts_on_subscription_expiring) {
            return self::SUCCESS;
        }

        Playlist::query()
            ->where('xtream', true)
            ->with([
                'user',
                'profiles' => fn ($query) => $query
                    ->where('enabled', true)
                    ->with('user'),
            ])
            ->chunkById(100, function (Collection $playlists): void {
                foreach ($playlists as $playlist) {
                    $this->checkPlaylist($playlist);
                }
            });

        return self::SUCCESS;
    }

    private function checkPlaylist(Playlist $playlist): void
    {
        if ($playlist->profiles_enabled && $playlist->profiles->isNotEmpty()) {
            foreach ($playlist->profiles as $profile) {
                $this->checkProviderInfo(
                    providerInfo: $profile->provider_info,
                    owner: $profile->user ?? $playlist->user,
                    displayName: $playlist->name.' / '.$profile->name,
                    cacheIdentity: 'profile:'.$profile->id,
                );
            }

            return;
        }

        $this->checkProviderInfo(
            providerInfo: $playlist->xtream_status,
            owner: $playlist->user,
            displayName: $playlist->name,
            cacheIdentity: 'playlist:'.$playlist->id,
        );
    }

    /** @param array<string, mixed>|null $providerInfo */
    private function checkProviderInfo(
        ?array $providerInfo,
        ?User $owner,
        string $displayName,
        string $cacheIdentity,
    ): void {
        $expiresAt = $this->parseExpiration($providerInfo['user_info']['exp_date'] ?? null);

        if (! $expiresAt || ! $owner) {
            return;
        }

        $secondsRemaining = $expiresAt->getTimestamp() - now()->getTimestamp();
        $expired = $secondsRemaining <= 0;
        $daysRemaining = $expired ? 0 : max(1, (int) ceil($secondsRemaining / 86400));
        $warningDays = max(1, min(365, $this->settings->subscription_expiry_warning_days ?? 7));

        if (! $expired && $daysRemaining > $warningDays) {
            return;
        }

        $state = $expired ? 'expired' : 'expiring';
        $cacheKey = 'subscription-expiry-alert:'.$cacheIdentity.':'.$expiresAt->getTimestamp().':'.$state;

        if (! Cache::add($cacheKey, true, now()->addYear())) {
            return;
        }

        $date = $expiresAt->toFormattedDateString();
        $title = $expired ? __('Subscription expired') : __('Subscription expiring soon');
        $body = $expired
            ? __(':subscription expired on :date.', ['subscription' => $displayName, 'date' => $date])
            : trans_choice(
                ':subscription expires in :days day, on :date.|:subscription expires in :days days, on :date.',
                $daysRemaining,
                ['subscription' => $displayName, 'days' => $daysRemaining, 'date' => $date],
            );

        $notification = Notification::make()
            ->warning()
            ->title($title)
            ->body($body);

        try {
            $notification->sendToDatabase($owner);
        } catch (Throwable $exception) {
            Cache::forget($cacheKey);
            Log::error('Unable to save subscription expiry notification', [
                'subscription' => $displayName,
                'exception' => $exception->getMessage(),
            ]);

            return;
        }

        try {
            $notification->broadcast($owner);
        } catch (Throwable $exception) {
            Log::warning('Unable to broadcast subscription expiry notification', [
                'subscription' => $displayName,
                'exception' => $exception->getMessage(),
            ]);
        }

        try {
            $this->alertService->send('['.$title.'] '.$body);
        } catch (Throwable $exception) {
            Log::warning('Unable to forward subscription expiry notification', [
                'subscription' => $displayName,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function parseExpiration(mixed $value): ?Carbon
    {
        if (blank($value) || (is_numeric($value) && (int) $value <= 0)) {
            return null;
        }

        try {
            return is_numeric($value)
                ? Carbon::createFromTimestamp((int) $value)
                : Carbon::parse((string) $value);
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
