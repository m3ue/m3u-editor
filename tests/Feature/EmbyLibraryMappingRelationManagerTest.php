<?php

use App\Filament\Resources\MediaServerIntegrations\Pages\EditMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\EmbyLibraryMappingsRelationManager;
use App\Models\Category;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\EmbyLibraryMapping;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Series;
use App\Models\User;
use App\Services\EmbyPublicationCatalogService;
use Filament\Actions\Action as FilamentAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Invokes the relation manager's private sourceLabelOptions() directly, since
 * it's not reachable through a public API and the "Mapped group" field's
 * live-updating options aren't easily assertable through Livewire's action
 * testing helpers.
 */
function embyMappedGroupOptions($component, ?string $sourceKind, ?string $sourceIdentifier, ?string $collectionType): array
{
    $instance = $component->instance();
    $method = new ReflectionMethod($instance, 'sourceLabelOptions');
    $method->setAccessible(true);

    return $method->invoke($instance, $sourceKind, $sourceIdentifier, $collectionType);
}

/** Invokes the relation manager's private sourceSearchOptions() directly. */
function embySourceSearchOptions($component, ?string $sourceKind, string $search, ?string $onlyIdentifier = null): array
{
    $instance = $component->instance();
    $method = new ReflectionMethod($instance, 'sourceSearchOptions');
    $method->setAccessible(true);

    return $method->invoke($instance, $sourceKind, $search, $onlyIdentifier);
}

/** Invokes the relation manager's private compatibleLibraryPathOptions() directly. */
function embyCompatibleLibraryPathOptions($component, ?string $libraryId): array
{
    $instance = $component->instance();
    $method = new ReflectionMethod($instance, 'compatibleLibraryPathOptions');
    $method->setAccessible(true);

    return $method->invoke($instance, $libraryId);
}

it('shows managed library mappings only on authorized Emby integrations', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $emby = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $jellyfin = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'jellyfin']);
    $foreignEmby = MediaServerIntegration::factory()->createQuietly(['type' => 'emby']);

    expect(EmbyLibraryMappingsRelationManager::canViewForRecord($emby, EditMediaServerIntegration::class))->toBeTrue()
        ->and(EmbyLibraryMappingsRelationManager::canViewForRecord($jellyfin, EditMediaServerIntegration::class))->toBeFalse()
        ->and(EmbyLibraryMappingsRelationManager::canViewForRecord($foreignEmby, EditMediaServerIntegration::class))->toBeFalse();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    expect(EmbyLibraryMappingsRelationManager::canViewForRecord($foreignEmby, EditMediaServerIntegration::class))->toBeTrue();
});

it('creates an owned mapping from eligible sources and companion writable paths', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create(['name' => 'Action', 'type' => 'vod']);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed/movies'],
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'enabled' => true,
        'source_kind' => 'vod_group',
        'source_identifier' => (string) $group->id,
        'source_label' => 'Action',
        'target_library_id' => null,
        'target_library_name' => 'Managed Movies',
        'collection_type' => 'movies',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => true,
        'options' => [
            'naming' => 'media-year',
            'nfo' => true,
            'versions' => true,
            'cleanup' => 'replace',
            'refresh' => true,
        ],
    ])->assertHasNoActionErrors();

    $mapping = EmbyLibraryMapping::query()->sole();
    expect($mapping->user_id)->toBe($user->id)
        ->and($mapping->media_server_integration_id)->toBe($integration->id)
        ->and($mapping->source_identifier)->toBe((string) $group->id)
        ->and($mapping->output_path)->toBe('/srv/emby/managed/movies');
});

