<?php

use App\Filament\Resources\ArrIntegrations\Pages\ListArrIntegrations;
use App\Models\ArrIntegration;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Columns\ToggleColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function arrWidgetCacheSetting(bool $enabled): void
{
    $settings = new GeneralSettings;
    $settings->enable_cache = $enabled;
    app()->instance(GeneralSettings::class, $settings);
}

beforeEach(function () {
    Bus::fake();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);
    $this->integration = ArrIntegration::factory()->radarr()->create(['user_id' => $this->user->id]);
});

it('disables the Caching toggle while the cache feature is off', function () {
    arrWidgetCacheSetting(false);

    Livewire::test(ListArrIntegrations::class)
        ->assertTableColumnExists('cache_enabled', fn (ToggleColumn $column): bool => $column->isDisabled(), $this->integration);
});

it('enables the Caching toggle while the cache feature is on', function () {
    arrWidgetCacheSetting(true);

    Livewire::test(ListArrIntegrations::class)
        ->assertTableColumnExists('cache_enabled', fn (ToggleColumn $column): bool => ! $column->isDisabled(), $this->integration);
});

it('shows the Caching toggle off for an integration used for caching while the cache feature is off', function () {
    arrWidgetCacheSetting(false);
    $this->integration->update(['cache_enabled' => true]);

    Livewire::test(ListArrIntegrations::class)
        ->assertTableColumnStateSet('cache_enabled', false, $this->integration);
});

it('shows a locked off Use for caching in the edit slide-over while the cache feature is off', function () {
    arrWidgetCacheSetting(false);
    $this->integration->update(['cache_enabled' => true, 'cache_failback' => true]);

    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($this->integration))
        ->assertFormFieldHidden('cache_enabled')
        ->assertFormFieldDisabled('cache_enabled_locked')
        ->assertSchemaStateSet(['cache_enabled_locked' => false], 'mountedActionSchema0')
        ->assertFormFieldHidden('cache_cleanup')
        ->assertFormFieldHidden('cache_failback')
        ->assertMountedActionModalSee('Turn on "Enable cache" in Settings');
});

it('keeps the stored caching options when saved while the cache feature is off', function () {
    arrWidgetCacheSetting(false);
    $this->integration->update(['cache_enabled' => true, 'cache_cleanup' => true, 'cache_failback' => true]);

    Livewire::test(ListArrIntegrations::class)
        ->callAction(TestAction::make('edit')->table($this->integration))
        ->assertHasNoFormErrors();

    $this->integration->refresh();
    expect($this->integration->cache_enabled)->toBeTrue()
        ->and($this->integration->cache_cleanup)->toBeTrue()
        ->and($this->integration->cache_failback)->toBeTrue();
});

it('enables Use for caching in the edit slide-over while the cache feature is on', function () {
    arrWidgetCacheSetting(true);

    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($this->integration))
        ->assertFormFieldEnabled('cache_enabled')
        ->assertFormFieldHidden('cache_enabled_locked');
});

it('shows Remove after leaving dynamic groups on a Radarr used for caching, and saves it', function () {
    arrWidgetCacheSetting(true);

    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($this->integration))
        ->assertFormFieldHidden('cache_cleanup')
        ->fillForm(['cache_enabled' => true])
        ->assertFormFieldVisible('cache_cleanup')
        ->fillForm(['cache_cleanup' => true])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($this->integration->refresh()->cache_cleanup)->toBeTrue();
});

it('hides Remove after leaving dynamic groups on Sonarr', function () {
    arrWidgetCacheSetting(true);
    $sonarr = ArrIntegration::factory()->sonarr()->cacheEnabled()->create(['user_id' => $this->user->id]);

    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($sonarr))
        ->assertFormFieldHidden('cache_cleanup');
});
