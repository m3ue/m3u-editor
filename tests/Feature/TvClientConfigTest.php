<?php

use App\Enums\DefaultAuthMode;
use App\Events\TvClientConfigUpdatedEvent;
use App\Filament\Clusters\Devices\DevicesCluster;
use App\Filament\Resources\TvClientProfiles\Pages\ListTvClientProfiles;
use App\Filament\Resources\TvClientProfiles\TvClientProfileResource;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\TvClientProfile;
use App\Models\TvDevice;
use App\Models\User;
use App\Services\TvClientConfigService;
use App\Settings\GeneralSettings;
use App\Support\TvClientSettings;
use Filament\Actions\CreateAction;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create(['name' => 'owner']);
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'default_auth_mode' => DefaultAuthMode::Uuid,
    ]);

    $this->auth = PlaylistAuth::factory()->for($this->user)->create([
        'enabled' => true,
        'username' => 'tv-config-user',
        'password' => 'tv-config-pass',
    ]);
    $this->auth->assignTo($this->playlist);
});

function clientConfigUrl(string $username, string $password, ?string $action = null): string
{
    return route('xtream.api.player').'?'.http_build_query(array_filter([
        'username' => $username,
        'password' => $password,
        'action' => $action,
    ]));
}

it('advertises client_config with an empty config when no profile applies', function () {
    $handshake = $this->getJson(clientConfigUrl('tv-config-user', 'tv-config-pass'))->assertOk();

    expect($handshake->json('m3u_editor.client_config'))->toMatchArray([
        'api_version' => TvClientSettings::API_VERSION,
        'action' => 'get_client_config',
    ]);

    $config = $this->getJson(clientConfigUrl('tv-config-user', 'tv-config-pass', 'get_client_config'))->assertOk();

    expect($config->json('profile'))->toBeNull()
        ->and($config->json('settings'))->toBe([])
        ->and($config->json('revision'))->toBe($handshake->json('m3u_editor.client_config.revision'));
});

it('serves the auth\'s own profile and a revision that matches the handshake', function () {
    $profile = TvClientProfile::factory()->for($this->user)->create([
        'name' => 'Kids',
        'settings' => [
            'default_start_page' => ['mode' => 'enforced', 'value' => 'movies'],
            'prebuffer_live' => ['mode' => 'default', 'value' => true],
        ],
    ]);
    $this->auth->update(['tv_client_profile_id' => $profile->id]);

    $handshake = $this->getJson(clientConfigUrl('tv-config-user', 'tv-config-pass'))->assertOk();
    $config = $this->getJson(clientConfigUrl('tv-config-user', 'tv-config-pass', 'get_client_config'))->assertOk();

    expect($config->json('profile'))->toBe('Kids')
        ->and($config->json('settings'))->toBe([
            'default_start_page' => ['mode' => 'enforced', 'value' => 'movies'],
            'prebuffer_live' => ['mode' => 'default', 'value' => true],
        ])
        ->and($config->json('revision'))->toBe($handshake->json('m3u_editor.client_config.revision'))
        ->and($config->json('revision'))->not->toBe(app(TvClientConfigService::class)->revision([]));
});

it('falls back to the owner\'s default profile for auths without one and for the owner login', function () {
    TvClientProfile::factory()->for($this->user)->asDefault()->create([
        'name' => 'House default',
        'settings' => ['home_screen_style' => ['mode' => 'default', 'value' => 'simplified']],
    ]);

    $this->getJson(clientConfigUrl('tv-config-user', 'tv-config-pass', 'get_client_config'))
        ->assertOk()
        ->assertJsonPath('profile', 'House default');

    $this->getJson(clientConfigUrl('owner', $this->playlist->uuid, 'get_client_config'))
        ->assertOk()
        ->assertJsonPath('profile', 'House default')
        ->assertJsonPath('settings.home_screen_style.value', 'simplified');
});

it('prefers the auth\'s own profile over the default', function () {
    TvClientProfile::factory()->for($this->user)->asDefault()->create(['name' => 'House default']);
    $own = TvClientProfile::factory()->for($this->user)->create(['name' => 'Bedroom']);
    $this->auth->update(['tv_client_profile_id' => $own->id]);

    $this->getJson(clientConfigUrl('tv-config-user', 'tv-config-pass', 'get_client_config'))
        ->assertOk()
        ->assertJsonPath('profile', 'Bedroom');
});

it('ignores another user\'s default profile', function () {
    TvClientProfile::factory()->asDefault()->create(['name' => 'Someone else']);

    $this->getJson(clientConfigUrl('tv-config-user', 'tv-config-pass', 'get_client_config'))
        ->assertOk()
        ->assertJsonPath('profile', null);
});