it('derives compatible existing-library destinations from Emby paths and companion roots', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed', 'C:\\Emby\\Managed'],
        'available_libraries' => [
            [
                'id' => 'single',
                'name' => 'Single',
                'type' => 'movies',
                'paths' => ['/srv/emby/managed/movies', '/srv/emby/private'],
            ],
            [
                'id' => 'multiple',
                'name' => 'Multiple',
                'type' => 'tvshows',
                'paths' => ['/srv/emby/managed', 'c:/emby/managed/tv'],
            ],
            [
                'id' => 'none',
                'name' => 'None',
                'type' => 'movies',
                'paths' => ['/srv/emby/private', '/srv/emby/managed2'],
            ],
        ],
    ]);
    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ]);

    expect(embyCompatibleLibraryPathOptions($component, 'single'))->toBe([
        '/srv/emby/managed/movies' => '/srv/emby/managed/movies',
    ])->and(embyCompatibleLibraryPathOptions($component, 'multiple'))->toBe([
        '/srv/emby/managed' => '/srv/emby/managed',
        'c:/emby/managed/tv' => 'c:/emby/managed/tv',
    ])->and(embyCompatibleLibraryPathOptions($component, 'none'))->toBe([]);
});

it('shows only the focused existing-library destination controls', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed'],
        'available_libraries' => [[
            'id' => 'library-1',
            'name' => 'Existing Movies',
            'type' => 'movies',
            'paths' => ['/srv/emby/private'],
        ]],
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->mountAction(TestAction::make('create')->table())
        ->set('mountedActions.0.data.destination_mode', 'existing')
        ->set('mountedActions.0.data.target_library_id', 'library-1')
        ->assertMountedActionModalDontSee('Library name')
        ->assertMountedActionModalDontSee('Library type')
        ->assertMountedActionModalDontSee('Create and manage this Emby library')
        ->assertMountedActionModalDontSee('Compatible library path')
        ->assertMountedActionModalSee('No compatible writable destination is available. Register a writable root in the Emby companion, then refresh this integration.');
});

it('automatically selects one compatible existing path and asks when several are available', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed'],
        'available_libraries' => [
            [
                'id' => 'single',
                'name' => 'Single',
                'type' => 'movies',
                'paths' => ['/srv/emby/managed/movies'],
            ],
            [
                'id' => 'multiple',
                'name' => 'Multiple',
                'type' => 'movies',
                'paths' => ['/srv/emby/managed/movies', '/srv/emby/managed/movies-4k'],
            ],
        ],
    ]);
    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->mountAction(TestAction::make('create')->table())
        ->set('mountedActions.0.data.destination_mode', 'existing')
        ->set('mountedActions.0.data.target_library_id', 'single')
        ->assertSet('mountedActions.0.data.output_path', '/srv/emby/managed/movies')
        ->assertMountedActionModalDontSee('Compatible library path');

    $component->set('mountedActions.0.data.target_library_id', 'multiple')
        ->assertSet('mountedActions.0.data.output_path', null)
        ->assertMountedActionModalSee('Compatible library path');
});

it('derives existing library values instead of accepting redundant submitted fields', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed'],
        'available_libraries' => [[
            'id' => 'library-1',
            'name' => 'Existing Movies',
            'type' => 'movies',
            'paths' => ['/srv/emby/managed/movies'],
        ]],
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'destination_mode' => 'existing',
        'enabled' => true,
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => 'library-1',
        'target_library_name' => 'Spoofed name',
        'collection_type' => 'tvshows',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => true,
        'options' => [
            'naming' => 'media-year',
            'nfo' => true,
            'versions' => true,
            'cleanup' => 'replace',
            'refresh' => true,
        ],
    ])->assertHasNoActionErrors();

    expect(EmbyLibraryMapping::query()->sole())
        ->target_library_name->toBe('Existing Movies')
        ->collection_type->toBe('movies')
        ->is_managed->toBeFalse();
});

it('creates a managed library from only its required destination choices', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed/movies'],
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'destination_mode' => 'new',
        'enabled' => true,
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => 'spoofed-library',
        'target_library_name' => 'Managed Movies',
        'collection_type' => 'movies',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => false,
        'options' => [
            'naming' => 'media-year',
            'nfo' => true,
            'versions' => true,
            'cleanup' => 'replace',
            'refresh' => true,
        ],
    ])->assertHasNoActionErrors();

    expect(EmbyLibraryMapping::query()->sole())
        ->target_library_id->toBeNull()
        ->is_managed->toBeTrue();
});

