<?php

use App\Filament\Clusters\Devices\DevicesCluster;
use App\Filament\Clusters\Devices\Pages\PairDevice;
use App\Filament\Resources\TvDevices\TvDeviceResource;
use App\Models\DeviceAuthorization;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('hides the device pairing page from non-admins', function () {
    $this->actingAs(User::factory()->create());

    expect(PairDevice::canAccess())->toBeFalse()
        ->and(TvDeviceResource::canAccess())->toBeFalse();
});

it('allows admins to access the device pairing page', function () {
    $this->actingAs(User::factory()->admin()->create());

    expect(PairDevice::canAccess())->toBeTrue()
        ->and(TvDeviceResource::canAccess())->toBeTrue();
});

it('approves a pending code and assigns the chosen credential', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $playlistAuth = PlaylistAuth::factory()->for($admin)->create();
    $deviceAuth = DeviceAuthorization::factory()->create();

    Livewire::test(PairDevice::class)
        ->fillForm([
            'user_code' => $deviceAuth->user_code,
            'playlist_auth_ids' => [$playlistAuth->id],
        ], 'content')
        ->call('approve');

    $this->assertDatabaseHas('device_authorizations', [
        'id' => $deviceAuth->id,
        'status' => 'approved',
        'playlist_auth_id' => $playlistAuth->id,
        'approved_by_user_id' => $admin->id,
    ]);
});

it('approves a code typed lowercase and without the dash', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $playlistAuth = PlaylistAuth::factory()->for($admin)->create();
    $deviceAuth = DeviceAuthorization::factory()->create(['user_code' => 'XKQP-9F3T']);

    Livewire::test(PairDevice::class)
        ->fillForm([
            'user_code' => 'xkqp9f3t',
            'playlist_auth_ids' => [$playlistAuth->id],
        ], 'content')
        ->call('approve');

    $this->assertDatabaseHas('device_authorizations', [
        'id' => $deviceAuth->id,
        'status' => 'approved',
        'playlist_auth_id' => $playlistAuth->id,
    ]);
});

it('approves a code typed with extra whitespace around the dash', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $playlistAuth = PlaylistAuth::factory()->for($admin)->create();
    $deviceAuth = DeviceAuthorization::factory()->create(['user_code' => 'XKQP-9F3T']);

    Livewire::test(PairDevice::class)
        ->fillForm([
            'user_code' => ' xkqp 9f3t ',
            'playlist_auth_ids' => [$playlistAuth->id],
        ], 'content')
        ->call('approve');

    $this->assertDatabaseHas('device_authorizations', [
        'id' => $deviceAuth->id,
        'status' => 'approved',
        'playlist_auth_id' => $playlistAuth->id,
    ]);
});

it('shows a generic error for an unknown or expired code', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $playlistAuth = PlaylistAuth::factory()->for($admin)->create();

    Livewire::test(PairDevice::class)
        ->fillForm([
            'user_code' => 'ZZZZ-ZZZZ',
            'playlist_auth_ids' => [$playlistAuth->id],
        ], 'content')
        ->call('approve')
        ->assertNotified();
});

it('only offers the authenticated admin\'s own playlist auths in the picker', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $ownAuth = PlaylistAuth::factory()->for($admin)->create();

    $otherUser = User::factory()->create();
    $otherAuth = PlaylistAuth::factory()->for($otherUser)->create();

    $options = PlaylistAuth::where('user_id', auth()->id())->pluck('name', 'id')->all();

    expect($options)->toHaveKey($ownAuth->id);
    expect($options)->not->toHaveKey($otherAuth->id);
});

it('rejects approval when the posted playlist_auth_id does not belong to the admin', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $otherUser = User::factory()->create();
    $otherAuth = PlaylistAuth::factory()->for($otherUser)->create();
    $deviceAuth = DeviceAuthorization::factory()->create();

    Livewire::test(PairDevice::class)
        ->fillForm([
            'user_code' => $deviceAuth->user_code,
            'playlist_auth_id' => $otherAuth->id,
        ], 'content')
        ->call('approve');

    $this->assertDatabaseHas('device_authorizations', [
        'id' => $deviceAuth->id,
        'status' => 'pending',
    ]);
});

it('hides the pairing page when device pairing is disabled', function () {
    $this->actingAs(User::factory()->admin()->create());

    $settings = Mockery::mock(GeneralSettings::class);
    $settings->device_pairing_enabled = false;
    $settings->app_output_enabled = true;
    $settings->push_relay_enabled = true;
    app()->instance(GeneralSettings::class, $settings);

    expect(PairDevice::canAccess())->toBeFalse()
        ->and(PairDevice::shouldRegisterNavigation())->toBeFalse()
        ->and(TvDeviceResource::canAccess())->toBeTrue();
});

it('keeps the pairing page available when only push relay is disabled', function () {
    $this->actingAs(User::factory()->admin()->create());

    $settings = Mockery::mock(GeneralSettings::class);
    $settings->push_relay_enabled = false;
    $settings->device_pairing_enabled = true;
    $settings->app_output_enabled = true;
    app()->instance(GeneralSettings::class, $settings);

    expect(TvDeviceResource::canAccess())->toBeFalse()
        ->and(TvDeviceResource::shouldRegisterNavigation())->toBeFalse()
        ->and(PairDevice::canAccess())->toBeTrue()
        ->and(DevicesCluster::canAccess())->toBeTrue();
});

it('denies cluster access and hides the nav item when push relay, device pairing and enhanced output are all disabled', function () {
    $this->actingAs(User::factory()->admin()->create());

    // Enhanced output on would keep the cluster up for Settings Profiles.
    $settings = Mockery::mock(GeneralSettings::class);
    $settings->push_relay_enabled = false;
    $settings->device_pairing_enabled = false;
    $settings->app_output_enabled = false;
    app()->instance(GeneralSettings::class, $settings);

    expect(DevicesCluster::canAccess())->toBeFalse()
        ->and(DevicesCluster::shouldRegisterNavigation())->toBeFalse()
        ->and(TvDeviceResource::canAccess())->toBeFalse()
        ->and(PairDevice::canAccess())->toBeFalse();
});

it('approves several credentials in the chosen order, first as primary', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $living = PlaylistAuth::factory()->for($admin)->create();
    $kids = PlaylistAuth::factory()->for($admin)->create();
    $sports = PlaylistAuth::factory()->for($admin)->create();
    $deviceAuth = DeviceAuthorization::factory()->create();

    Livewire::test(PairDevice::class)
        ->fillForm([
            'user_code' => $deviceAuth->user_code,
            'playlist_auth_ids' => [$kids->id, $living->id, $sports->id],
        ], 'content')
        ->call('approve');

    $deviceAuth->refresh();
    expect($deviceAuth->status)->toBe('approved')
        ->and($deviceAuth->playlist_auth_id)->toBe($kids->id)
        ->and($deviceAuth->playlist_auth_ids)->toBe([$kids->id, $living->id, $sports->id]);
});

it('refuses a pairing that includes another user\'s credential', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $own = PlaylistAuth::factory()->for($admin)->create();
    $other = PlaylistAuth::factory()->for(User::factory()->create())->create();
    $deviceAuth = DeviceAuthorization::factory()->create();

    Livewire::test(PairDevice::class)
        ->fillForm([
            'user_code' => $deviceAuth->user_code,
            'playlist_auth_ids' => [$own->id, $other->id],
        ], 'content')
        ->call('approve')
        ->assertNotified();

    expect($deviceAuth->refresh()->status)->toBe('pending');
});
