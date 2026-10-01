<?php

use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\TvDevice;
use App\Models\TvDeviceLog;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;

beforeEach(function () {
    // Endpoint behaviour, not the rate limiter (see TvDeviceRegistryTest).
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

function logsUrl(array $query = [], string $password = 'tv_pass'): string
{
    $base = route('tv.logs.upload', ['username' => 'tv_user', 'password' => $password]);

    return $query === [] ? $base : $base.'?'.http_build_query($query);
}

function deviceIdentity(): array
{
    return [
        'device_id' => 'device-abc',
        'device_name' => 'Living Room SHIELD',
        'platform' => 'androidtv',
        'app_version' => '1.1.4',
    ];
}

it('stores an uploaded log against the device and registers it', function () {
    $response = $this->postJson(logsUrl(deviceIdentity()), ['log' => "header\n---\n[INFO] hello"]);

    $response->assertOk()->assertJsonPath('ok', true);

    $device = TvDevice::firstWhere('device_id', 'device-abc');
    $log = $device->logs()->first();

    expect($response->json('id'))->toBe($log->id)
        ->and($log->content)->toBe("header\n---\n[INFO] hello")
        ->and($log->size_bytes)->toBe(strlen("header\n---\n[INFO] hello"))
        ->and($log->app_version)->toBe('1.1.4')
        ->and($device->notifiable_id)->toBe($this->playlist->id);
});

it('keeps only the newest uploads per device', function () {
    foreach (range(1, TvDeviceLog::KEEP_PER_DEVICE + 2) as $i) {
        $this->postJson(logsUrl(deviceIdentity()), ['log' => "upload {$i}"])->assertOk();
    }

    $device = TvDevice::firstWhere('device_id', 'device-abc');

    expect($device->logs()->count())->toBe(TvDeviceLog::KEEP_PER_DEVICE)
        ->and($device->logs()->first()->content)->toBe('upload '.(TvDeviceLog::KEEP_PER_DEVICE + 2));
});

it('rejects uploads from a revoked device', function () {
    TvDevice::factory()->for($this->playlist, 'notifiable')->create([
        'device_id' => 'device-abc',
        'revoked_at' => now(),
    ]);

    $this->postJson(logsUrl(deviceIdentity()), ['log' => 'nope'])->assertForbidden();

    expect(TvDeviceLog::count())->toBe(0);
});

it('rejects an upload without a device id', function () {
    $this->postJson(logsUrl(), ['log' => 'orphan'])->assertStatus(422);

    expect(TvDeviceLog::count())->toBe(0);
});

it('rejects an empty or oversized log', function () {
    $this->postJson(logsUrl(deviceIdentity()), ['log' => ''])->assertStatus(422);
    $this->postJson(logsUrl(deviceIdentity()), ['log' => str_repeat('x', TvDeviceLog::MAX_BYTES + 1)])
        ->assertStatus(422);

    expect(TvDeviceLog::count())->toBe(0);
});

it('rejects invalid credentials', function () {
    $this->postJson(logsUrl(deviceIdentity(), password: 'wrong'), ['log' => 'x'])->assertUnauthorized();
});

it('deletes a device\'s logs with the device', function () {
    $log = TvDeviceLog::factory()->create();

    $log->device->delete();

    expect(TvDeviceLog::count())->toBe(0);
});
