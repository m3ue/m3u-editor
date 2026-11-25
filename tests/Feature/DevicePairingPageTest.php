<?php

use App\Filament\Clusters\Devices\DevicesCluster;
use App\Filament\Clusters\Devices\Pages\PairDevice;
use App\Filament\Resources\TvClientProfiles\TvClientProfileResource;
use App\Filament\Resources\TvDevices\TvDeviceResource;
use App\Filament\Resources\TvHomeScreens\TvHomeScreenResource;
use App\Models\DeviceAuthorization;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Repeater rows in the raw shape the browser produces: one keyed item per
 * row, each holding the simple field's value under its name.
 *
 * @return array<string, array{playlist_auth_id: string}>
 */
function credentialRows(int ...$ids): array
{
    return collect($ids)
        ->mapWithKeys(fn (int $id, int $index): array => ["row-{$index}" => ['playlist_auth_id' => (string) $id]])
        ->all();
}

it('gives non-admins the device pairing page but not the rest of the devices cluster', function () {
    $this->actingAs(User::factory()->create());

    expect(PairDevice::canAccess())->toBeTrue()
        ->and(TvDeviceResource::canAccess())->toBeFalse()
        ->and(TvClientProfileResource::canAccess())->toBeFalse()
        ->and(TvHomeScreenResource::canAccess())->toBeFalse()
        ->and(DevicesCluster::canAccess())->toBeFalse();
});

it('requires signing in to reach the device pairing page', function () {
    expect(PairDevice::canAccess())->toBeFalse();

    $this->get(PairDevice::getUrl())->assertRedirect();
});

it('opens the pairing page for a non-admin following the QR code', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/pdt?code=XKQP-9F3T')
        ->assertRedirect(PairDevice::getUrl(['code' => 'XKQP-9F3T']));

    $this->get(PairDevice::getUrl(['code' => 'XKQP-9F3T']))
        ->assertOk()
        ->assertSee('Device Pairing')
        ->assertDontSee('href="'.DevicesCluster::getUrl().'"', false);
});

it('keeps the devices sub-navigation on the pairing page for admins only', function () {
    $this->actingAs(User::factory()->admin()->create());
    expect(Livewire::test(PairDevice::class)->instance()->getSubNavigation())->not->toBeEmpty();

    $this->actingAs(User::factory()->create());
    expect(Livewire::test(PairDevice::class)->instance()->getSubNavigation())->toBeEmpty();
});

it('lets a non-admin pair a device with their own credential', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $playlistAuth = PlaylistAuth::factory()->for($user)->create();
    $deviceAuth = DeviceAuthorization::factory()->create();

    Livewire::test(PairDevice::class)
        ->set('data.user_code', $deviceAuth->user_code)
        ->set('data.playlist_auth_ids', credentialRows($playlistAuth->id))
        ->call('approve')
        ->assertHasNoFormErrors();

    $deviceAuth->refresh();
    expect($deviceAuth->status)->toBe('approved')
        ->and($deviceAuth->playlist_auth_id)->toBe($playlistAuth->id)
        ->and($deviceAuth->approved_by_user_id)->toBe($user->id);
});

it('refuses a non-admin pairing with another user\'s credential', function () {
    $this->actingAs(User::factory()->create());

    $other = PlaylistAuth::factory()->for(User::factory()->admin()->create())->create();
    $deviceAuth = DeviceAuthorization::factory()->create();

    Livewire::test(PairDevice::class)
        ->set('data.user_code', $deviceAuth->user_code)
        ->set('data.playlist_auth_ids', credentialRows($other->id))
        ->call('approve')
        ->assertHasFormErrors();

    expect($deviceAuth->refresh()->status)->toBe('pending');
});

it('hides the pairing page from non-admins when device pairing is disabled', function () {
    $this->actingAs(User::factory()->create());

    $settings = Mockery::mock(GeneralSettings::class);
    $settings->device_pairing_enabled = false;
    $settings->app_output_enabled = true;
    app()->instance(GeneralSettings::class, $settings);

    expect(PairDevice::canAccess())->toBeFalse();
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
        ], 'content')
        ->set('data.playlist_auth_ids', credentialRows($playlistAuth->id))
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
        ], 'content')
        ->set('data.playlist_auth_ids', credentialRows($playlistAuth->id))
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
        ], 'content')
        ->set('data.playlist_auth_ids', credentialRows($playlistAuth->id))
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
        ], 'content')
        ->set('data.playlist_auth_ids', credentialRows($playlistAuth->id))
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
        ], 'content')
        ->set('data.playlist_auth_ids', credentialRows($kids->id, $living->id, $sports->id))
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
        ], 'content')
        ->set('data.playlist_auth_ids', credentialRows($own->id, $other->id))
        ->call('approve')
        ->assertHasFormErrors();

    expect($deviceAuth->refresh()->status)->toBe('pending');
});