it('registers companion writable paths before creating the first owned mapping', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $group = Group::factory()->for($user)->for($playlist)->create([
        'name' => 'Action',
        'type' => 'vod',
    ]);
    $auth = PlaylistAuth::factory()->for($user)->create([
        'enabled' => true,
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'library_publishing_enabled' => true,
    ]);
    $auth->assignTo($playlist);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => null,
    ]);

    $this->postJson('/player_api.php', [
        'username' => 'emby-companion',
        'password' => 'companion-secret',
        'action' => 'm3u_editor_register_publisher',
        'api_version' => 1,
        'integration_id' => $integration->id,
        'writable_paths' => ['/srv/emby/managed/movies'],
    ])->assertOk()
        ->assertJsonPath('data.integration_id', $integration->id)
        ->assertJsonPath('data.writable_paths', ['/srv/emby/managed/movies']);

    expect($integration->refresh()->emby_publisher_writable_paths)
        ->toBe(['/srv/emby/managed/movies'])
        ->and($integration->emby_publisher_capabilities_updated_at)->not->toBeNull();

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'enabled' => true,
        'source_kind' => 'vod_group',
        'source_identifier' => (string) $group->id,
        'source_label' => 'Action',
        'target_library_id' => null,
        'target_library_name' => 'Managed Movies',
        'collection_type' => 'movies',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => true,
        'options' => [
            'naming' => 'media-year',
            'nfo' => true,
            'versions' => true,
            'cleanup' => 'replace',
            'refresh' => true,
        ],
    ])->assertHasNoActionErrors();

    $mapping = EmbyLibraryMapping::query()->sole();
    expect($mapping->user_id)->toBe($user->id)
        ->and($mapping->media_server_integration_id)->toBe($integration->id)
        ->and($mapping->output_path)->toBe('/srv/emby/managed/movies');
});

it('rejects foreign sources and unadvertised output paths', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $otherUser = User::factory()->create();
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $otherPlaylist = Playlist::factory()->for($otherUser)->createQuietly();
    $foreignGroup = Group::factory()->for($otherUser)->for($otherPlaylist)->create([
        'name' => 'Foreign',
        'type' => 'vod',
    ]);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed/movies'],
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('create')->table(), [
        'enabled' => true,
        'source_kind' => 'vod_group',
        'source_identifier' => (string) $foreignGroup->id,
        'source_label' => 'Foreign',
        'target_library_name' => 'Managed Movies',
        'collection_type' => 'movies',
        'output_path' => '/unadvertised/path',
        'is_managed' => true,
        'options' => [
            'naming' => 'media-year',
            'cleanup' => 'replace',
        ],
    ])->assertHasActionErrors([
        'source_identifier',
        'source_label',
        'output_path',
    ]);

    expect(EmbyLibraryMapping::query()->count())->toBe(0);
});

it('shows mapping state and toggles publishing without deleting state', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed/movies'],
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => null,
        'output_path' => '/srv/emby/managed/movies',
        'status' => 'failed',
        'error_summary' => 'Redacted failure',
        'enabled' => true,
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->assertCanSeeTableRecords([$mapping])
        ->assertSee('Redacted failure')
        ->assertTableActionExists('preview')
        ->assertTableActionExists('reconcile')
        ->assertTableActionExists('edit')
        ->assertTableActionExists('delete')
        ->call('updateTableColumnState', 'enabled', (string) $mapping->id, false);

    expect($mapping->refresh()->enabled)->toBeFalse()
        ->and($mapping->exists)->toBeTrue();
});

it('previews the exact canonical dry-run plan without mutating the mapping', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => null,
        'last_planned_revision' => null,
    ]);
    $plan = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->assertTableActionExists(
        'preview',
        fn (FilamentAction $action): bool => str_contains(
            (string) $action->getModalContent(),
            $plan['revision'],
        ),
        $mapping,
    );

    expect($mapping->refresh()->last_planned_revision)->toBeNull();
});

