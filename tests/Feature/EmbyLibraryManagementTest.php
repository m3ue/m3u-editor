<?php

use App\Interfaces\MediaServer;
use App\Models\EmbyLibraryMapping;
use App\Models\MediaServerIntegration;
use App\Models\User;
use App\Services\EmbyManagedSetupService;
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
        'emby_publisher_writable_paths' => ['/srv/emby'],
    ]);
});

it('bootstraps managed publishing over trusted Docker HTTP with the saved administrator credential', function () {
    $this->integration->update([
        'host' => 'emby',
        'ssl' => false,
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'http://emby:8096/M3uEditor/Managed/Setup/V1' => Http::response([
            'CapabilityVersion' => 1,
            'IntegrationId' => $this->integration->id,
            'ConfirmedRoot' => '/config/plugins/m3u-editor/managed-publishing',
            'Ready' => true,
            'Result' => 'Ready',
        ]),
    ]);

    $result = app(EmbyManagedSetupService::class)->setup($this->integration);

    expect($result['success'])->toBeTrue()
        ->and($this->integration->refresh())
        ->emby_managed_setup_binding_id->toBe($this->integration->id)
        ->emby_managed_setup_root->toBe('/config/plugins/m3u-editor/managed-publishing')
        ->emby_managed_setup_capability_version->toBe(1)
        ->emby_managed_setup_contract_version->toBe(1)
        ->and($this->integration->emby_publisher_writable_paths)
        ->toBe(['/srv/emby']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'http://emby:8096/M3uEditor/Managed/Setup/V1'
        && $request->hasHeader('X-Emby-Token', 'emby-secret')
        && $request->data() === ['IntegrationId' => $this->integration->id]);
});

it('allows managed setup only over approved transport origins', function (string $host, bool $ssl, bool $allowed) {
    $this->integration->update([
        'host' => $host,
        'ssl' => $ssl,
    ]);
    Http::preventStrayRequests();
    Http::fake(fn () => Http::response([
        'CapabilityVersion' => 1,
        'IntegrationId' => $this->integration->id,
        'ConfirmedRoot' => '/config/plugins/m3u-editor/managed-publishing',
        'Ready' => true,
        'Result' => 'Ready',
    ]));

    $result = app(EmbyManagedSetupService::class)->setup($this->integration);

    expect($result['success'])->toBe($allowed);
    if ($allowed) {
        Http::assertSentCount(1);
    } else {
        Http::assertNothingSent();
    }
})->with([
    'Docker service name over HTTP' => ['emby', false, true],
    'IPv4 loopback over HTTP' => ['127.0.0.1', false, true],
    'RFC1918 10/8 over HTTP' => ['10.2.3.4', false, true],
    'RFC1918 172.16/12 over HTTP' => ['172.31.255.1', false, true],
    'RFC1918 192.168/16 over HTTP' => ['192.168.50.2', false, true],
    'IPv4 link-local over HTTP' => ['169.254.10.20', false, true],
    'IPv6 loopback over HTTP' => ['::1', false, true],
    'IPv6 ULA over HTTP' => ['fd12:3456:789a::1', false, true],
    'IPv6 link-local over HTTP' => ['fe80::1', false, true],
    'public IPv4 over HTTP' => ['8.8.8.8', false, false],
    'integer-encoded public IPv4 over HTTP' => ['134744072', false, false],
    'hex-encoded public IPv4 over HTTP' => ['0x08080808', false, false],
    'public hostname over HTTP' => ['emby.example.com', false, false],
    'public hostname over HTTPS' => ['emby.example.com', true, true],
    'userinfo-like host over HTTPS' => ['admin@emby.example.com', true, false],
    'query-like host over HTTPS' => ['emby.example.com?target=private', true, false],
]);

