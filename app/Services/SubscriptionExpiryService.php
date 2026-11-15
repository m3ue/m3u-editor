<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\Notification;
use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\Cache;

class SubscriptionExpiryService
{
    public function __construct(
        private readonly GeneralSettings $settings,
        private readonly AlertService $alertService,
    ) {}

    /**
     * Resolve the provider expiry date from an Xtream `player_api.php` response.
     * Returns null when the provider reports no expiry (blank or 0) or an unparseable value.
     *
     * @param  array<string, mixed>|null  $providerInfo
     */
    public static function expiresAt(?array $providerInfo): ?Carbon
    {
        $value = $providerInfo['user_info']['exp_date'] ?? null;

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

    /**
     * Notify the owner once when a subscription enters the warning window, and once more
     * when it expires. Each state is recorded per expiry date in the database cache store,
     * which survives restarts (the embedded Redis does not persist), so a renewal resets it.
     *
     * @param  array<string, mixed>|null  $providerInfo
     */
    public function notifyIfDue(?array $providerInfo, ?User $owner, string $subscriptionName, string $subscriptionKey): void
    {
        $expiresAt = self::expiresAt($providerInfo);

        if (! $expiresAt || ! $owner) {
            return;
        }

        $secondsRemaining = $expiresAt->getTimestamp() - now()->getTimestamp();
        $expired = $secondsRemaining <= 0;
        $daysRemaining = $expired ? 0 : (int) ceil($secondsRemaining / 86400);

        if (! $expired && $daysRemaining > ($this->settings->subscription_expiry_warning_days ?: 7)) {
            return;
        }

        $store = Cache::store('database');
        $cacheKey = 'subscription-expiry-alert:'.$subscriptionKey.':'.$expiresAt->getTimestamp().':'.($expired ? 'expired' : 'expiring');

        if ($store->has($cacheKey)) {
            return;
        }

        $date = $expiresAt->toFormattedDateString();
        $title = $expired ? __('Subscription expired') : __('Subscription expiring soon');
        $body = $expired
            ? __(':subscription expired on :date.', ['subscription' => $subscriptionName, 'date' => $date])
            : trans_choice(
                ':subscription expires in :days day, on :date.|:subscription expires in :days days, on :date.',
                $daysRemaining,
                ['subscription' => $subscriptionName, 'days' => $daysRemaining, 'date' => $date],
            );

        // Record the alert only once the in-app notification is saved, so a failed save retries next run
        $notification = Notification::make()
            ->warning()
            ->title($title)
            ->body($body)
            ->sendToDatabase($owner);

        $store->put($cacheKey, true, now()->addYear());

        $notification->broadcast($owner);
        $this->alertService->send('['.$title.'] '.$body);
    }
}