it('defers reconcile with a generic pending result while the managed library is unresolved', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_publisher_writable_paths' => ['/srv/emby/managed/movies'],
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => null,
        'target_library_name' => 'Managed Movies',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => true,
        'last_planned_revision' => 'unsafe-revision',
    ]);
    Http::preventStrayRequests();
    Http::fakeSequence('https://emby.test:8096/Library/VirtualFolders')
        ->push([], 200)
        ->push([], 204)
        ->push([], 200);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('reconcile')->table($mapping))
        ->assertNotified();

    $mapping->refresh();
    expect($mapping->target_library_id)->toBeNull()
        ->and($mapping->status)->toBe('pending')
        ->and($mapping->status_summary)->toBe('Pending')
        ->and($mapping->error_summary)->toBeNull()
        ->and($mapping->last_planned_revision)->toBeNull()
        ->and($mapping->status_summary.$mapping->error_summary)
        ->not->toContain('emby-secret', '/srv/emby/managed/movies');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

it('resolves a pending managed library from a later exact listing without duplicate state', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_publisher_writable_paths' => ['/srv/emby/managed/movies'],
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => null,
        'target_library_name' => 'Managed Movies',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => true,
        'last_planned_revision' => null,
    ]);
    Http::preventStrayRequests();
    Http::fakeSequence('https://emby.test:8096/Library/VirtualFolders')
        ->push([], 200)
        ->push([], 204)
        ->push([], 200)
        ->push([[
            'ItemId' => 'managed-library-1',
            'Name' => 'Managed Movies',
            'CollectionType' => 'movies',
            'Locations' => ['/srv/emby/managed/movies'],
        ]], 200);

    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('reconcile')->table($mapping))
        ->assertNotified();

    expect($mapping->refresh()->status)->toBe('pending')
        ->and($mapping->target_library_id)->toBeNull();

    $component->callAction(TestAction::make('reconcile')->table($mapping))
        ->assertNotified();

    $mapping->refresh();
    $currentPlan = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);
    expect($mapping->target_library_id)->toBe('managed-library-1')
        ->and($mapping->status)->toBe('planned')
        ->and($mapping->last_planned_revision)->toBe($currentPlan['revision'])
        ->and(EmbyLibraryMapping::query()->count())->toBe(1)
        ->and(Http::recorded(fn (Request $request): bool => $request->method() === 'POST'))->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

it('creates a managed Emby library and plans a bounded manual reconcile', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'host' => 'emby.test',
        'port' => 8096,
        'ssl' => true,
        'api_key' => 'emby-secret',
        'emby_publisher_writable_paths' => ['/srv/emby/managed/movies'],
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => null,
        'target_library_name' => 'Managed Movies',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => true,
        'last_planned_revision' => null,
    ]);
    Http::preventStrayRequests();
    Http::fakeSequence('https://emby.test:8096/Library/VirtualFolders')
        ->push([], 200)
        ->push([], 204)
        ->push([[
            'ItemId' => 'managed-library-1',
            'Name' => 'Managed Movies',
            'CollectionType' => 'movies',
            'Locations' => ['/srv/emby/managed/movies'],
        ]], 200);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('reconcile')->table($mapping))
        ->assertNotified();

    $mapping->refresh();
    $currentPlan = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);
    expect($mapping->target_library_id)->toBe('managed-library-1')
        ->and($mapping->status)->toBe('planned')
        ->and($mapping->last_planned_revision)->toBe($currentPlan['revision'])
        ->and($mapping->last_applied_revision)->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

it('edits and deletes an owned mapping through Filament actions', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed/movies'],
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => null,
        'target_library_name' => 'Managed Movies',
        'output_path' => '/srv/emby/managed/movies',
    ]);

    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('edit')->table($mapping), [
        'enabled' => true,
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => null,
        'target_library_name' => 'Renamed Managed Movies',
        'collection_type' => 'movies',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => true,
        'options' => [
            'naming' => 'media-year',
            'nfo' => true,
            'versions' => true,
            'cleanup' => 'replace',
            'refresh' => true,
        ],
    ])->assertHasNoActionErrors();

    expect($mapping->refresh()->target_library_name)->toBe('Renamed Managed Movies');

    $component->callAction(TestAction::make('delete')->table($mapping));
    expect(EmbyLibraryMapping::find($mapping->id))->toBeNull();
});

