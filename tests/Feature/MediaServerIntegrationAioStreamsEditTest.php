<?php

use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use App\Filament\Resources\MediaServerIntegrations\Pages\EditMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\Pages\ViewAioStreamsCatalog;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\AioStreamsMoviesRelationManager;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\AioStreamsSeriesRelationManager;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\MoviesRelationManager;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\SeriesRelationManager;
use App\Filament\Resources\Series\SeriesResource;
use App\Models\MediaServerIntegration;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);
});

it('shows the browse catalog header action for an enabled aiostreams integration', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    Livewire::test(EditMediaServerIntegration::class, ['record' => $integration->id])
        ->assertActionVisible('browseCatalog');
});

it('hides the browse catalog header action for a disabled aiostreams integration', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => false,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    Livewire::test(EditMediaServerIntegration::class, ['record' => $integration->id])
        ->assertActionHidden('browseCatalog');
});

it('hides the browse catalog header action for non-aiostreams integrations', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test Jellyfin',
        'type' => 'jellyfin',
        'host' => 'jellyfin.example.com',
        'api_key' => 'secret',
        'enabled' => true,
    ]);

    Livewire::test(EditMediaServerIntegration::class, ['record' => $integration->id])
        ->assertActionHidden('browseCatalog');
});

it('renders the AIOStreams catalog browse sub-page', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    Livewire::test(ViewAioStreamsCatalog::class, ['record' => $integration->id])
        ->assertOk()
        ->assertSee('Search movies');
});

it('links "Back to Media Server" on the browse catalog page back to this specific integration, not the index', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    Livewire::test(ViewAioStreamsCatalog::class, ['record' => $integration->id])
        ->assertActionHasUrl('back', MediaServerIntegrationResource::getUrl('edit', ['record' => $integration]));
});

it('shows the AIOStreams movies and series relation managers for aiostreams integrations', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    expect(AioStreamsMoviesRelationManager::canViewForRecord($integration, EditMediaServerIntegration::class))->toBeTrue();
    expect(AioStreamsSeriesRelationManager::canViewForRecord($integration, EditMediaServerIntegration::class))->toBeTrue();
});

it('hides the AIOStreams movies and series relation managers for non-aiostreams integrations', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test Jellyfin',
        'type' => 'jellyfin',
        'host' => 'jellyfin.example.com',
        'api_key' => 'secret',
        'enabled' => true,
    ]);

    expect(AioStreamsMoviesRelationManager::canViewForRecord($integration, EditMediaServerIntegration::class))->toBeFalse();
    expect(AioStreamsSeriesRelationManager::canViewForRecord($integration, EditMediaServerIntegration::class))->toBeFalse();
});

it('hides the movies and series relation managers for aiostreams integrations', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    expect(MoviesRelationManager::canViewForRecord($integration, EditMediaServerIntegration::class))->toBeFalse();
    expect(SeriesRelationManager::canViewForRecord($integration, EditMediaServerIntegration::class))->toBeFalse();
});

it('still shows the movies and series relation managers for non-aiostreams integrations', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test Jellyfin',
        'type' => 'jellyfin',
        'host' => 'jellyfin.example.com',
        'api_key' => 'secret',
        'enabled' => true,
    ]);

    expect(MoviesRelationManager::canViewForRecord($integration, EditMediaServerIntegration::class))->toBeTrue();
    expect(SeriesRelationManager::canViewForRecord($integration, EditMediaServerIntegration::class))->toBeTrue();
});

it('renders the edit page for an aiostreams integration without error', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    Livewire::test(EditMediaServerIntegration::class, ['record' => $integration->id])
        ->assertOk();
});

it('links the series edit action to the full Series edit page instead of opening a slideOver', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $integration->getOrCreatePlaylist()->id,
        'is_custom' => true,
        'aio_integration_id' => $integration->id,
        'aio_item_id' => 'tt1',
        'aio_type' => 'series',
    ]);

    Livewire::test(AioStreamsSeriesRelationManager::class, [
        'ownerRecord' => $integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])
        ->assertTableActionHasUrl('edit', SeriesResource::getUrl('edit', ['record' => $series]), record: $series);
});
