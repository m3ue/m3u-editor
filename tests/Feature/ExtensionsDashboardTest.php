<?php

use App\Filament\Pages\ExtensionsDashboard;
use App\Filament\Resources\ExtensionPlugins\ExtensionPluginResource;
use App\Filament\Resources\ExtensionPlugins\Pages\ListExtensionPlugins;
use App\Filament\Resources\PluginInstallReviews\Pages\ListPluginInstallReviews;
use App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource;
use App\Models\ExtensionPlugin;
use App\Models\PluginInstallReview;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('plugins.clamav.driver', 'fake');
    config()->set('plugins.install_mode', 'normal');
});

it('honors testing cache and session overrides', function () {
    expect(config('cache.default'))->toBe('array');
    expect(config('session.driver'))->toBe('array');
});

/**
 * Create an admin user for plugin dashboard and install management tests.
 */
function adminUserForExtensionsTests(): User
{
    $user = User::factory()->create([
        'email' => 'extensions-admin-'.Str::lower(Str::random(8)).'@example.com',
    ]);

    config()->set('dev.admin_emails', [$user->email]);

    return $user;
}

/**
 * Seed a minimal extension plugin row for dashboard rendering tests.
 *
 * @param  array<string, mixed>  $overrides
 */
function createExtensionForDashboardTests(string $name, array $overrides = []): ExtensionPlugin
{
    $pluginId = Str::slug($name).'-'.Str::lower(Str::random(4));

    return ExtensionPlugin::query()->create(array_merge([
        'plugin_id' => $pluginId,
        'name' => $name,
        'version' => '1.0.0',
        'api_version' => '1.0.0',
        'description' => 'Dashboard fixture plugin.',
        'entrypoint' => 'Plugin.php',
        'class_name' => 'AppLocalPlugins\\'.Str::studly($pluginId).'\\Plugin',
        'capabilities' => [],
        'hooks' => [],
        'permissions' => [],
        'schema_definition' => ['tables' => []],
        'actions' => [],
        'settings_schema' => [],
        'settings' => [],
        'data_ownership' => ['tables' => [], 'directories' => [], 'files' => []],
        'source_type' => 'local_directory',
        'path' => storage_path('app/testing-plugin-sources/'.$pluginId),
        'available' => true,
        'enabled' => false,
        'installation_status' => 'installed',
        'trust_state' => 'pending_review',
        'validation_status' => 'valid',
        'integrity_status' => 'unknown',
    ], $overrides));
}

/**
 * Seed a minimal plugin install row for dashboard queue rendering tests.
 *
 * @param  array<string, mixed>  $overrides
 */
function createPluginInstallForDashboardTests(string $pluginId, int $userId, array $overrides = []): PluginInstallReview
{
    return PluginInstallReview::query()->create(array_merge([
        'plugin_id' => Str::slug($pluginId),
        'plugin_name' => $pluginId,
        'plugin_version' => '1.0.0',
        'api_version' => '1.0.0',
        'source_type' => 'uploaded_archive',
        'source_path' => 'browser-upload://'.$pluginId.'.zip',
        'source_origin' => 'browser_upload',
        'source_metadata' => ['uploaded_filename' => $pluginId.'.zip'],
        'archive_filename' => $pluginId.'.zip',
        'status' => 'staged',
        'validation_status' => 'pending',
        'scan_status' => 'pending',
        'created_by_user_id' => $userId,
    ], $overrides));
}

/**
 * Create a zip archive fixture that is structurally valid but lacks plugin.json.
 */
function createMissingManifestArchiveForExtensionsTests(): string
{
    $directory = storage_path('app/testing-plugin-archives/extensions-dashboard');
    $archivePath = $directory.'/missing-manifest-'.Str::lower(Str::random(6)).'.zip';

    File::ensureDirectoryExists($directory);
    File::delete($archivePath);

    $zip = new ZipArchive;
    $opened = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        throw new RuntimeException("Unable to create test archive [{$archivePath}].");
    }

    $zip->addFromString('README.txt', 'No plugin manifest here.');
    $zip->close();

    return $archivePath;
}

it('renders the extensions dashboard with health cards, quick actions, and install queue data', function () {
    $admin = adminUserForExtensionsTests();
    $this->actingAs($admin);

    createExtensionForDashboardTests('Trusted Extension', [
        'trust_state' => 'trusted',
        'integrity_status' => 'verified',
    ]);

    createExtensionForDashboardTests('Pending Review Extension', [
        'trust_state' => 'pending_review',
        'integrity_status' => 'changed',
    ]);

    createPluginInstallForDashboardTests('Queued Upload Install', $admin->id);

    Livewire::test(ExtensionsDashboard::class)
        ->assertOk()
        ->assertSee('Installed Extensions')
        ->assertSee('Trusted Extensions')
        ->assertSee('Pending Plugin Installs')
        ->assertSee('Extensions Needing Attention')
        ->assertSee('Upload Plugin Archive')
        ->assertSee('Trusted Extension')
        ->assertSee('Pending Review Extension')
        ->assertSee('Queued Upload Install');
});

it('renames the plugin navigation surfaces to extensions and plugin installs', function () {
    $extensionDefaults = (new ReflectionClass(ExtensionPluginResource::class))->getDefaultProperties();
    $installDefaults = (new ReflectionClass(PluginInstallReviewResource::class))->getDefaultProperties();

    expect(ExtensionPluginResource::getNavigationLabel())->toBe('Extensions');
    expect(PluginInstallReviewResource::getNavigationLabel())->toBe('Plugin Installs');
    expect($extensionDefaults['navigationGroup'])->toBe('Extensions');
    expect($installDefaults['navigationGroup'])->toBe('Extensions');
});

it('shows the plugin installs action from the extensions list page', function () {
    $admin = adminUserForExtensionsTests();
    $this->actingAs($admin);

    Livewire::test(ListExtensionPlugins::class)
        ->assertOk()
        ->assertSee('Plugin Installs')
        ->assertSee('Discover Plugins');
});

it('converts staging exceptions into user-facing notifications on the install list page', function () {
    $admin = adminUserForExtensionsTests();
    $this->actingAs($admin);
    $archivePath = createMissingManifestArchiveForExtensionsTests();

    try {
        $beforeCount = PluginInstallReview::query()->count();

        Livewire::test(ListPluginInstallReviews::class)
            ->call('mountAction', 'stage_archive')
            ->set('mountedActions.0.data.archive', $archivePath)
            ->call('callMountedAction')
            ->assertHasNoActionErrors();

        expect(PluginInstallReview::query()->count())->toBe($beforeCount);
    } finally {
        File::delete($archivePath);
    }
});