it('preserves an unavailable existing target on edit and requires a new choice', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed'],
        'available_libraries' => [],
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => 'missing-library',
        'target_library_name' => 'Existing Movies',
        'collection_type' => 'movies',
        'output_path' => '/srv/emby/managed/movies',
        'is_managed' => false,
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->mountAction(TestAction::make('edit')->table($mapping))
        ->assertSet('mountedActions.0.data.destination_mode', 'existing')
        ->assertSet('mountedActions.0.data.target_library_id', 'missing-library')
        ->callMountedAction()
        ->assertHasActionErrors(['target_library_id']);

    expect($mapping->refresh())
        ->target_library_id->toBe('missing-library')
        ->output_path->toBe('/srv/emby/managed/movies');
});

it('does not silently replace a missing saved path when editing an existing target', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly([
        'type' => 'emby',
        'emby_publisher_writable_paths' => ['/srv/emby/managed'],
        'available_libraries' => [[
            'id' => 'library-1',
            'name' => 'Existing Movies',
            'type' => 'movies',
            'paths' => ['/srv/emby/managed/new-path'],
        ]],
    ]);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => 'library-1',
        'target_library_name' => 'Existing Movies',
        'collection_type' => 'movies',
        'output_path' => '/srv/emby/managed/old-path',
        'is_managed' => false,
    ]);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->callAction(TestAction::make('edit')->table($mapping), [
        'destination_mode' => 'existing',
        'enabled' => true,
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'target_library_id' => 'library-1',
        'output_path' => '/srv/emby/managed/old-path',
        'options' => $mapping->options,
    ])->assertHasActionErrors();

    expect($mapping->refresh()->output_path)->toBe('/srv/emby/managed/old-path');
});

it('returns no Mapped group options for a live-only custom playlist, for either library type', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $customPlaylist = CustomPlaylist::factory()->for($user)->createQuietly();

    // Live channels only (is_vod: false, no series attached) — nothing here
    // is eligible content for Emby publishing (movies/tvshows only).
    $liveChannel = Channel::factory()->for($user)->for($playlist)->createQuietly([
        'group' => 'PPV', 'is_vod' => false, 'enabled' => true,
    ]);
    $customPlaylist->channels()->attach([$liveChannel->id]);

    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ]);

    expect(embyMappedGroupOptions($component, 'custom_playlist_group', (string) $customPlaylist->id, 'movies'))->toBe([])
        ->and(embyMappedGroupOptions($component, 'custom_playlist_group', (string) $customPlaylist->id, 'tvshows'))->toBe([]);
});

it('scopes Mapped group options to VOD groups for movies and series categories for tvshows independently', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $customPlaylist = CustomPlaylist::factory()->for($user)->createQuietly();

    $vodChannel = Channel::factory()->for($user)->for($playlist)->createQuietly([
        'group' => 'Action', 'is_vod' => true, 'enabled' => true,
    ]);
    $customPlaylist->channels()->attach([$vodChannel->id]);

    $category = Category::factory()->for($user)->createQuietly(['name' => 'Drama']);
    $series = Series::factory()->for($user)->for($category)->createQuietly(['enabled' => true]);
    $customPlaylist->series()->attach([$series->id]);

    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ]);

    expect(embyMappedGroupOptions($component, 'custom_playlist_group', (string) $customPlaylist->id, 'movies'))
        ->toBe(['Action' => 'Action'])
        ->and(embyMappedGroupOptions($component, 'custom_playlist_group', (string) $customPlaylist->id, 'tvshows'))
        ->toBe(['Drama' => 'Drama']);
});

