<?php

use App\Models\DeviceAuthorization;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withServerVariables(['REMOTE_ADDR' => fake()->ipv4()]);
    Bus::fake();
    Http::preventStrayRequests();
});

it('keeps pending polls at the advertised interval without increasing it', function (bool $pollEarly) {
    $this->freezeSecond();

    $code = $this->postJson('/api/device/code')->assertOk()->assertJsonPath('interval', 5)->json('device_code');
    $deviceAuth = DeviceAuthorization::where('device_code', $code)->firstOrFail();
    $neighborCode = $this->postJson('/api/device/code')->assertOk()->json('device_code');
    $neighbor = DeviceAuthorization::where('device_code', $neighborCode)->firstOrFail();
    $neighborAttributes = $neighbor->getAttributes();

    expect($deviceAuth->last_polled_at)->toBeNull()
        ->and($deviceAuth->poll_attempts)->toBe(0);

    $steps = [[0, 'pending', 5]];
    if ($pollEarly) {
        $steps[] = [4, 'slow_down', 10];
        $steps[] = [10, 'pending', 10];
    } else {
        $steps[] = [5, 'pending', 5];
    }
    $steps[] = [31, 'pending', $pollEarly ? 10 : 5];
    $steps[] = [60, 'pending', $pollEarly ? 10 : 5];

    foreach ($steps as $index => [$elapsed, $status, $interval]) {
        $this->travel($elapsed)->seconds();
        $this->postJson('/api/device/token', ['device_code' => $code])
            ->assertOk()
            ->assertExactJson(['status' => $status, 'interval' => $interval]);

        $fresh = $deviceAuth->fresh();
        expect($fresh->status)->toBe('pending')
            ->and($fresh->poll_attempts)->toBe($index + 1)
            ->and($fresh->interval_seconds)->toBe($interval)
            ->and($fresh->last_polled_at->equalTo(now()))->toBeTrue()
            ->and($neighbor->fresh()->getAttributes())->toBe($neighborAttributes);
    }
})->with(['initial interval' => false, 'increased interval after early poll' => true]);

it('returns terminal pairing states before enforcing the polling interval', function (string $status, bool $expired, string $expected) {
    $this->freezeSecond();
    $code = $this->postJson('/api/device/code')->assertOk()->json('device_code');
    $deviceAuth = DeviceAuthorization::where('device_code', $code)->firstOrFail();
    $playlistAuth = PlaylistAuth::factory()->for(User::factory())->create();
    $deviceAuth->update([
        'status' => $status,
        'playlist_auth_id' => $status === 'approved' ? $playlistAuth->id : null,
        'last_polled_at' => now(),
        'expires_at' => $expired ? now() : now()->addMinutes(10),
    ]);

    $response = $this->postJson('/api/device/token', ['device_code' => $code])
        ->assertOk()->assertJsonPath('status', $expected)->assertJsonMissingPath('interval');
    if ($expected === 'approved') {
        $response->assertJson(['username' => $playlistAuth->username, 'password' => $playlistAuth->password]);
    }
    expect($deviceAuth->fresh())->toBeNull();
    $this->postJson('/api/device/token', ['device_code' => $code])->assertExactJson(['status' => 'pending']);
})->with([
    'approval' => ['approved', false, 'approved'],
    'denial' => ['denied', false, 'denied'],
    'expiry' => ['pending', true, 'expired'],
    'expiry before approval' => ['approved', true, 'expired'],
    'expiry before denial' => ['denied', true, 'expired'],
]);

it('returns pending for an unknown device_code without leaking existence', function () {
    $response = $this->postJson('/api/device/token', ['device_code' => 'does-not-exist']);

    $response->assertOk()->assertJson(['status' => 'pending']);
});

it('returns pending while a code is still waiting for approval', function () {
    $deviceAuth = DeviceAuthorization::factory()->create();

    $response = $this->postJson('/api/device/token', ['device_code' => $deviceAuth->device_code]);

    $response->assertOk()->assertJson(['status' => 'pending']);
    $this->assertDatabaseHas('device_authorizations', ['id' => $deviceAuth->id, 'poll_attempts' => 1]);
});

