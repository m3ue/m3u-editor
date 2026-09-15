<?php

use App\Filament\Clusters\Settings\Pages\ManageIntegrationSettings;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Admin panel requires an admin user (User::factory()->admin() state).
    // Mirrors the pattern in tests/Feature/PreferencesTooltipTranslationTest.php.
    $this->actingAs(User::factory()->admin()->create());

    // Phase 1 finding: an earlier settings migration resolves GeneralSettings
    // mid-migration, freezing a stale payload. Re-read so the migrated defaults
    // are present when the form is loaded.
    app(GeneralSettings::class)->refresh();
});

it('renders the Dynamic Group Cache section with all expected fields', function () {
    Livewire::test(ManageIntegrationSettings::class, ['tab' => 'dynamic-group-cache'])
        ->assertFormFieldExists('enable_dynamic_group_cache')
        ->assertFormFieldExists('dynamic_group_cache_location')
        ->assertFormFieldExists('dynamic_group_cache_lazy_load')
        ->assertFormFieldExists('dynamic_group_cache_schedule')
        ->assertFormFieldExists('dynamic_group_cache_max_concurrent_downloads')
        ->assertFormFieldExists('dynamic_group_cache_retry_cooldown_minutes')
        ->assertFormFieldExists('dynamic_group_cache_failure_cooldown_hours');
});

it('persists Dynamic Group Cache settings on save', function () {
    Livewire::test(ManageIntegrationSettings::class, ['tab' => 'dynamic-group-cache'])
        ->fillForm([
            'enable_dynamic_group_cache' => true,
            'dynamic_group_cache_location' => '/tmp/cache-test',
            'dynamic_group_cache_lazy_load' => true,
            'dynamic_group_cache_max_concurrent_downloads' => 5,
            'dynamic_group_cache_retry_cooldown_minutes' => 120,
            'dynamic_group_cache_failure_cooldown_hours' => 48,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $settings = app(GeneralSettings::class);
    expect($settings->enable_dynamic_group_cache)->toBeTrue()
        ->and($settings->dynamic_group_cache_location)->toBe('/tmp/cache-test')
        ->and($settings->dynamic_group_cache_lazy_load)->toBeTrue()
        ->and($settings->dynamic_group_cache_max_concurrent_downloads)->toBe(5)
        ->and($settings->dynamic_group_cache_retry_cooldown_minutes)->toBe(120)
        ->and($settings->dynamic_group_cache_failure_cooldown_hours)->toBe(48);
});

it('persists the cron schedule when not using lazy_load', function () {
    Livewire::test(ManageIntegrationSettings::class, ['tab' => 'dynamic-group-cache'])
        ->fillForm([
            'enable_dynamic_group_cache' => true,
            'dynamic_group_cache_lazy_load' => false,
            'dynamic_group_cache_schedule' => '0 4 * * *',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->dynamic_group_cache_schedule)->toBe('0 4 * * *');
});

it('rejects an invalid cron schedule with a validation error', function () {
    Livewire::test(ManageIntegrationSettings::class, ['tab' => 'dynamic-group-cache'])
        ->fillForm([
            'enable_dynamic_group_cache' => true,
            'dynamic_group_cache_lazy_load' => false,
            'dynamic_group_cache_schedule' => 'not-a-cron-expression',
        ])
        ->call('save')
        ->assertHasFormErrors(['dynamic_group_cache_schedule']);
});
