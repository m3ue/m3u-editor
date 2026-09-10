<?php

use App\Filament\Clusters\Settings\Pages\ManageAlertSettings;
use App\Filament\Clusters\Settings\Pages\ManageApiSettings;
use App\Filament\Clusters\Settings\Pages\ManageAssetSettings;
use App\Filament\Clusters\Settings\Pages\ManageBackupSettings;
use App\Filament\Clusters\Settings\Pages\ManageCopilotSettings;
use App\Filament\Clusters\Settings\Pages\ManageGeneralSettings;
use App\Filament\Clusters\Settings\Pages\ManageIntegrationSettings;
use App\Filament\Clusters\Settings\Pages\ManageNavigationSettings;
use App\Filament\Clusters\Settings\Pages\ManageProxySettings;
use App\Filament\Clusters\Settings\Pages\ManageSmtpSettings;
use App\Filament\Clusters\Settings\Pages\ManageSyncSettings;
use App\Filament\Clusters\Settings\Pages\ManageTvAppSettings;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Livewire\Livewire;

$allPages = [
    ManageGeneralSettings::class,
    ManageNavigationSettings::class,
    ManageProxySettings::class,
    ManageTvAppSettings::class,
    ManageSyncSettings::class,
    ManageAssetSettings::class,
    ManageBackupSettings::class,
    ManageSmtpSettings::class,
    ManageApiSettings::class,
    ManageIntegrationSettings::class,
    ManageCopilotSettings::class,
    ManageAlertSettings::class,
];

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('renders every settings sub-page', function (string $page) {
    Livewire::test($page)->assertOk();
})->with($allPages);

it('renders every settings sub-page in a non-English locale', function (string $page) {
    // Regression: a bare, dot-less __() label (e.g. __('Navigation')) collides with a
    // same-named lang/{locale}/{group}.php translation *group* file when no exact JSON
    // translation exists for that locale, returning an array instead of a string and
    // crashing the page. English is unaffected because en.json carries an identity
    // entry for every string; other locales are not guaranteed to.
    app()->setLocale('fr');

    Livewire::test($page)->assertOk();
})->with($allPages);

it('blocks non-admins from settings sub-pages', function () {
    auth()->logout();
    $this->actingAs(User::factory()->create());

    expect(ManageGeneralSettings::canAccess())->toBeFalse();
});

it('saves each page independently without wiping keys owned by other pages', function () {
    $settings = app(GeneralSettings::class);
    $settings->tmdb_rate_limit = 40;
    $settings->save();

    Livewire::test(ManageSmtpSettings::class)
        ->fillForm(['smtp_host' => 'smtp.example.com'])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(ManageAlertSettings::class)
        ->fillForm(['discord_alerts_enabled' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = app(GeneralSettings::class)->refresh();

    expect($fresh->smtp_host)->toBe('smtp.example.com')
        ->and((bool) $fresh->discord_alerts_enabled)->toBeTrue()
        ->and((int) $fresh->tmdb_rate_limit)->toBe(40);
});

it('resolves the custom date format from the virtual fields on the general page', function () {
    Livewire::test(ManageGeneralSettings::class)
        ->fillForm([
            'date_format_preset' => '__custom__',
            'date_format_custom' => 'd/m/Y',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->refresh()->date_format)->toBe('d/m/Y');

    // Virtual fields rehydrate from the stored value on a fresh mount.
    Livewire::test(ManageGeneralSettings::class)
        ->assertFormSet([
            'date_format_preset' => '__custom__',
            'date_format_custom' => 'd/m/Y',
        ]);
});

it('registers the Navigation page in the Settings cluster sub-navigation', function () {
    // SettingsCluster overrides getClusteredComponents() with an explicit list rather than
    // relying on auto-discovery, so every new settings sub-page must be added here or it
    // is unreachable via the Settings sidebar/sub-nav despite still being a valid route.
    expect(SettingsCluster::getClusteredComponents())->toContain(ManageNavigationSettings::class);
});

it('hides the proxy page when the proxy integration is disabled', function () {
    config(['proxy.proxy_integration_enabled' => false]);

    expect(ManageProxySettings::canAccess())->toBeFalse()
        ->and(ManageProxySettings::shouldRegisterNavigation())->toBeFalse();
});

/**
 * Resolve the index (1-based) of the active tab of the first Tabs component
 * on a settings page, given a ?tab= query value.
 */
function activeSettingsTab(string $pageClass, string $tab): int
{
    request()->merge(['tab' => $tab]);

    $page = new $pageClass;
    $schema = $page->form(Schema::make($page));

    $tabs = collect($schema->getComponents())
        ->first(fn ($c) => $c instanceof Tabs);

    return $tabs->getActiveTab();
}

it('opens the Integrations page on the tab named in ?tab=', function () {
    expect(activeSettingsTab(ManageIntegrationSettings::class, 'tmdb'))->toBe(1)
        ->and(activeSettingsTab(ManageIntegrationSettings::class, 'aiostreams'))->toBe(2)
        ->and(activeSettingsTab(ManageIntegrationSettings::class, 'mediaflow'))->toBe(3);
});

it('opens the Alerts page on the tab named in ?tab=', function () {
    expect(activeSettingsTab(ManageAlertSettings::class, 'discord'))->toBe(1)
        ->and(activeSettingsTab(ManageAlertSettings::class, 'slack'))->toBe(2)
        ->and(activeSettingsTab(ManageAlertSettings::class, 'telegram'))->toBe(3);
});

it('deep-links resolve to the tabbed Integrations page', function () {
    expect(ManageIntegrationSettings::getUrl(['tab' => 'mediaflow']))
        ->toContain('/settings/integrations')
        ->toContain('tab=mediaflow');
});
