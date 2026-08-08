<?php

use App\Models\DeviceAuthorization;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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

it('returns 404 for polling when device pairing is disabled', function () {
    $deviceAuth = DeviceAuthorization::factory()->create();

    $settings = Mockery::mock(GeneralSettings::class);
    $settings->device_pairing_enabled = false;
    $settings->app_output_enabled = true;
    app()->instance(GeneralSettings::class, $settings);

    $this->postJson('/api/device/token', ['device_code' => $deviceAuth->device_code])->assertNotFound();
});
