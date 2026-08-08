<?php

use App\Interfaces\MediaServer;
use App\Models\EmbyLibraryMapping;
use App\Models\MediaServerIntegration;
use App\Models\User;
use App\Services\MediaServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('defines managed library creation on the media server contract', function () {
    expect(method_exists(MediaServer::class, 'createLibrary'))->toBeTrue();
});

beforeEach(function () {
    $user = User::factory()->create();
    $this->integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
    ]);
});

it('creates an Emby library through the official virtual folders endpoint', function () {
    Http::preventStrayRequests();
    Http::fakeSequence('https://emby.test:8096/Library/VirtualFolders')
        ->push([], 200)
        ->push([], 204)
        ->push([[
            'ItemId' => 'library-1',
            'Name' => 'Managed Movies',
            'CollectionType' => 'movies',
            'Locations' => ['/srv/emby/managed/movies'],
        ]], 200);

    $result = MediaServerService::make($this->integration)->createLibrary(
        name: 'Managed Movies',
        collectionType: 'movies',
        paths: ['/srv/emby/managed/movies'],
        refreshLibrary: false,
    );

    expect($result['success'])->toBeTrue()
        ->and($result['created'])->toBeTrue()
        ->and($result['library']['id'])->toBe('library-1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://emby.test:8096/Library/VirtualFolders'
        && $request->hasHeader('X-Emby-Token', 'emby-secret')
        && $request->data() === [
            'Name' => 'Managed Movies',
            'CollectionType' => 'movies',
            'Paths' => ['/srv/emby/managed/movies'],
            'RefreshLibrary' => false,
        ]);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

it('rejects unsupported Emby library collection types before making a request', function () {
    Http::preventStrayRequests();
    Http::fake();

    $result = MediaServerService::make($this->integration)->createLibrary(
        name: 'Managed Music',
        collectionType: 'music',
        paths: ['/srv/emby/managed/music'],
    );

    expect($result)->toMatchArray([
        'success' => false,
        'created' => false,
    ]);
    Http::assertNothingSent();
});

it('rejects non-absolute Emby library paths before making a request', function () {
    Http::preventStrayRequests();
    Http::fake();

    $result = MediaServerService::make($this->integration)->createLibrary(
        name: 'Managed Movies',
        collectionType: 'movies',
        paths: ['relative/movies'],
    );

    expect($result['success'])->toBeFalse();
    Http::assertNothingSent();
});

it('does not create libraries for Jellyfin integrations', function () {
    Http::preventStrayRequests();
    Http::fake();
    $this->integration->update(['type' => 'jellyfin']);

    $result = MediaServerService::make($this->integration)->createLibrary(
        name: 'Managed Movies',
        collectionType: 'movies',
        paths: ['/srv/jellyfin/managed/movies'],
    );

    expect($result['success'])->toBeFalse();
    Http::assertNothingSent();
});

it('reconciles an existing Emby library by stable ID without creating a duplicate', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/Library/VirtualFolders' => Http::response([[
            'ItemId' => 'library-1',
            'Name' => 'Renamed Managed Movies',
            'CollectionType' => 'movies',
            'Locations' => ['/srv/emby/moved/movies'],
        ]], 200),
    ]);

    $result = MediaServerService::make($this->integration)->createLibrary(
        name: 'Managed Movies',
        collectionType: 'movies',
        paths: ['/srv/emby/managed/movies'],
        libraryId: 'library-1',
    );

    expect($result['success'])->toBeTrue()
        ->and($result['created'])->toBeFalse()
        ->and($result['library']['id'])->toBe('library-1')
        ->and($result['drift'])->toBeTrue();
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        || $request->method() === 'DELETE');
});

it('reconciles an existing Emby library by exact managed name and path', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/Library/VirtualFolders' => Http::response([[
            'ItemId' => 'library-2',
            'Name' => 'Managed TV',
            'CollectionType' => 'tvshows',
            'Locations' => ['/srv/emby/managed/tv'],
        ]], 200),
    ]);

    $result = MediaServerService::make($this->integration)->createLibrary(
        name: 'Managed TV',
        collectionType: 'tvshows',
        paths: ['/srv/emby/managed/tv'],
        libraryId: 'missing-library',
    );

    expect($result['success'])->toBeTrue()
        ->and($result['created'])->toBeFalse()
        ->and($result['library']['id'])->toBe('library-2');
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        || $request->method() === 'DELETE');
});

