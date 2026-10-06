<?php

use App\Filament\Clusters\MediaServers\MediaServersCluster;
use App\Filament\Pages\RequestContent;
use App\Filament\Resources\ArrIntegrations\ArrIntegrationResource;
use App\Filament\Resources\ArrIntegrations\Pages\ListArrIntegrations;
use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use App\Filament\Resources\MediaServerIntegrations\Pages\ListMediaServerIntegrations;
use App\Models\ArrIntegration;
use App\Models\MediaServerIntegration;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);
});

it('keeps every page under the /media-server-integrations prefix', function () {
    expect(MediaServersCluster::getUrl())->toEndWith('/media-server-integrations')
        ->and(MediaServerIntegrationResource::getUrl('index'))->toEndWith('/media-server-integrations/servers')
        ->and(ArrIntegrationResource::getUrl('index'))->toEndWith('/media-server-integrations/arr')
        ->and(RequestContent::getUrl())->toEndWith('/media-server-integrations/request-content');
});

it('opens Media Servers from the cluster root', function () {
    Livewire::test(MediaServersCluster::class)
        ->assertRedirect(MediaServerIntegrationResource::getUrl('index'));
});

it('shows one Media Servers item in the sidebar', function () {
    $items = MediaServersCluster::getNavigationItems();

    expect($items)->toHaveCount(1)
        ->and($items[0]->getLabel())->toBe('Media Servers')
        ->and($items[0]->getUrl())->toBe(MediaServersCluster::getUrl());
});

it('links the Sonarr & Radarr tab instead of rendering its table under Media Servers', function () {
    ArrIntegration::factory()->radarr()->create(['user_id' => $this->user->id, 'name' => 'Radarr 4K']);

    Livewire::test(ListMediaServerIntegrations::class)
        ->assertOk()
        ->assertSee(ArrIntegrationResource::getUrl('index'))
        ->assertDontSee('Radarr 4K');

    Livewire::test(ListArrIntegrations::class)
        ->assertOk()
        ->assertCanSeeTableRecords(ArrIntegration::all());
});

it('builds breadcrumbs under Media Servers', function () {
    expect(Livewire::test(ListMediaServerIntegrations::class)->instance()->getBreadcrumbs())
        ->toBe([
            MediaServerIntegrationResource::getUrl('index') => 'Media Servers',
            0 => 'List',
        ])
        ->and(Livewire::test(ListArrIntegrations::class)->instance()->getBreadcrumbs())
        ->toBe([
            MediaServersCluster::getUrl() => 'Media Servers',
            ArrIntegrationResource::getUrl('index') => 'Sonarr & Radarr',
            0 => 'List',
        ]);
});

it('creates and edits Sonarr & Radarr integrations in slide-overs on the list', function () {
    $integration = ArrIntegration::factory()->radarr()->create(['user_id' => $this->user->id]);

    expect(array_keys(ArrIntegrationResource::getPages()))->toBe(['index']);

    Livewire::test(ListArrIntegrations::class)
        ->assertActionExists(TestAction::make('create')->table(), fn (CreateAction $action): bool => $action->isModalSlideOver())
        ->assertActionExists(TestAction::make('edit')->table($integration), fn (EditAction $action): bool => $action->isModalSlideOver());
});

it('blocks users without the integrations permission', function () {
    $this->actingAs(User::factory()->create(['permissions' => []]));

    expect(MediaServersCluster::canAccess())->toBeFalse()
        ->and(MediaServersCluster::shouldRegisterNavigation())->toBeFalse();
});

it('filters media servers by every type, including WebDAV and AIOStreams', function () {
    $servers = collect(['plex', 'webdav', 'aiostreams'])->mapWithKeys(fn (string $type): array => [
        $type => MediaServerIntegration::factory()->create(['user_id' => $this->user->id, 'type' => $type]),
    ]);

    Livewire::test(ListMediaServerIntegrations::class)
        ->assertTableFilterExists('type', fn (SelectFilter $filter): bool => $filter->getOptions() === [
            'emby' => 'Emby',
            'jellyfin' => 'Jellyfin',
            'plex' => 'Plex',
            'local' => 'Local Media',
            'webdav' => 'WebDAV',
            'aiostreams' => 'AIOStreams',
        ])
        ->filterTable('type', 'aiostreams')
        ->assertCanSeeTableRecords([$servers['aiostreams']])
        ->assertCanNotSeeTableRecords([$servers['plex'], $servers['webdav']])
        ->filterTable('type', 'webdav')
        ->assertCanSeeTableRecords([$servers['webdav']])
        ->assertCanNotSeeTableRecords([$servers['plex'], $servers['aiostreams']]);
});

it('lists Request Content under Sonarr & Radarr only once one of the user\'s integrations is enabled', function () {
    // Another user's enabled integration and the user's disabled one don't count.
    ArrIntegration::factory()->radarr()->create(['enabled' => true]);
    $integration = ArrIntegration::factory()->radarr()->create(['user_id' => $this->user->id, 'enabled' => false]);

    expect(RequestContent::shouldRegisterNavigation())->toBeFalse();
    Livewire::test(ListArrIntegrations::class)
        ->assertDontSee(RequestContent::getUrl());

    $integration->update(['enabled' => true]);

    expect(RequestContent::shouldRegisterNavigation())->toBeTrue();
    Livewire::test(ListArrIntegrations::class)
        ->assertSeeInOrder([ArrIntegrationResource::getUrl('index'), RequestContent::getUrl()]);
});

it('keeps Request Content under the Media Servers breadcrumb', function () {
    expect(Livewire::test(RequestContent::class)->instance()->getBreadcrumbs())
        ->toBe([MediaServersCluster::getUrl() => 'Media Servers']);
});