it('adds the confirmed managed root while preserving existing publisher roots and mappings', function () {
    $mapping = EmbyLibraryMapping::factory()
        ->for($this->integration->user)
        ->for($this->integration, 'integration')
        ->create([
            'output_path' => '/srv/emby/existing-library',
            'target_library_id' => 'existing-library',
        ]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/M3uEditor/Managed/Setup/V1' => Http::response([
            'CapabilityVersion' => 1,
            'IntegrationId' => $this->integration->id,
            'ConfirmedRoot' => '/config/plugins/m3u-editor/managed-publishing',
            'Ready' => true,
            'Result' => 'Ready',
        ]),
    ]);

    $result = app(EmbyManagedSetupService::class)->setup($this->integration);
    expect($result['success'])->toBeTrue()
        ->and($this->integration->emby_publisher_writable_paths)->toBe(['/srv/emby'])
        ->and($this->integration->getEmbyPublisherWritablePaths())->toBe([
            '/config/plugins/m3u-editor/managed-publishing',
            '/srv/emby',
        ])
        ->and($mapping->refresh())
        ->output_path->toBe('/srv/emby/existing-library')
        ->target_library_id->toBe('existing-library');
    Http::assertSentCount(1);
});

it('fails closed without state changes for rejected or partial managed setup responses', function (mixed $body, int $status) {
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/M3uEditor/Managed/Setup/V1' => Http::response($body, $status),
    ]);

    $result = app(EmbyManagedSetupService::class)->setup($this->integration);

    expect($result)->toBe([
        'success' => false,
        'message' => 'Install an Emby companion that supports managed setup version 1, then retry.',
    ])->and($this->integration->refresh())
        ->emby_managed_setup_binding_id->toBeNull()
        ->emby_managed_setup_root->toBeNull()
        ->emby_publisher_writable_paths->toBe(['/srv/emby']);
    Http::assertSentCount(1);
})->with([
    'unauthorized setup' => [[], 401],
    'old companion endpoint' => [[], 404],
    'redirect response' => [[], 302],
    'scalar JSON response' => ['"not ready"', 200],
    'list JSON response' => [['not ready'], 200],
    'malformed JSON response' => ['{', 200],
    'not ready' => [[
        'CapabilityVersion' => 1,
        'IntegrationId' => 1,
        'ConfirmedRoot' => '/config/plugins/m3u-editor/managed-publishing',
        'Ready' => false,
        'Result' => 'backend secret or local path',
    ], 200],
    'old capability version' => [[
        'CapabilityVersion' => 0,
        'IntegrationId' => 1,
        'ConfirmedRoot' => '/config/plugins/m3u-editor/managed-publishing',
        'Ready' => true,
        'Result' => 'Ready',
    ], 200],
    'wrong integration binding' => [[
        'CapabilityVersion' => 1,
        'IntegrationId' => 999,
        'ConfirmedRoot' => '/config/plugins/m3u-editor/managed-publishing',
        'Ready' => true,
        'Result' => 'Ready',
    ], 200],
    'unsafe confirmed root' => [[
        'CapabilityVersion' => 1,
        'IntegrationId' => 1,
        'ConfirmedRoot' => '../private',
        'Ready' => true,
        'Result' => 'Ready',
    ], 200],
]);

it('preserves fifty legacy writable roots across repeated managed setup', function () {
    $legacyRoots = array_map(fn (int $index): string => "/srv/legacy/{$index}", range(1, 50));
    $this->integration->update(['emby_publisher_writable_paths' => $legacyRoots]);
    $response = [
        'CapabilityVersion' => 1,
        'IntegrationId' => $this->integration->id,
        'ConfirmedRoot' => '/srv/managed',
        'Ready' => true,
    ];
    Http::preventStrayRequests();
    Http::fakeSequence('https://emby.test:8096/M3uEditor/Managed/Setup/V1')
        ->push($response)
        ->push($response)
        ->push($response);

    expect(app(EmbyManagedSetupService::class)->setup($this->integration)['success'])->toBeTrue()
        ->and(app(EmbyManagedSetupService::class)->setup($this->integration->refresh())['success'])->toBeTrue()
        ->and(app(EmbyManagedSetupService::class)->setup($this->integration->refresh())['success'])->toBeTrue()
        ->and($this->integration->refresh()->emby_publisher_writable_paths)->toBe($legacyRoots)
        ->and($this->integration->getEmbyPublisherWritablePaths())
        ->toBe(['/srv/managed', ...$legacyRoots]);
    Http::assertSentCount(3);
});