it('does not prematurely populate Mapped group with the custom playlist\'s own name', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $customPlaylist = CustomPlaylist::factory()->for($user)->createQuietly(['name' => 'Sports']);
    $vodChannel = Channel::factory()->for($user)->for($playlist)->createQuietly([
        'group' => 'Action', 'is_vod' => true, 'enabled' => true,
    ]);
    $customPlaylist->channels()->attach([$vodChannel->id]);

    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);

    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->mountAction(TestAction::make('create')->table());

    // sourceOptions('custom_playlist_group') labels are the CustomPlaylist's
    // own name ("Sports") — selecting it as "Source" must not leak that into
    // "Mapped group", which is only ever populated from sourceLabelOptions().
    $component->set('mountedActions.0.data.source_kind', 'custom_playlist_group')
        ->set('mountedActions.0.data.source_identifier', (string) $customPlaylist->id)
        ->assertSet('mountedActions.0.data.source_label', null);

    // Nor should picking a library type resurrect the stale value.
    $component->set('mountedActions.0.data.collection_type', 'movies')
        ->assertSet('mountedActions.0.data.source_label', null);
});

it('disambiguates same-named VOD groups across playlists in the Source search, without leaking the suffix into Mapped group', function () {
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlistA = Playlist::factory()->for($user)->createQuietly(['name' => 'Provider A']);
    $playlistB = Playlist::factory()->for($user)->createQuietly(['name' => 'Provider B']);
    $groupA = Group::factory()->for($user)->for($playlistA)->create(['name' => 'Action', 'type' => 'vod']);
    $groupB = Group::factory()->for($user)->for($playlistB)->create(['name' => 'Action', 'type' => 'vod']);

    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $component = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ]);

    $results = embySourceSearchOptions($component, 'vod_group', 'action');
    expect($results)->toBe([
        (string) $groupA->id => 'Action (Provider A)',
        (string) $groupB->id => 'Action (Provider B)',
    ]);

    // The already-selected-value lookup (getOptionLabelUsing) resolves the same way.
    expect(embySourceSearchOptions($component, 'vod_group', '', (string) $groupB->id))
        ->toBe([(string) $groupB->id => 'Action (Provider B)']);

    // Mapped group's auto-populated value must stay the raw group name — it's
    // matched verbatim against channels.group by EmbyPublicationCatalogService,
    // so the "(Provider B)" UI disambiguation must never leak into it.
    $action = Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->mountAction(TestAction::make('create')->table());

    $action->set('mountedActions.0.data.source_kind', 'vod_group')
        ->set('mountedActions.0.data.source_identifier', (string) $groupB->id)
        ->assertSet('mountedActions.0.data.source_label', 'Action');
});

it('caps the number of items rendered in the Preview modal without affecting the actual revision hash', function () {
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    $user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($user);
    $playlist = Playlist::factory()->for($user)->createQuietly();
    $integration = MediaServerIntegration::factory()->for($user)->createQuietly(['type' => 'emby']);
    $mapping = EmbyLibraryMapping::factory()->for($user)->for($integration, 'integration')->create([
        'source_kind' => 'all',
        'source_identifier' => '*',
        'source_label' => 'All eligible items',
        'collection_type' => 'movies',
        'target_library_id' => null,
    ]);

    // More than the 50-item preview cap.
    Channel::factory()->for($user)->for($playlist)->count(60)->createQuietly([
        'is_vod' => true, 'enabled' => true,
    ]);

    $fullPlan = app(EmbyPublicationCatalogService::class)->buildMapping($mapping);
    expect($fullPlan['items'])->toHaveCount(60);

    Livewire::test(EmbyLibraryMappingsRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])->assertTableActionExists('preview', function (FilamentAction $action) use ($fullPlan): bool {
        $modalHtml = (string) $action->getModalContent();

        // The rendered JSON only carries 50 items...
        $renderedItemCount = substr_count($modalHtml, '&quot;canonical_id&quot;');

        // ...but the revision shown is still the hash of the complete, untruncated catalog.
        return $renderedItemCount === 50
            && str_contains($modalHtml, $fullPlan['revision']);
    }, $mapping);
});
