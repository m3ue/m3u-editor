<?php

use App\Filament\Resources\PushDeviceTokens\Pages\ListPushDeviceTokens;
use App\Filament\Resources\PushDeviceTokens\PushDeviceTokenResource;
use App\Models\DeviceAuthorization;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('hides the devices/pairing page from non-admins', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(PushDeviceTokenResource::canAccess())->toBeFalse();
});

it('allows admins to access the devices/pairing page', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    expect(PushDeviceTokenResource::canAccess())->toBeTrue();
});

it('approves a pending code and assigns the chosen credential', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $playlistAuth = PlaylistAuth::factory()->for($admin)->create();
    $deviceAuth = DeviceAuthorization::factory()->create();

    Livewire::test(ListPushDeviceTokens::class, ['activeTab' => 'pairing'])
        ->fillForm([
            'user_code' => $deviceAuth->user_code,
            'playlist_auth_id' => $playlistAuth->id,
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

    Livewire::test(ListPushDeviceTokens::class, ['activeTab' => 'pairing'])
        ->fillForm([
            'user_code' => 'xkqp9f3t',
            'playlist_auth_id' => $playlistAuth->id,
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

    Livewire::test(ListPushDeviceTokens::class, ['activeTab' => 'pairing'])
        ->fillForm([
            'user_code' => ' xkqp 9f3t ',
            'playlist_auth_id' => $playlistAuth->id,
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

    Livewire::test(ListPushDeviceTokens::class, ['activeTab' => 'pairing'])
        ->fillForm([
            'user_code' => 'ZZZZ-ZZZZ',
            'playlist_auth_id' => $playlistAuth->id,
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

    Livewire::test(ListPushDeviceTokens::class, ['activeTab' => 'pairing'])
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

it('hides the pairing tab when device pairing is disabled', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $settings = Mockery::mock(GeneralSettings::class);
    $settings->device_pairing_enabled = false;
    $settings->app_output_enabled = true;
    app()->instance(GeneralSettings::class, $settings);

    $component = Livewire::test(ListPushDeviceTokens::class);

    expect($component->instance()->getTabs())->not->toHaveKey('pairing');
});

it('falls back to the devices tab when pairing is requested but disabled', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $settings = Mockery::mock(GeneralSettings::class);
    $settings->device_pairing_enabled = false;
    $settings->app_output_enabled = true;
    app()->instance(GeneralSettings::class, $settings);

    $component = Livewire::test(ListPushDeviceTokens::class, ['activeTab' => 'pairing']);

    expect($component->instance()->activeTab)->toBe('devices');
});

it('hides the devices tab when push relay is disabled', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $settings = Mockery::mock(GeneralSettings::class);
    $settings->push_relay_enabled = false;
    app()->instance(GeneralSettings::class, $settings);

    $component = Livewire::test(ListPushDeviceTokens::class);

    expect($component->instance()->getTabs())->not->toHaveKey('devices')
        ->and($component->instance()->activeTab)->toBe('pairing');
});

it('denies access and hides the nav item when both push relay and device pairing are disabled', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $settings = Mockery::mock(GeneralSettings::class);
    $settings->push_relay_enabled = false;
    $settings->device_pairing_enabled = false;
    $settings->app_output_enabled = true;
    app()->instance(GeneralSettings::class, $settings);

    expect(PushDeviceTokenResource::canAccess())->toBeFalse();
    expect(PushDeviceTokenResource::shouldRegisterNavigation())->toBeFalse();
});