it('approves credentials picked through the repeater rows the way the browser sets them', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    // Occupy id 1 with someone else's credential: the raw repeater item used
    // to be cast straight to int, which always produced 1.
    PlaylistAuth::factory()->for(User::factory()->create())->create();
    $living = PlaylistAuth::factory()->for($admin)->create();
    $kids = PlaylistAuth::factory()->for($admin)->create();
    $deviceAuth = DeviceAuthorization::factory()->create(['user_code' => 'XKQP-9F3T']);

    $page = Livewire::test(PairDevice::class);
    $firstRow = array_key_first($page->get('data.playlist_auth_ids'));

    $page->set('data.user_code', 'XKQP9F3T')
        ->set("data.playlist_auth_ids.{$firstRow}.playlist_auth_id", (string) $kids->id)
        ->set('data.playlist_auth_ids.second-row', ['playlist_auth_id' => (string) $living->id])
        ->call('approve')
        ->assertHasNoFormErrors();

    $deviceAuth->refresh();
    expect($deviceAuth->status)->toBe('approved')
        ->and($deviceAuth->playlist_auth_id)->toBe($kids->id)
        ->and($deviceAuth->playlist_auth_ids)->toBe([$kids->id, $living->id]);
});

it('starts and resets the credential rows in the raw shape the select binds to', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $playlistAuth = PlaylistAuth::factory()->for($admin)->create();
    DeviceAuthorization::factory()->create(['user_code' => 'XKQP-9F3T']);

    $page = Livewire::test(PairDevice::class);

    expect(array_values($page->get('data.playlist_auth_ids')))->toBe([['playlist_auth_id' => null]]);

    $page->set('data.user_code', 'XKQP9F3T')
        ->set('data.playlist_auth_ids', credentialRows($playlistAuth->id))
        ->call('approve')
        ->assertHasNoFormErrors();

    expect(array_values($page->get('data.playlist_auth_ids')))->toBe([['playlist_auth_id' => null]]);
});

/**
 * The whole chain a TV goes through, with nothing written to the database by
 * the test itself: the TV's code request, the page filled the way the browser
 * does (through the row keys the page rendered), and the TV's poll.
 */
it('hands the TV exactly the credentials picked on the page, in order', function (bool $isAdmin) {
    $user = $isAdmin ? User::factory()->admin()->create() : User::factory()->create();

    // Occupy the lowest ids: casting a raw repeater row to int always gave 1.
    PlaylistAuth::factory()->count(2)->for(User::factory()->create())->create();
    $living = PlaylistAuth::factory()->for($user)->create(['name' => 'Living Room', 'username' => 'living', 'password' => 'l-pass']);
    $kids = PlaylistAuth::factory()->for($user)->create(['name' => 'Kids', 'username' => 'kids', 'password' => 'k-pass']);

    $this->actingAs($user);
    $page = Livewire::withQueryParams([])->test(PairDevice::class);

    $pair = function (PlaylistAuth ...$picked) use ($page): array {
        $code = $this->postJson('/api/device/code')->assertOk()->json();

        $page->set('data.user_code', strtolower(str_replace('-', '', $code['user_code'])));

        $rowKeys = array_keys($page->get('data.playlist_auth_ids'));
        expect($rowKeys)->toHaveCount(1);

        foreach ($picked as $index => $playlistAuth) {
            $key = $rowKeys[$index] ?? (string) Str::uuid();
            $page->set("data.playlist_auth_ids.{$key}.playlist_auth_id", (string) $playlistAuth->id);
        }

        $page->call('approve')->assertHasNoFormErrors();

        return $this->postJson('/api/device/token', ['device_code' => $code['device_code']])->assertOk()->json();
    };

    $kidsEntry = ['name' => 'Kids', 'username' => 'kids', 'password' => 'k-pass'];
    $livingEntry = ['name' => 'Living Room', 'username' => 'living', 'password' => 'l-pass'];

    expect($pair($kids, $living))->toBe([
        'status' => 'approved',
        'username' => 'kids',
        'password' => 'k-pass',
        'playlists' => [$kidsEntry, $livingEntry],
    ]);

    // Pairing a second TV from the same page, without reloading it.
    expect($pair($living))->toBe([
        'status' => 'approved',
        'username' => 'living',
        'password' => 'l-pass',
        'playlists' => [$livingEntry],
    ]);
})->with(['admin' => true, 'non-admin' => false]);
