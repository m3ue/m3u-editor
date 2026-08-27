<?php

use App\Events\DeviceDeregisteredEvent;
use App\Models\Playlist;
use App\Models\PushDeviceToken;
use App\Models\TvDevice;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->playlist = Playlist::factory()->for(User::factory()->create())->create();
});

it('logOut() broadcasts a deregister and drops the push token but keeps the row active', function () {
    Event::fake([DeviceDeregisteredEvent::class]);

    $device = TvDevice::factory()->for($this->playlist, 'notifiable')->create([
        'device_id' => 'device-abc',
        'app_version' => '1.1.2',
    ]);
    $token = PushDeviceToken::factory()->for($this->playlist, 'notifiable')->create([
        'device_id' => 'device-abc',
    ]);

    $device->logOut();

    expect($device->fresh()->revoked_at)->toBeNull()
        ->and(PushDeviceToken::find($token->id))->toBeNull();

    Event::assertDispatched(DeviceDeregisteredEvent::class, fn ($e) => $e->deviceId === 'device-abc');
});

it('revokeAccess() logs out and marks the row revoked; restoreAccess() lifts it', function () {
    Event::fake([DeviceDeregisteredEvent::class]);

    $device = TvDevice::factory()->for($this->playlist, 'notifiable')->create([
        'device_id' => 'device-abc',
        'app_version' => '1.1.2',
    ]);

    $device->revokeAccess();
    expect($device->fresh()->revoked_at)->not->toBeNull();
    Event::assertDispatchedTimes(DeviceDeregisteredEvent::class, 1);

    // A second revoke is a no-op (no extra broadcast).
    $device->fresh()->revokeAccess();
    Event::assertDispatchedTimes(DeviceDeregisteredEvent::class, 1);

    $device->fresh()->restoreAccess();
    expect($device->fresh()->revoked_at)->toBeNull();
});

it('keeps revoked rows out of the prune set but still prunes stale active rows', function () {
    config(['services.push_relay.stale_days' => 60]);

    $staleActive = TvDevice::factory()->for($this->playlist, 'notifiable')->create([
        'last_seen_at' => now()->subDays(61),
    ]);
    $staleRevoked = TvDevice::factory()->revoked()->for($this->playlist, 'notifiable')->create([
        'last_seen_at' => now()->subDays(400),
        'revoked_at' => now()->subDays(200),
    ]);
    $freshActive = TvDevice::factory()->for($this->playlist, 'notifiable')->create([
        'last_seen_at' => now()->subDay(),
    ]);

    $pruned = (new TvDevice)->pruneAll();

    expect($pruned)->toBe(1)
        ->and(TvDevice::find($staleActive->id))->toBeNull()
        ->and(TvDevice::find($staleRevoked->id))->not->toBeNull()
        ->and(TvDevice::find($freshActive->id))->not->toBeNull();
});