it('fails closed when an Emby library name exists at a different path', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/Library/VirtualFolders' => Http::response([[
            'ItemId' => 'library-3',
            'Name' => 'Managed Movies',
            'CollectionType' => 'movies',
            'Locations' => ['/srv/emby/unmanaged/movies'],
        ]], 200),
    ]);

    $result = MediaServerService::make($this->integration)->createLibrary(
        name: 'Managed Movies',
        collectionType: 'movies',
        paths: ['/srv/emby/managed/movies'],
    );

    expect($result['success'])->toBeFalse()
        ->and($result['created'])->toBeFalse()
        ->and($result['drift'])->toBeTrue();
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        || $request->method() === 'DELETE');
});

it('normalizes Emby errors without exposing response details', function () {
    Http::preventStrayRequests();
    Http::fakeSequence('https://emby.test:8096/Library/VirtualFolders')
        ->push([], 200)
        ->push('upstream secret: emby-secret', 500)
        ->push('upstream secret: emby-secret', 500);

    $result = MediaServerService::make($this->integration)->createLibrary(
        name: 'Managed Movies',
        collectionType: 'movies',
        paths: ['/srv/emby/managed/movies'],
    );

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->not->toContain('emby-secret')
        ->and($result['message'])->not->toContain('upstream secret');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

it('fails closed for movie imports while a created managed library is unresolved', function () {
    EmbyLibraryMapping::factory()
        ->for($this->integration->user)
        ->for($this->integration, 'integration')
        ->create([
            'collection_type' => 'movies',
            'target_library_id' => null,
            'target_library_name' => 'Managed Movies',
            'output_path' => '/srv/emby/managed/movies',
            'is_managed' => true,
            'enabled' => true,
        ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/Library/VirtualFolders' => Http::sequence()
            ->push([], 200)
            ->push([], 204)
            ->push([], 200),
        'https://emby.test:8096/Items*' => Http::response(['Items' => []], 200),
    ]);

    $service = MediaServerService::make($this->integration);
    $result = $service->createLibrary(
        name: 'Managed Movies',
        collectionType: 'movies',
        paths: ['/srv/emby/managed/movies'],
        refreshLibrary: false,
    );

    expect($result['success'])->toBeTrue()
        ->and($result['created'])->toBeTrue()
        ->and($result['library'])->toBeNull()
        ->and($service->fetchMovies())->toBeEmpty();
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://emby.test:8096/Items?')
        && ($request->data()['ParentId'] ?? null) === null);
});

it('fails closed for series imports while a managed library is unresolved', function () {
    EmbyLibraryMapping::factory()
        ->for($this->integration->user)
        ->for($this->integration, 'integration')
        ->create([
            'collection_type' => 'tvshows',
            'target_library_id' => null,
            'target_library_name' => 'Managed TV',
            'output_path' => '/srv/emby/managed/tv',
            'is_managed' => true,
            'enabled' => true,
        ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/Items*' => Http::response(['Items' => []], 200),
    ]);

    expect(MediaServerService::make($this->integration)->fetchSeries())->toBeEmpty();
    Http::assertNothingSent();
});

it('excludes managed Emby libraries from default movie imports', function () {
    $this->integration->update([
        'available_libraries' => [
            ['id' => 'source-library', 'name' => 'Source Movies', 'type' => 'movies'],
            ['id' => 'managed-library', 'name' => 'Managed Movies', 'type' => 'movies'],
        ],
        'selected_library_ids' => [],
    ]);
    EmbyLibraryMapping::factory()
        ->for($this->integration->user)
        ->for($this->integration, 'integration')
        ->create([
            'target_library_id' => 'managed-library',
            'target_library_name' => 'Managed Movies',
            'is_managed' => true,
        ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/Items*' => Http::response(['Items' => []], 200),
    ]);

    MediaServerService::make($this->integration->refresh())->fetchMovies();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && ($request->data()['ParentId'] ?? null) === 'source-library');
    Http::assertNotSent(fn (Request $request): bool => ($request->data()['ParentId'] ?? null) === 'managed-library'
        || ($request->method() === 'GET' && ($request->data()['ParentId'] ?? null) === null));
});

it('preserves unfiltered imports for media types without selected libraries', function () {
    $this->integration->update([
        'available_libraries' => [
            ['id' => 'movie-library', 'name' => 'Movies', 'type' => 'movies'],
            ['id' => 'series-library', 'name' => 'Series', 'type' => 'tvshows'],
        ],
        'selected_library_ids' => ['series-library'],
    ]);

    expect($this->integration->getImportLibraryIdsForType('movies'))->toBeNull()
        ->and($this->integration->getImportLibraryIdsForType('tvshows'))->toBe(['series-library']);
});
