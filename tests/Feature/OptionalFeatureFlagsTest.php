<?php

/**
 * PLUGINS_ENABLED, SCHEDULES_DIRECT_ENABLED, AIOSTREAMS_ENABLED and
 * CACHED_CONTENT_ENABLED work like DVR_ENABLED: switching one off hides the
 * feature and stops its background work, and config/horizon.php drops its
 * supervisor. Work must never be queued for a supervisor that isn't running.
 */

use App\Enums\EpgSourceType;
use App\Enums\Status;
use App\Filament\Clusters\Settings\Pages\ManageCacheSettings;
use App\Filament\Pages\PluginsDashboard;
use App\Filament\Resources\CachedContentFiles\CachedContentFileResource;
use App\Filament\Resources\MediaServerIntegrations\MediaServerIntegrationResource;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Filament\Resources\Plugins\PluginResource;
use App\Jobs\ProcessEpgImport;
use App\Jobs\ResolveAioStreamsChannel;
use App\Jobs\ResolveAioStreamsEpisode;
use App\Models\Epg;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\CachedContentDispatchService;
use App\Services\SchedulesDirectService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['is_admin' => true, 'permissions' => ['use_tools', 'use_integrations']]);
    $this->actingAs($this->user);
});

it('hides plugin management while plugins are disabled', function () {
    expect($this->user->canManagePlugins())->toBeTrue()
        ->and(PluginResource::canAccess())->toBeTrue()
        ->and(PluginsDashboard::canAccess())->toBeTrue();

    config()->set('plugins.enabled', false);

    expect($this->user->canManagePlugins())->toBeFalse()
        ->and(PluginResource::canAccess())->toBeFalse()
        ->and(PluginsDashboard::canAccess())->toBeFalse();
});

it('keeps SchedulesDirect EPG imports off the SD queue and skips them while SD is disabled', function () {
    Bus::fake();

    $epg = Epg::factory()->for($this->user)->createQuietly([
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'status' => Status::Completed,
        'auto_sync' => true,
    ]);

    expect((new ProcessEpgImport($epg))->queue)->toBe('schedules-direct');

    config()->set('services.schedules_direct.enabled', false);

    $job = new ProcessEpgImport($epg, force: true);
    expect($job->queue)->toBeNull();

    $job->handle(Mockery::mock(SchedulesDirectService::class));

    expect($epg->refresh()->status)->toBe(Status::Completed);
});

it('does not schedule SchedulesDirect EPG refreshes while SD is disabled', function (bool $enabled) {
    Bus::fake();
    config()->set('services.schedules_direct.enabled', $enabled);

    Epg::factory()->for($this->user)->createQuietly([
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'status' => Status::Completed,
        'auto_sync' => true,
        'synced' => null,
        'sync_interval' => '0 */6 * * *',
    ]);

    Artisan::call('app:refresh-epg');

    $enabled
        ? Bus::assertDispatched(ProcessEpgImport::class)
        : Bus::assertNotDispatched(ProcessEpgImport::class);
})->with(['enabled' => true, 'disabled' => false]);

it('rejects API syncs of SchedulesDirect EPGs while SD is disabled', function () {
    Bus::fake();
    config()->set('services.schedules_direct.enabled', false);

    $epg = Epg::factory()->for($this->user)->createQuietly([
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
    ]);

    $this->getJson(route('api.epg.sync', ['uuid' => $epg->uuid]))
        ->assertStatus(409);

    Bus::assertNotDispatched(ProcessEpgImport::class);
});

it('keeps AIOStreams resolution off its queue and skips it while AIOStreams is disabled', function () {
    expect((new ResolveAioStreamsChannel(1))->queue)->toBe('aiostreams-resolve')
        ->and((new ResolveAioStreamsEpisode(1))->queue)->toBe('aiostreams-resolve');

    config()->set('services.aiostreams.enabled', false);

    expect((new ResolveAioStreamsChannel(1))->queue)->toBeNull()
        ->and((new ResolveAioStreamsEpisode(1))->queue)->toBeNull();
});

it('hides AIOStreams integrations and the AIOStreams type while AIOStreams is disabled', function () {
    $integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    expect(MediaServerIntegrationResource::typeOptions())->toHaveKey('aiostreams')
        ->and(MediaServerIntegrationResource::getEloquentQuery()->pluck('id')->all())->toContain($integration->id);

    config()->set('services.aiostreams.enabled', false);

    expect(MediaServerIntegrationResource::typeOptions())->not->toHaveKey('aiostreams')
        ->and(MediaServerIntegrationResource::getEloquentQuery()->pluck('id')->all())->not->toContain($integration->id);
});

it('hides the playlist AIOStreams tab while AIOStreams is disabled or no integration is enabled', function () {
    Bus::fake();
    $playlist = Playlist::factory()->for($this->user)->createQuietly();

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->assertFormFieldHidden('aiostreams_integration_id');

    MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->assertFormFieldVisible('aiostreams_integration_id');

    config()->set('services.aiostreams.enabled', false);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->assertFormFieldHidden('aiostreams_integration_id');
});

it('hides the playlist Requests tab from users without the integrations permission', function () {
    Bus::fake();
    $playlist = Playlist::factory()->for($this->user)->createQuietly();

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->assertFormFieldVisible('request_enabled');

    $this->user->update(['is_admin' => false, 'permissions' => ['use_tools']]);

    Livewire::test(EditPlaylist::class, ['record' => $playlist->id])
        ->assertFormFieldHidden('request_enabled');
});

it('turns caching off regardless of the Enable cache setting while cached content is disabled', function () {
    $settings = app(GeneralSettings::class);
    $settings->enable_cache = true;
    $settings->save();

    expect(app(CachedContentDispatchService::class)->isEnabled())->toBeTrue()
        ->and(CachedContentFileResource::canAccess())->toBeTrue()
        ->and(ManageCacheSettings::canAccess())->toBeTrue();

    config()->set('cached_content.enabled', false);

    expect(app(CachedContentDispatchService::class)->isEnabled())->toBeFalse()
        ->and(CachedContentFileResource::canAccess())->toBeFalse()
        ->and(ManageCacheSettings::canAccess())->toBeFalse();
});

it('defaults every optional feature to on', function () {
    expect(config('plugins.enabled'))->toBeTrue()
        ->and(config('services.schedules_direct.enabled'))->toBeTrue()
        ->and(config('services.aiostreams.enabled'))->toBeTrue()
        ->and(config('cached_content.enabled'))->toBeTrue();
});
