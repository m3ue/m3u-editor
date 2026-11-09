<?php

/**
 * Emby/Jellyfin integrations can sign in with a regular (non-admin) server
 * user's username and password instead of an admin API key (issue #1823).
 * The password is exchanged once for a session token and never stored;
 * library and item queries are scoped to that user, and admin-only calls are
 * skipped. API-key integrations must keep their existing behavior.
 */

use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use App\Filament\Resources\MediaServerIntegrations\Pages\EditMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\EmbyLibraryMappingsRelationManager;
use App\Models\MediaServerIntegration;
use App\Models\User;
use App\Services\EmbyJellyfinService;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Bus::fake();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);
});

function userLoginIntegration(array $attributes = []): MediaServerIntegration
{
    return MediaServerIntegration::factory()->create(array_merge([
        'user_id' => test()->user->id,
        'type' => 'jellyfin',
        'host' => 'media.local',
        'port' => 8096,
        'ssl' => false,
        'api_key' => 'session-token',
        'auth_method' => 'user',
        'server_username' => 'viewer',
        'server_user_id' => 'user-123',
        'enabled' => true,
    ], $attributes));
}

function authenticateByNameResponse(): array
{
    return [
        'AccessToken' => 'new-session-token',
        'User' => ['Id' => 'user-123', 'Name' => 'viewer', 'Policy' => ['IsAdministrator' => false]],
    ];
}

it('exchanges a Jellyfin username and password for a session token through the standard Authorization header', function () {
    Http::fake([
        'http://media.local:8096/Users/AuthenticateByName' => Http::response(authenticateByNameResponse()),
    ]);

    $connection = (new MediaServerIntegration)->forceFill(['type' => 'jellyfin', 'host' => 'media.local', 'port' => 8096, 'ssl' => false]);
    $result = EmbyJellyfinService::authenticateByName($connection, 'viewer', 'secret');

    expect($result)
        ->success->toBeTrue()
        ->access_token->toBe('new-session-token')
        ->user_id->toBe('user-123')
        ->is_admin->toBeFalse();

    Http::assertSent(function (ClientRequest $request) {
        $authorization = $request->header('Authorization')[0] ?? '';

        return $request->method() === 'POST'
            && $request['Username'] === 'viewer'
            && $request['Pw'] === 'secret'
            && str_starts_with($authorization, 'MediaBrowser Client="m3u editor"')
            && str_contains($authorization, 'DeviceId="'.EmbyJellyfinService::userLoginDeviceId('http://media.local:8096', 'viewer').'"')
            && ! str_contains($authorization, 'Token=');
    });
});

it('signs in to Emby with the X-Emby-Authorization header', function () {
    Http::fake([
        'http://media.local:8096/Users/AuthenticateByName' => Http::response(authenticateByNameResponse()),
    ]);

    $connection = (new MediaServerIntegration)->forceFill(['type' => 'emby', 'host' => 'media.local', 'port' => 8096, 'ssl' => false]);

    expect(EmbyJellyfinService::authenticateByName($connection, 'viewer', 'secret'))->success->toBeTrue();

    Http::assertSent(fn (ClientRequest $request) => $request->hasHeader('X-Emby-Authorization')
        && ! $request->hasHeader('Authorization'));
});

it('reports rejected credentials without a token', function () {
    Http::fake([
        'http://media.local:8096/Users/AuthenticateByName' => Http::response('', 401),
    ]);

    $connection = (new MediaServerIntegration)->forceFill(['type' => 'jellyfin', 'host' => 'media.local', 'port' => 8096, 'ssl' => false]);
    $result = EmbyJellyfinService::authenticateByName($connection, 'viewer', 'wrong');

    expect($result)
        ->success->toBeFalse()
        ->message->toBe('Invalid username or password.')
        ->not->toHaveKey('access_token');
});

it('lists a user-login integration\'s libraries from the user views instead of the admin-only virtual folders', function () {
    Http::fake([
        'http://media.local:8096/Users/user-123/Views' => Http::response(['Items' => [
            ['Id' => 'lib-movies', 'Name' => 'Movies', 'CollectionType' => 'movies'],
            ['Id' => 'lib-shows', 'Name' => 'Shows', 'CollectionType' => 'tvshows'],
            ['Id' => 'lib-home', 'Name' => 'Home Videos', 'CollectionType' => 'homevideos'],
            ['Id' => 'pl', 'Name' => 'Playlists', 'CollectionType' => 'playlists'],
            ['Id' => 'live', 'Name' => 'Live TV', 'CollectionType' => 'livetv'],
        ]]),
    ]);

    $libraries = EmbyJellyfinService::make(userLoginIntegration())->fetchLibraries();

    expect($libraries->pluck('type', 'id')->all())->toBe([
        'lib-movies' => 'movies',
        'lib-shows' => 'tvshows',
        'lib-home' => 'mixed',
    ]);

    Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), '/Library/VirtualFolders'));
});

