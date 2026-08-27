<?php

use App\Events\DeviceDeregisteredEvent;
use App\Filament\Resources\PushDeviceTokens\Pages\ListPushDeviceTokens;
use App\Filament\Resources\PushDeviceTokens\PushDeviceTokenResource;
use App\Models\Playlist;
use App\Models\PushDeviceToken;
use App\Models\TvDevice;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->playlist = Playlist::factory()->for($this->admin)->create();
    $this->actingAs($this->admin);
});

it('is only accessible to admins', function () {
    expect(PushDeviceTokenResource::canAccess())->toBeTrue();

    $this->actingAs(User::factory()->create());
    expect(PushDeviceTokenResource::canAccess())->toBeFalse();
});

it('blocks non-admin users from reaching the list page', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(ListPushDeviceTokens::class)
        ->assertForbidden();
});

it('lists devices across every user\'s playlists, not just the admin\'s own', function () {
    $ownDevice = TvDevice::factory()->for($this->playlist, 'notifiable')->create();

    $otherPlaylist = Playlist::factory()->for(User::factory()->create())->create();
    $otherDevice = TvDevice::factory()->for($otherPlaylist, 'notifiable')->create();

    Livewire::test(ListPushDeviceTokens::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$ownDevice, $otherDevice]);
});

it('logs a device out: broadcasts a logout and drops its push token, without revoking', function () {
    Event::fake([DeviceDeregisteredEvent::class]);

    $device = TvDevice::factory()->for($this->playlist, 'notifiable')->create([
        'device_id' => 'device-abc',
        'app_version' => '1.1.2',
    ]);
    $pushToken = PushDeviceToken::factory()->for($this->playlist, 'notifiable')->create([
        'device_id' => 'device-abc',
    ]);

    Livewire::test(ListPushDeviceTokens::class)
        ->loadTable()
        ->callAction(TestAction::make('logout')->table($device));

    expect($device->fresh()->revoked_at)->toBeNull()
        ->and(PushDeviceToken::find($pushToken->id))->toBeNull();

    Event::assertDispatched(DeviceDeregisteredEvent::class, fn ($event) => $event->deviceId === 'device-abc');
});

it('revokes a device: marks it revoked, broadcasts a logout, drops its push token', function () {
    Event::fake([DeviceDeregisteredEvent::class]);

    $device = TvDevice::factory()->for($this->playlist, 'notifiable')->create([
        'device_id' => 'device-abc',
        'app_version' => '1.1.2',
    ]);
    $pushToken = PushDeviceToken::factory()->for($this->playlist, 'notifiable')->create([
        'device_id' => 'device-abc',
    ]);

    Livewire::test(ListPushDeviceTokens::class)
        ->loadTable()
        ->callAction(TestAction::make('revoke')->table($device));

    expect($device->fresh()->revoked_at)->not->toBeNull()
        ->and(PushDeviceToken::find($pushToken->id))->toBeNull();

    Event::assertDispatched(DeviceDeregisteredEvent::class, fn ($event) => $event->deviceId === 'device-abc');
});

it('restores a revoked device so it can pair again', function () {
    $device = TvDevice::factory()->revoked()->for($this->playlist, 'notifiable')->create([
        'app_version' => '1.1.2',
    ]);

    Livewire::test(ListPushDeviceTokens::class)
        ->loadTable()
        ->callAction(TestAction::make('restore')->table($device));

    expect($device->fresh()->revoked_at)->toBeNull();
});

it('swaps Revoke access for Restore access once a device is revoked', function () {
    $active = TvDevice::factory()->for($this->playlist, 'notifiable')->create([
        'app_version' => '1.1.2',
    ]);
    $revoked = TvDevice::factory()->revoked()->for($this->playlist, 'notifiable')->create([
        'app_version' => '1.1.2',
    ]);

    Livewire::test(ListPushDeviceTokens::class)
        ->loadTable()
        ->assertActionVisible(TestAction::make('revoke')->table($active))
        ->assertActionHidden(TestAction::make('restore')->table($active))
        ->assertActionVisible(TestAction::make('restore')->table($revoked))
        ->assertActionHidden(TestAction::make('revoke')->table($revoked));
});

