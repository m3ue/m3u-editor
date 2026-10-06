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

it('disables Use for caching in the edit slide-over while the cache feature is off', function () {
    arrWidgetCacheSetting(false);

    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($this->integration))
        ->assertFormFieldDisabled('cache_enabled')
        ->assertMountedActionModalSee('Turn on "Enable cache" in Settings');
});

it('enables Use for caching in the edit slide-over while the cache feature is on', function () {
    arrWidgetCacheSetting(true);

    Livewire::test(ListArrIntegrations::class)
        ->mountAction(TestAction::make('edit')->table($this->integration))
        ->assertFormFieldEnabled('cache_enabled');
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