it('slows down a client that polls faster than the allowed interval', function () {
    $deviceAuth = DeviceAuthorization::factory()->create([
        'interval_seconds' => 5,
        'last_polled_at' => now(),
    ]);

    $response = $this->postJson('/api/device/token', ['device_code' => $deviceAuth->device_code]);

    $response->assertOk()->assertJson(['status' => 'slow_down', 'interval' => 10]);
});

it('reports expired codes and deletes them', function () {
    $deviceAuth = DeviceAuthorization::factory()->expired()->create();

    $response = $this->postJson('/api/device/token', ['device_code' => $deviceAuth->device_code]);

    $response->assertOk()->assertJson(['status' => 'expired']);
    $this->assertDatabaseMissing('device_authorizations', ['id' => $deviceAuth->id]);
});

it('hands back the assigned credential once and consumes the code', function () {
    $user = User::factory()->create();
    $playlistAuth = PlaylistAuth::factory()->for($user)->create([
        'username' => 'tv-user',
        'password' => 'tv-pass',
    ]);
    $deviceAuth = DeviceAuthorization::factory()->approved()->create([
        'playlist_auth_id' => $playlistAuth->id,
    ]);

    $response = $this->postJson('/api/device/token', ['device_code' => $deviceAuth->device_code]);

    $response->assertOk()->assertJson([
        'status' => 'approved',
        'username' => 'tv-user',
        'password' => 'tv-pass',
    ]);
    $this->assertDatabaseMissing('device_authorizations', ['id' => $deviceAuth->id]);

    // A repeat poll with the same device_code must never re-serve credentials.
    $second = $this->postJson('/api/device/token', ['device_code' => $deviceAuth->device_code]);
    $second->assertOk()->assertJson(['status' => 'pending']);
});

it('hands back every granted credential in order, primary first', function () {
    $user = User::factory()->create();
    $kids = PlaylistAuth::factory()->for($user)->create(['name' => 'Kids', 'username' => 'kids', 'password' => 'k-pass']);
    $living = PlaylistAuth::factory()->for($user)->create(['name' => 'Living Room', 'username' => 'living', 'password' => 'l-pass']);
    $deleted = PlaylistAuth::factory()->for($user)->create();
    $deviceAuth = DeviceAuthorization::factory()->approved()->create([
        'playlist_auth_id' => $kids->id,
        'playlist_auth_ids' => [$kids->id, $deleted->id, $living->id],
    ]);
    $deleted->delete();

    $response = $this->postJson('/api/device/token', ['device_code' => $deviceAuth->device_code]);

    $response->assertOk()->assertJson([
        'status' => 'approved',
        'username' => 'kids',
        'password' => 'k-pass',
        'playlists' => [
            ['name' => 'Kids', 'username' => 'kids', 'password' => 'k-pass'],
            ['name' => 'Living Room', 'username' => 'living', 'password' => 'l-pass'],
        ],
    ]);
    expect($response->json('playlists'))->toHaveCount(2);
});

it('lists the single credential of a pairing approved before ordering existed', function () {
    $user = User::factory()->create();
    $auth = PlaylistAuth::factory()->for($user)->create(['name' => 'Only', 'username' => 'only', 'password' => 'o-pass']);
    $deviceAuth = DeviceAuthorization::factory()->approved()->create(['playlist_auth_id' => $auth->id]);

    $this->postJson('/api/device/token', ['device_code' => $deviceAuth->device_code])
        ->assertOk()
        ->assertJson([
            'username' => 'only',
            'playlists' => [['name' => 'Only', 'username' => 'only', 'password' => 'o-pass']],
        ]);
});

it('returns 404 for polling when device pairing is disabled', function () {
    $this->freezeSecond();
    $deviceAuth = DeviceAuthorization::factory()->approved()->create(['last_polled_at' => now()]);
    $attributes = $deviceAuth->fresh()->getAttributes();

    $settings = Mockery::mock(GeneralSettings::class);
    $settings->device_pairing_enabled = false;
    $settings->app_output_enabled = true;
    app()->instance(GeneralSettings::class, $settings);

    $this->postJson('/api/device/token', ['device_code' => $deviceAuth->device_code])->assertNotFound();
    expect($deviceAuth->fresh()->getAttributes())->toBe($attributes);
});
