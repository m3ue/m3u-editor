<?php

use App\Filament\Resources\MediaServerIntegrations\Pages\EditMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\EmbyLibraryMappingsRelationManager;
use App\Models\EmbyLibraryMapping;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\EmbyPublicationCatalogService;
use Filament\Actions\Action as FilamentAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
            (string) $action->getModalDescription(),
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