it('drops unknown keys, modes and values when saving', function () {
    $profile = TvClientProfile::factory()->for($this->user)->create([
        'settings' => [
            'not_a_setting' => ['mode' => 'enforced', 'value' => 'x'],
            'live_tv_layout' => ['mode' => 'sometimes', 'value' => 'grid'],
            'home_screen_style' => ['mode' => 'default', 'value' => 'fancy'],
            'epg_preview_delay_seconds' => ['mode' => 'default', 'value' => '8'],
            'epg_start_view' => ['mode' => 'default', 'value' => null],
            'deinterlace' => ['mode' => 'enforced', 'value' => '0'],
        ],
    ]);

    expect($profile->fresh()->settings)->toBe([
        'epg_preview_delay_seconds' => ['mode' => 'default', 'value' => 8],
        'deinterlace' => ['mode' => 'enforced', 'value' => false],
    ]);
});

it('keeps a single default profile per user', function () {
    $first = TvClientProfile::factory()->for($this->user)->asDefault()->create();
    $second = TvClientProfile::factory()->for($this->user)->asDefault()->create();
    $otherUsers = TvClientProfile::factory()->asDefault()->create();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and($otherUsers->fresh()->is_default)->toBeTrue();
});

it('notifies the auth\'s devices when its profile is edited or swapped', function () {
    Event::fake([TvClientConfigUpdatedEvent::class]);

    $profile = TvClientProfile::factory()->for($this->user)->create();
    TvDevice::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth->id,
    ]);
    TvDevice::factory()->revoked()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth->id,
    ]);

    $this->auth->update(['tv_client_profile_id' => $profile->id]);
    $profile->update(['settings' => ['deinterlace' => ['mode' => 'enforced', 'value' => true]]]);

    $expected = ["tv.{$this->playlist->getMorphClass()}.{$this->playlist->uuid}.{$this->auth->id}"];

    Event::assertDispatchedTimes(TvClientConfigUpdatedEvent::class, 2);
    Event::assertDispatched(TvClientConfigUpdatedEvent::class, fn (TvClientConfigUpdatedEvent $event): bool => $event->channelNames === $expected);
});

it('notifies owner-login devices and auths without a profile when the default changes', function () {
    Event::fake([TvClientConfigUpdatedEvent::class]);

    $ownerDevice = TvDevice::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => null,
    ]);
    $assigned = TvClientProfile::factory()->for($this->user)->create();
    $otherAuth = PlaylistAuth::factory()->for($this->user)->create(['tv_client_profile_id' => $assigned->id]);
    TvDevice::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $otherAuth->id,
    ]);
    TvDevice::factory()->create([
        'notifiable_type' => $this->playlist->getMorphClass(),
        'notifiable_id' => $this->playlist->id,
        'playlist_auth_id' => $this->auth->id,
    ]);

    TvClientProfile::factory()->for($this->user)->asDefault()->create();

    $type = $this->playlist->getMorphClass();
    $uuid = $this->playlist->uuid;

    Event::assertDispatched(TvClientConfigUpdatedEvent::class, function (TvClientConfigUpdatedEvent $event) use ($type, $uuid): bool {
        $channels = $event->channelNames;
        sort($channels);

        return $channels === [
            "tv.{$type}-admin.{$uuid}",
            "tv.{$type}.{$uuid}",
            "tv.{$type}.{$uuid}.{$this->auth->id}",
        ];
    });
});

it('creates a profile from the panel with managed settings', function () {
    $this->user->forceFill(['is_admin' => true])->save();
    $this->actingAs($this->user);

    Livewire::test(ListTvClientProfiles::class)
        ->callAction(CreateAction::class, [
            'name' => 'Living Room',
            'is_default' => true,
            'settings' => [
                'live_tv_layout' => ['mode' => 'enforced', 'value' => 'grid'],
            ],
        ])
        ->assertHasNoFormErrors();

    $profile = TvClientProfile::firstWhere('name', 'Living Room');

    expect($profile->user_id)->toBe($this->user->id)
        ->and($profile->is_default)->toBeTrue()
        ->and($profile->settings)->toBe(['live_tv_layout' => ['mode' => 'enforced', 'value' => 'grid']]);
});

it('lists Settings Profiles in the Devices cluster while enhanced output is on', function () {
    $this->actingAs(User::factory()->admin()->create());

    $settings = Mockery::mock(GeneralSettings::class);
    $settings->push_relay_enabled = false;
    $settings->device_pairing_enabled = false;
    $settings->app_output_enabled = true;
    app()->instance(GeneralSettings::class, $settings);

    expect(DevicesCluster::getClusteredComponents())->toContain(TvClientProfileResource::class)
        ->and(TvClientProfileResource::canAccess())->toBeTrue()
        ->and(DevicesCluster::canAccess())->toBeTrue()
        ->and(DevicesCluster::shouldRegisterNavigation())->toBeTrue();

    $settings->app_output_enabled = false;

    expect(TvClientProfileResource::canAccess())->toBeFalse()
        ->and(DevicesCluster::canAccess())->toBeFalse()
        ->and(DevicesCluster::shouldRegisterNavigation())->toBeFalse();
});

it('keeps Settings Profiles admin-only, like the rest of the Devices cluster', function () {
    $this->actingAs($this->user);

    expect(TvClientProfileResource::canAccess())->toBeFalse();
});
