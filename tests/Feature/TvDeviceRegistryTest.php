<?php

use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PushDeviceToken;
use App\Models\TvDevice;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // The tv/* route group is throttled (60/min per IP). The limiter state is
    // shared across a whole test run, so leave it out here - these tests
    // exercise endpoint behaviour, not the rate limiter. (Matches the pattern
    // in PlaylistAuthNotificationScopeTest / RequesterLifecycleNotificationTest.)
    $this->withoutMiddleware(ThrottleRequestsWithRedis::class);

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->auth = PlaylistAuth::factory()->for($this->user)->create([
        'username' => 'tv_user',
        'password' => 'tv_pass',
        'enabled' => true,
    ]);
    $this->auth->assignTo($this->playlist);
});

function notificationsUrl(array $query = []): string
{
    $base = route('tv.notifications', ['username' => 'tv_user', 'password' => 'tv_pass']);

    return $query === [] ? $base : $base.'?'.http_build_query($query);
}

it('registers a device row from the identity params on the notifications call', function () {
    $this->getJson(notificationsUrl([
        'device_id' => 'device-abc',
        'device_name' => "Shaun's iPhone",
        'platform' => 'ios',
        'app_version' => '1.1.2',
    ]))->assertOk()->assertJsonPath('device_revoked', false);

    $device = TvDevice::firstWhere('device_id', 'device-abc');

    expect($device)->not->toBeNull()
        ->and($device->device_name)->toBe("Shaun's iPhone")
        ->and($device->platform)->toBe('ios')
        ->and($device->app_version)->toBe('1.1.2')
        ->and($device->playlist_auth_id)->toBe($this->auth->id)
        ->and($device->notifiable_id)->toBe($this->playlist->id)
        ->and($device->last_seen_at)->not->toBeNull();
});

it('does nothing when no device_id is sent (older app builds)', function () {
    $this->getJson(notificationsUrl())->assertOk();

    expect(TvDevice::count())->toBe(0);
});

it('debounces last_seen_at writes to once per 5 minutes', function () {
    $device = TvDevice::factory()->create([
        'device_id' => 'device-abc',
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth->id,
        'device_name' => 'Old Name',
        'platform' => 'ios',
        'app_version' => '1.1.2',
        'last_seen_at' => now()->subMinutes(2),
    ]);
    $seenBefore = $device->fresh()->last_seen_at;

    // Same identity, within the window -> skipped.
    $this->getJson(notificationsUrl([
        'device_id' => 'device-abc',
        'device_name' => 'Old Name',
        'platform' => 'ios',
        'app_version' => '1.1.2',
    ]))->assertOk();

    expect($device->fresh()->last_seen_at->equalTo($seenBefore))->toBeTrue();

    // A changed name always writes, even inside the window.
    $this->getJson(notificationsUrl([
        'device_id' => 'device-abc',
        'device_name' => 'New Name',
        'platform' => 'ios',
        'app_version' => '1.1.2',
    ]))->assertOk();

    expect($device->fresh()->device_name)->toBe('New Name');
});

it('tells a revoked device to log out and does not resurrect the row', function () {
    TvDevice::factory()->revoked()->create([
        'device_id' => 'device-abc',
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'device_name' => 'Revoked Phone',
    ]);

    $this->getJson(notificationsUrl([
        'device_id' => 'device-abc',
        'device_name' => 'Revoked Phone renamed',
        'platform' => 'ios',
        'app_version' => '1.1.2',
    ]))->assertOk()->assertJsonPath('device_revoked', true);

    $device = TvDevice::firstWhere('device_id', 'device-abc');
    expect($device->revoked_at)->not->toBeNull()
        ->and($device->device_name)->toBe('Revoked Phone');
});

it('upserts on device_id so a concurrent first-touch cannot duplicate or 500', function () {
    // A concurrent notifications call for this same brand-new device won the
    // INSERT in the gap after firstOrNew() read "not found". The write must
    // resolve to an in-place update via ON CONFLICT, not a duplicate or error.
    DB::table('tv_devices')->insert([
        'device_id' => 'race-abc',
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'device_name' => 'Winner',
        'last_seen_at' => now()->subHour(),
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $this->getJson(notificationsUrl([
        'device_id' => 'race-abc',
        'device_name' => 'Racer',
        'platform' => 'ios',
        'app_version' => '1.1.2',
    ]))->assertOk()->assertJsonPath('device_revoked', false);

    expect(TvDevice::where('device_id', 'race-abc')->count())->toBe(1);

    $device = TvDevice::firstWhere('device_id', 'race-abc');
    expect($device->device_name)->toBe('Racer')
        ->and($device->platform)->toBe('ios')
        ->and($device->last_seen_at->gt(now()->subMinute()))->toBeTrue();
});

it('stores device_id and device_name from a push subscribe call', function () {
    $this->postJson(route('tv.push.subscribe', ['username' => 'tv_user', 'password' => 'tv_pass']), [
        'token' => 'fcm-token-1',
        'platform' => 'ios',
        'device_id' => 'device-abc',
        'device_name' => "Shaun's iPhone",
    ])->assertOk();

    $token = PushDeviceToken::firstWhere('token', 'fcm-token-1');
    expect($token->device_id)->toBe('device-abc')
        ->and($token->device_name)->toBe("Shaun's iPhone");
});
