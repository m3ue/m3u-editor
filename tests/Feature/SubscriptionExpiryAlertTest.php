<?php

use App\Jobs\UpdateXtreamStats;
use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Models\User;
use App\Services\AlertService;
use App\Settings\GeneralSettings;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;

beforeEach(function () {
    Bus::fake();
    NotificationFacade::fake();
    Http::preventStrayRequests();
});

function enableSubscriptionExpiryAlerts(int $warningDays = 7): void
{
    $settings = app(GeneralSettings::class);
    $settings->alerts_on_subscription_expiring = true;
    $settings->subscription_expiry_warning_days = $warningDays;
    $settings->save();
}

function expiringXtreamPlaylist(User $user, int $daysUntilExpiry): Playlist
{
    return Playlist::factory()->for($user)->create([
        'xtream' => true,
        'profiles_enabled' => false,
        'xtream_status' => [
            'user_info' => [
                'exp_date' => now()->addDays($daysUntilExpiry)->getTimestamp(),
            ],
        ],
    ]);
}

it('does nothing when subscription expiry alerts are disabled', function () {
    $user = User::factory()->create();
    expiringXtreamPlaylist($user, 3);

    $alertService = Mockery::mock(AlertService::class);
    $alertService->shouldNotReceive('send');
    app()->instance(AlertService::class, $alertService);

    $this->artisan('app:check-subscription-expirations')->assertSuccessful();

    NotificationFacade::assertNotSentTo($user, DatabaseNotification::class);
});

it('creates one in-app alert and forwards it to configured alert channels', function () {
    enableSubscriptionExpiryAlerts();
    $user = User::factory()->create();
    $playlist = expiringXtreamPlaylist($user, 3);

    $alertService = Mockery::mock(AlertService::class);
    $alertService->shouldReceive('send')
        ->once()
        ->with(Mockery::on(fn (string $message): bool => str_contains($message, $playlist->name)));
    app()->instance(AlertService::class, $alertService);

    $this->artisan('app:check-subscription-expirations')->assertSuccessful();

    // The default cache store (embedded Redis) does not persist across restarts
    Cache::flush();
    $this->artisan('app:check-subscription-expirations')->assertSuccessful();

    NotificationFacade::assertSentToTimes($user, DatabaseNotification::class, 1);
    Bus::assertNotDispatched(UpdateXtreamStats::class);
    NotificationFacade::assertSentTo(
        $user,
        DatabaseNotification::class,
        fn (DatabaseNotification $notification): bool => $notification->data['title'] === 'Subscription expiring soon'
            && str_contains($notification->data['body'], $playlist->name)
            && $notification->data['status'] === 'warning',
    );
});

it('uses the warning window configured in the alerts pane', function () {
    enableSubscriptionExpiryAlerts(warningDays: 3);
    $user = User::factory()->create();
    expiringXtreamPlaylist($user, 5);

    $alertService = Mockery::mock(AlertService::class);
    $alertService->shouldNotReceive('send');
    app()->instance(AlertService::class, $alertService);

    $this->artisan('app:check-subscription-expirations')->assertSuccessful();

    NotificationFacade::assertNotSentTo($user, DatabaseNotification::class);
});

it('checks each provider profile when playlist pooling is enabled', function () {
    enableSubscriptionExpiryAlerts();
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'name' => 'Pooled provider',
        'xtream' => true,
        'profiles_enabled' => true,
        'xtream_status' => [
            'user_info' => [
                'exp_date' => now()->addDays(2)->getTimestamp(),
            ],
        ],
    ]);
    $profile = PlaylistProfile::factory()->forPlaylist($playlist)->create([
        'name' => 'Backup account',
        'provider_info' => [
            'user_info' => [
                'exp_date' => now()->addDays(4)->getTimestamp(),
            ],
        ],
    ]);

    $alertService = Mockery::mock(AlertService::class);
    $alertService->shouldReceive('send')
        ->once()
        ->with(Mockery::on(fn (string $message): bool => str_contains($message, $profile->name)));
    app()->instance(AlertService::class, $alertService);

    $this->artisan('app:check-subscription-expirations')->assertSuccessful();

    NotificationFacade::assertSentTo(
        $user,
        DatabaseNotification::class,
        fn (DatabaseNotification $notification): bool => str_contains($notification->data['body'], $playlist->name)
            && str_contains($notification->data['body'], $profile->name),
    );
});

it('sends a separate alert after the subscription has expired', function () {
    enableSubscriptionExpiryAlerts();
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'xtream' => true,
        'profiles_enabled' => false,
        'xtream_status' => [
            'user_info' => [
                'exp_date' => now()->subHour()->getTimestamp(),
            ],
        ],
    ]);

    $alertService = Mockery::mock(AlertService::class);
    $alertService->shouldReceive('send')
        ->once()
        ->with(Mockery::on(fn (string $message): bool => str_contains($message, 'Subscription expired')));
    app()->instance(AlertService::class, $alertService);

    $this->artisan('app:check-subscription-expirations')->assertSuccessful();

    NotificationFacade::assertSentTo(
        $user,
        DatabaseNotification::class,
        fn (DatabaseNotification $notification): bool => $notification->data['title'] === 'Subscription expired',
    );
});

it('alerts for an expired provider profile that was auto-disabled', function () {
    enableSubscriptionExpiryAlerts();
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'xtream' => true,
        'profiles_enabled' => true,
    ]);
    $profile = PlaylistProfile::factory()->forPlaylist($playlist)->create([
        'name' => 'Expired account',
        'enabled' => false,
        'provider_info' => [
            'user_info' => [
                'exp_date' => now()->subHour()->getTimestamp(),
            ],
        ],
    ]);

    $alertService = Mockery::mock(AlertService::class);
    $alertService->shouldReceive('send')
        ->once()
        ->with(Mockery::on(fn (string $message): bool => str_contains($message, 'Subscription expired') && str_contains($message, $profile->name)));
    app()->instance(AlertService::class, $alertService);

    $this->artisan('app:check-subscription-expirations')->assertSuccessful();

    NotificationFacade::assertSentToTimes($user, DatabaseNotification::class, 1);
});