it('returns the sanitized retry error when the managed companion is unavailable', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/M3uEditor/Managed/Setup/V1' => Http::failedConnection(),
    ]);

    expect(app(EmbyManagedSetupService::class)->setup($this->integration))->toBe([
        'success' => false,
        'message' => 'Install an Emby companion that supports managed setup version 1, then retry.',
    ])->and($this->integration->refresh()->emby_managed_setup_root)->toBeNull();
});

it('requires every managed setup response to remain on the exact requested origin', function () {
    $method = new ReflectionMethod(EmbyManagedSetupService::class, 'originsMatch');
    $method->setAccessible(true);
    $service = app(EmbyManagedSetupService::class);

    expect($method->invoke($service, 'https://emby.test:8096', 'https://emby.test:8096/M3uEditor/Managed/Setup/V1'))->toBeTrue()
        ->and($method->invoke($service, 'https://emby.test:8096', 'http://emby.test:8096/M3uEditor/Managed/Setup/V1'))->toBeFalse()
        ->and($method->invoke($service, 'https://emby.test:8096', 'https://other.test:8096/M3uEditor/Managed/Setup/V1'))->toBeFalse()
        ->and($method->invoke($service, 'https://emby.test:8096', 'https://emby.test:8920/M3uEditor/Managed/Setup/V1'))->toBeFalse();
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

it('rejects a managed library path already owned by a different Emby library', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/Library/VirtualFolders' => Http::response([[
            'ItemId' => 'other-library',
            'Name' => 'Different Name',
            'CollectionType' => 'movies',
            'Locations' => ['/srv/emby/managed/movies'],
        ]]),
    ]);

    $result = MediaServerService::make($this->integration)->createLibrary(
        name: 'Managed Movies',
        collectionType: 'movies',
        paths: ['/srv/emby/managed/movies'],
        refreshLibrary: false,
    );

    expect($result['success'])->toBeFalse();
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('does not create a library when the managed inventory request fails', function (int $status) {
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/Library/VirtualFolders' => Http::response([], $status),
    ]);

    $result = MediaServerService::make($this->integration)->createLibrary(
        name: 'Managed Movies',
        collectionType: 'movies',
        paths: ['/srv/emby/managed/movies'],
        refreshLibrary: false,
    );

    expect($result['success'])->toBeFalse();
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
})->with([
    'redirect' => 302,
    'server failure' => 500,
]);

it('rejects overlapping managed library paths', function (string $root, string $existingPath, string $requestedPath) {
    $this->integration->update(['emby_publisher_writable_paths' => [$root]]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/Library/VirtualFolders' => Http::response([[
            'ItemId' => 'other-library',
            'Name' => 'Different Name',
            'CollectionType' => 'movies',
            'Locations' => [$existingPath],
        ]]),
    ]);

    $result = MediaServerService::make($this->integration->refresh())->createLibrary(
        name: 'Managed Movies',
        collectionType: 'movies',
        paths: [$requestedPath],
        refreshLibrary: false,
    );

    expect($result['success'])->toBeFalse();
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
})->with([
    'Unix requested child' => ['/srv/emby', '/srv/emby/movies', '/srv/emby/movies/child'],
    'Unix requested parent' => ['/srv/emby', '/srv/emby/movies/child', '/srv/emby/movies'],
    'Windows requested child' => ['C:\\Emby', 'C:\\Emby\\Movies', 'C:\\Emby\\Movies\\Child'],
    'Windows requested parent' => ['C:\\Emby', 'C:\\Emby\\Movies\\Child', 'C:\\Emby\\Movies'],
]);

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