it('scopes item queries to the signed-in user only for user-login integrations', function () {
    Http::fake([
        'http://media.local:8096/Items*' => Http::response(['Items' => [['Id' => 'movie-1', 'Name' => 'Movie']]]),
    ]);

    EmbyJellyfinService::make(userLoginIntegration())->fetchMovies();

    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/Items')
        && ($request['UserId'] ?? null) === 'user-123');

    Http::fake([
        'http://admin.local:8096/Items*' => Http::response(['Items' => []]),
    ]);

    $apiKeyIntegration = userLoginIntegration([
        'host' => 'admin.local',
        'auth_method' => 'api_key',
        'server_username' => null,
        'server_user_id' => null,
    ]);

    EmbyJellyfinService::make($apiKeyIntegration)->fetchMovies();

    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), 'admin.local')
        && ! isset($request['UserId']));
});

it('skips the admin-only library refresh for user-login integrations', function () {
    $result = EmbyJellyfinService::make(userLoginIntegration())->refreshLibrary();

    expect($result['success'])->toBeFalse();

    Http::assertNothingSent();
});

it('stores the session token and never the password when saving a user login', function () {
    Http::fake([
        'http://media.local:8096/Users/AuthenticateByName' => Http::response(authenticateByNameResponse()),
    ]);

    $data = MediaServerIntegrationResource::applyAuthMethod([
        'type' => 'jellyfin',
        'host' => 'media.local',
        'port' => 8096,
        'ssl' => false,
        'auth_method' => 'user',
        'server_username' => 'viewer',
        'server_password' => 'secret',
    ]);

    expect($data)
        ->not->toHaveKey('server_password')
        ->and($data['api_key'])->toBe('new-session-token')
        ->and($data['server_user_id'])->toBe('user-123')
        ->and($data['auth_method'])->toBe('user');
});

it('halts the save when the server rejects the login', function () {
    Http::fake([
        'http://media.local:8096/Users/AuthenticateByName' => Http::response('', 401),
    ]);

    MediaServerIntegrationResource::applyAuthMethod([
        'type' => 'emby',
        'host' => 'media.local',
        'port' => 8096,
        'ssl' => false,
        'auth_method' => 'user',
        'server_username' => 'viewer',
        'server_password' => 'wrong',
    ]);
})->throws(Halt::class);

it('clears user-login fields when an integration uses an API key', function () {
    $data = MediaServerIntegrationResource::applyAuthMethod([
        'type' => 'emby',
        'api_key' => 'admin-key',
        'auth_method' => 'api_key',
        'server_username' => 'leftover',
    ]);

    expect($data)
        ->auth_method->toBe('api_key')
        ->server_username->toBeNull()
        ->server_user_id->toBeNull()
        ->api_key->toBe('admin-key');

    Http::assertNothingSent();
});

it('keeps the existing session when a user-login integration is edited without a new password', function () {
    $integration = userLoginIntegration([
        'name' => 'Shared Jellyfin',
        'available_libraries' => [['id' => 'lib-movies', 'name' => 'Movies', 'type' => 'movies', 'item_count' => 0, 'paths' => [], 'path' => '']],
        'selected_library_ids' => ['lib-movies'],
    ]);

    Livewire::test(EditMediaServerIntegration::class, ['record' => $integration->id])
        ->fillForm(['name' => 'Renamed Jellyfin'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($integration->refresh())
        ->name->toBe('Renamed Jellyfin')
        ->api_key->toBe('session-token')
        ->server_user_id->toBe('user-123');

    Http::assertNothingSent();
});

it('requires a password again when the username changes', function () {
    $integration = userLoginIntegration();

    Livewire::test(EditMediaServerIntegration::class, ['record' => $integration->id])
        ->fillForm(['server_username' => 'someone-else'])
        ->call('save')
        ->assertHasFormErrors(['server_password' => 'required']);
});

it('hides Emby Managed Libraries for user-login integrations', function () {
    $apiKeyIntegration = userLoginIntegration(['type' => 'emby', 'auth_method' => 'api_key']);
    $userIntegration = userLoginIntegration(['type' => 'emby']);

    expect(EmbyLibraryMappingsRelationManager::canViewForRecord($apiKeyIntegration, EditMediaServerIntegration::class))->toBeTrue()
        ->and(EmbyLibraryMappingsRelationManager::canViewForRecord($userIntegration, EditMediaServerIntegration::class))->toBeFalse();
});
