<?php

use App\Filament\Clusters\Settings\Pages\ManageApiSettings;
use App\Filament\Clusters\Settings\Pages\ManageCacheSettings;
use App\Filament\Clusters\Settings\Pages\ManageIntegrationSettings;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Resources\Playlists\Pages\CreatePlaylist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('renders the Cache settings page', function () {
    Livewire::test(ManageCacheSettings::class)
        ->assertOk();
});

it('saves the enable_cache toggle', function () {
    Livewire::test(ManageCacheSettings::class)
        ->fillForm(['enable_cache' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((bool) app(GeneralSettings::class)->refresh()->enable_cache)->toBeTrue();
});

it('saves the default_share_cache_across_playlists toggle', function () {
    Livewire::test(ManageCacheSettings::class)
        ->fillForm(['default_share_cache_across_playlists' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect((bool) app(GeneralSettings::class)->refresh()->default_share_cache_across_playlists)->toBeTrue();
});

it('saves the cache_retention_mode select value', function () {
    Livewire::test(ManageCacheSettings::class)
        ->fillForm(['cache_retention_mode' => 'never-expire'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->refresh()->cache_retention_mode)->toBe('never-expire');
});

it('does not wipe enable_cache when saving an unrelated field on the same page', function () {
    $settings = app(GeneralSettings::class);
    $settings->enable_cache = true;
    $settings->save();

    Livewire::test(ManageCacheSettings::class)
        ->fillForm(['cache_retention_mode' => 'manual'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = app(GeneralSettings::class)->refresh();

    expect((bool) $fresh->enable_cache)->toBeTrue()
        ->and($fresh->cache_retention_mode)->toBe('manual');
});

it('rejects non-admin users from the Cache settings page', function () {
    auth()->logout();
    $this->actingAs(User::factory()->create());

    expect(ManageCacheSettings::canAccess())->toBeFalse();
});

it('lists the Cache page between API and Integrations', function () {
    $pages = SettingsCluster::getClusteredComponents();

    expect(ManageCacheSettings::getNavigationSort())->toBeGreaterThan(ManageApiSettings::getNavigationSort())
        ->and(ManageCacheSettings::getNavigationSort())->toBeLessThan(ManageIntegrationSettings::getNavigationSort())
        ->and(array_search(ManageCacheSettings::class, $pages, true))
        ->toBe(array_search(ManageApiSettings::class, $pages, true) + 1);
});

it('shows the Docker volume callout only while caching is enabled', function () {
    Livewire::test(ManageCacheSettings::class)
        ->fillForm(['enable_cache' => false])
        ->assertDontSee('Mount a volume for cached files')
        ->fillForm(['enable_cache' => true])
        ->assertSee('Mount a volume for cached files')
        ->assertSee((string) config('filesystems.disks.cache.root'));
});

it('pre-fills the per-playlist share toggle from the global default on create', function (bool $globalDefault) {
    $settings = app(GeneralSettings::class);
    $settings->default_share_cache_across_playlists = $globalDefault;
    $settings->save();

    $settings->enable_cache = true;
    $settings->save();

    Livewire::test(CreatePlaylist::class)
        ->assertFormSet(['share_cache_across_playlists' => $globalDefault]);
})->with([true, false]);