it('does not drift an existing library when its selected path remains among several paths', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/Library/VirtualFolders' => Http::response([[
            'ItemId' => 'library-1',
            'Name' => 'Managed Movies',
            'CollectionType' => 'movies',
            'Locations' => ['/srv/emby/managed/movies', '/srv/emby/archive/movies'],
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
        ->and($result['drift'])->toBeFalse();
    Http::assertSentCount(1);
});

it('does not drift when a managed mapping path is below an existing library location', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/Library/VirtualFolders' => Http::response([[
            'ItemId' => 'library-1',
            'Name' => 'Managed Movies',
            'CollectionType' => 'movies',
            'Locations' => ['/srv/emby/managed/movies'],
        ]], 200),
    ]);

    $result = MediaServerService::make($this->integration)->createLibrary(
        name: 'Managed Movies',
        collectionType: 'movies',
        paths: ['/srv/emby/managed/movies/action-a1b2c3'],
        libraryId: 'library-1',
    );

    expect($result['success'])->toBeTrue()
        ->and($result['created'])->toBeFalse()
        ->and($result['drift'])->toBeFalse();
    Http::assertSentCount(1);
});

it('keeps drift when the existing library location is below the requested mapping path', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/Library/VirtualFolders' => Http::response([[
            'ItemId' => 'library-1',
            'Name' => 'Managed Movies',
            'CollectionType' => 'movies',
            'Locations' => ['/srv/emby/managed/movies/action-a1b2c3'],
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
        ->and($result['drift'])->toBeTrue();
    Http::assertSentCount(1);
});

it('matches an existing Windows library path without case or separator drift', function () {
    $this->integration->update(['emby_publisher_writable_paths' => ['C:\\Emby']]);
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/Library/VirtualFolders' => Http::response([[
            'ItemId' => 'library-1',
            'Name' => 'Managed Movies',
            'CollectionType' => 'movies',
            'Locations' => ['C:/EMBY/Managed/Movies'],
        ]], 200),
    ]);

    $result = MediaServerService::make($this->integration->refresh())->createLibrary(
        name: 'Managed Movies',
        collectionType: 'movies',
        paths: ['c:\\emby\\managed\\movies'],
        libraryId: 'library-1',
    );

    expect($result['success'])->toBeTrue()
        ->and($result['drift'])->toBeFalse();
    Http::assertSentCount(1);
});

it('keeps drift when the selected path is missing from an existing library', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://emby.test:8096/Library/VirtualFolders' => Http::response([[
            'ItemId' => 'library-1',
            'Name' => 'Managed Movies',
            'CollectionType' => 'movies',
            'Locations' => ['/srv/emby/archive/movies'],
        ]], 200),
    ]);

    $result = MediaServerService::make($this->integration)->createLibrary(
        name: 'Managed Movies',
        collectionType: 'movies',
        paths: ['/srv/emby/managed/movies'],
        libraryId: 'library-1',
    );

    expect($result['success'])->toBeTrue()
        ->and($result['drift'])->toBeTrue();
    Http::assertSentCount(1);
});

it('keeps drift when the stable target ID is missing despite an exact library', function () {
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
        ->and($result['library'])->toBeNull()
        ->and($result['drift'])->toBeTrue();
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        || $request->method() === 'DELETE');
});

it('keeps drift when an existing mapping path is unsafe or no longer companion-writable', function (string $path) {
    Http::preventStrayRequests();
    Http::fake();

    $result = MediaServerService::make($this->integration)->createLibrary(
        name: 'Managed Movies',
        collectionType: 'movies',
        paths: [$path],
        libraryId: 'library-1',
    );

    expect($result['success'])->toBeTrue()
        ->and($result['created'])->toBeFalse()
        ->and($result['library'])->toBeNull()
        ->and($result['drift'])->toBeTrue();
    Http::assertNothingSent();
})->with([
    'unsafe traversal' => ['/srv/emby/managed/../private'],
    'outside registered root' => ['/mnt/private/movies'],
]);

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
