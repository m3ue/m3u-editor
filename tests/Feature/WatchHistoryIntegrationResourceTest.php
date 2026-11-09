<?php

use App\Enums\WatchHistoryProvider;
use App\Filament\Clusters\MediaServers\MediaServersCluster;
use App\Filament\Resources\WatchHistoryIntegrations\Pages\ListWatchHistoryIntegrations;
use App\Filament\Resources\WatchHistoryIntegrations\WatchHistoryIntegrationResource;
use App\Jobs\SyncWatchHistoryIntegration;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Models\WatchHistoryIntegration;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);
});

it('requires the integrations permission', function () {
    $this->actingAs(User::factory()->create());

    expect(WatchHistoryIntegrationResource::canAccess())->toBeFalse();
});

it('scopes the table and global search to the current user', function () {
    WatchHistoryIntegration::factory()->for($this->user)->create(['name' => 'Mine']);
    WatchHistoryIntegration::factory()->create(['name' => 'Theirs']);

    expect(WatchHistoryIntegrationResource::getEloquentQuery()->pluck('name')->all())->toBe(['Mine'])
        ->and(WatchHistoryIntegrationResource::getGlobalSearchEloquentQuery()->pluck('name')->all())->toBe(['Mine']);
});

it('creates an account attached to the user\'s playlist auths and opens the sign-in', function () {
    Http::fake(['api.trakt.tv/oauth/device/code' => Http::response([
        'device_code' => 'device-123',
        'user_code' => 'ABCD1234',
        'verification_url' => 'https://trakt.tv/activate',
        'expires_in' => 600,
        'interval' => 5,
    ])]);
    $playlistAuth = PlaylistAuth::factory()->for($this->user)->create();

    Livewire::test(ListWatchHistoryIntegrations::class)
        ->callAction(TestAction::make('create')->table(), data: [
            'provider' => 'trakt',
            'name' => 'Trakt - Den',
            'playlistAuths' => [$playlistAuth->id],
            'enabled' => true,
            'push_progress' => true,
        ])
        ->assertHasNoFormErrors()
        ->assertActionMounted(TestAction::make('connect')->table(WatchHistoryIntegration::sole()))
        ->assertSchemaStateSet(['user_code' => 'ABCD1234']);

    $integration = WatchHistoryIntegration::sole();
    expect($integration)
        ->user_id->toBe($this->user->id)
        ->provider->toBe(WatchHistoryProvider::Trakt)
        ->isConnected()->toBeFalse()
        ->and($integration->playlistAuths->modelKeys())->toBe([$playlistAuth->id]);
});

it('connects once the code is approved and keeps the modal open until then', function () {
    $integration = WatchHistoryIntegration::factory()->disconnected()->for($this->user)->create();
    Http::fakeSequence('api.trakt.tv/oauth/device/token')
        ->push(['error' => 'authorization_pending'], 400)
        ->push(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 86400]);
    Http::fake([
        'api.trakt.tv/oauth/device/code' => Http::response([
            'device_code' => 'device-123',
            'user_code' => 'ABCD1234',
            'verification_url' => 'https://trakt.tv/activate',
        ]),
        'api.trakt.tv/users/settings' => Http::response(['user' => ['username' => 'couchpotato']]),
    ]);

    $component = Livewire::test(ListWatchHistoryIntegrations::class)
        ->mountAction(TestAction::make('connect')->table($integration))
        ->callMountedAction()
        ->assertActionMounted(TestAction::make('connect')->table($integration));

    expect($integration->fresh()->isConnected())->toBeFalse();

    $component->callMountedAction()->assertHasNoActionErrors();

    expect($integration->fresh())
        ->access_token->toBe('access-1')
        ->account_name->toBe('couchpotato');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/oauth/device/token')
        && $request['code'] === 'device-123'
        && filled($request['code_verifier']));
});

it('starts an import for an attached playlist auth', function () {
    Bus::fake();
    $integration = WatchHistoryIntegration::factory()->for($this->user)->create();
    $playlistAuth = PlaylistAuth::factory()->for($this->user)->create();
    $integration->playlistAuths()->attach($playlistAuth);

    Livewire::test(ListWatchHistoryIntegrations::class)
        ->callAction(TestAction::make('sync')->table($integration), data: ['mode' => 'replace'])
        ->assertHasNoFormErrors();

    Bus::assertDispatched(SyncWatchHistoryIntegration::class, fn (SyncWatchHistoryIntegration $job): bool => $job->integrationId === $integration->id
        && $job->playlistAuthId === $playlistAuth->id
        && $job->mode === 'replace');
});

it('disconnects and revokes the tokens', function () {
    Http::fake(['api.simkl.com/oauth2/revoke*' => Http::response([])]);
    $integration = WatchHistoryIntegration::factory()->simkl()->for($this->user)->create();

    Livewire::test(ListWatchHistoryIntegrations::class)
        ->callAction(TestAction::make('disconnect')->table($integration));

    expect($integration->fresh())
        ->isConnected()->toBeFalse()
        ->account_name->toBeNull();
    Http::assertSentCount(1);
});

it('is a top-level Integrations page, not a Media Servers tab', function () {
    expect(WatchHistoryIntegrationResource::getNavigationGroup())->toBe(__('Integrations'))
        ->and(WatchHistoryIntegrationResource::getCluster())->toBeNull()
        ->and(MediaServersCluster::getClusteredComponents())->not->toContain(WatchHistoryIntegrationResource::class);
});

it('tests a connection that still works and refreshes the account name', function () {
    Http::fake(['api.simkl.com/users/settings*' => Http::response(['user' => ['name' => 'jane_doe']])]);
    $integration = WatchHistoryIntegration::factory()->simkl()->for($this->user)->create([
        'account_name' => 'old_name',
        'last_error' => 'Something failed earlier',
    ]);

    Livewire::test(ListWatchHistoryIntegrations::class)
        ->callAction(TestAction::make('testConnection')->table($integration))
        ->assertNotified(__('Connection Successful'));

    expect($integration->fresh())
        ->account_name->toBe('jane_doe')
        ->last_error->toBeNull()
        ->isConnected()->toBeTrue();
});

it('marks a sign-in the service rejected as not connected', function () {
    Http::fake([
        'api.simkl.com/users/settings*' => Http::response(['error' => 'unauthorized'], 401),
        'api.simkl.com/oauth2/token*' => Http::response(['error' => 'invalid_grant'], 400),
    ]);
    $integration = WatchHistoryIntegration::factory()->simkl()->for($this->user)->create();

    Livewire::test(ListWatchHistoryIntegrations::class)
        ->callAction(TestAction::make('testConnection')->table($integration))
        ->assertNotified(__(':provider sign-in is no longer valid', ['provider' => 'SIMKL']));

    expect($integration->fresh())
        ->isConnected()->toBeFalse()
        ->last_error->not->toBeNull();
});