it('also drops a legacy push token that predates device_id when revoking', function () {
    Event::fake([DeviceDeregisteredEvent::class]);

    $device = TvDevice::factory()->for($this->playlist, 'notifiable')->create([
        'device_id' => 'device-abc',
        'app_version' => '1.1.2',
        'platform' => 'ios',
        'playlist_auth_id' => null,
    ]);

    // Registered on an older build: no device_id, matchable only by identity.
    $legacyToken = PushDeviceToken::factory()->for($this->playlist, 'notifiable')->create([
        'device_id' => null,
        'platform' => 'ios',
        'playlist_auth_id' => null,
    ]);

    // A token for a different platform must survive.
    $unrelatedToken = PushDeviceToken::factory()->for($this->playlist, 'notifiable')->create([
        'device_id' => null,
        'platform' => 'android',
        'playlist_auth_id' => null,
    ]);

    Livewire::test(ListPushDeviceTokens::class)
        ->loadTable()
        ->callAction(TestAction::make('revoke')->table($device));

    expect(PushDeviceToken::find($legacyToken->id))->toBeNull()
        ->and(PushDeviceToken::find($unrelatedToken->id))->not->toBeNull();
});

it('disables Log out and Revoke for devices on an app version older than the deregister minimum', function () {
    $legacy = TvDevice::factory()->legacyVersion()->for($this->playlist, 'notifiable')->create();

    Livewire::test(ListPushDeviceTokens::class)
        ->loadTable()
        ->assertActionDisabled(TestAction::make('logout')->table($legacy))
        ->assertActionDisabled(TestAction::make('revoke')->table($legacy));
});

it('can hard-delete a device row without broadcasting a logout', function () {
    Event::fake([DeviceDeregisteredEvent::class]);

    $device = TvDevice::factory()->for($this->playlist, 'notifiable')->create();

    Livewire::test(ListPushDeviceTokens::class)
        ->loadTable()
        ->callAction(TestAction::make('delete')->table($device));

    expect(TvDevice::find($device->id))->toBeNull();
    Event::assertNotDispatched(DeviceDeregisteredEvent::class);
});

it('allows deleting an already-revoked or legacy-version device', function () {
    $revoked = TvDevice::factory()->revoked()->for($this->playlist, 'notifiable')->create();
    $legacy = TvDevice::factory()->legacyVersion()->for($this->playlist, 'notifiable')->create();

    Livewire::test(ListPushDeviceTokens::class)
        ->loadTable()
        ->assertActionEnabled(TestAction::make('delete')->table($revoked))
        ->assertActionEnabled(TestAction::make('delete')->table($legacy));
});

it('filters to revoked devices', function () {
    $revoked = TvDevice::factory()->revoked()->for($this->playlist, 'notifiable')->create();
    $active = TvDevice::factory()->for($this->playlist, 'notifiable')->create();

    Livewire::test(ListPushDeviceTokens::class)
        ->loadTable()
        ->filterTable('revoked')
        ->assertCanSeeTableRecords([$revoked])
        ->assertCanNotSeeTableRecords([$active]);
});

it('filters to stale devices past the prune window', function () {
    config(['services.push_relay.stale_days' => 60]);

    $stale = TvDevice::factory()->for($this->playlist, 'notifiable')
        ->create(['last_seen_at' => now()->subDays(61)]);
    $fresh = TvDevice::factory()->for($this->playlist, 'notifiable')
        ->create(['last_seen_at' => now()->subDays(1)]);

    Livewire::test(ListPushDeviceTokens::class)
        ->loadTable()
        ->filterTable('stale')
        ->assertCanSeeTableRecords([$stale])
        ->assertCanNotSeeTableRecords([$fresh]);
});

it('renames the first tab to Registered Devices', function () {
    $tabs = Livewire::test(ListPushDeviceTokens::class)->instance()->getTabs();

    expect($tabs['devices']->getLabel())->toBe('Registered Devices');
});
