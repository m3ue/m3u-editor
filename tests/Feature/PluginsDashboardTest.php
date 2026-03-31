<?php

use App\Filament\Pages\PluginsDashboard;
use App\Filament\Resources\PluginInstallReviews\Pages\ListPluginInstallReviews;
use App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource;
use App\Filament\Resources\Plugins\Pages\EditPlugin;
use App\Filament\Resources\Plugins\Pages\ListPlugins;
use App\Filament\Resources\Plugins\PluginResource;
use App\Models\Plugin;
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
function adminUserForPluginsTests(): User
{
    return User::factory()->admin()->create([
        'email' => 'plugins-admin-'.Str::lower(Str::random(8)).'@example.com',
    ]);
}

/**
 * Seed a minimal plugin row for dashboard rendering tests.
 *
 * @param  array<string, mixed>  $overrides
 */
function createPluginForDashboardTests(string $name, array $overrides = []): Plugin
{
    $pluginId = Str::slug($name).'-'.Str::lower(Str::random(4));

    return Plugin::query()->create(array_merge([
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
function createMissingManifestArchiveForPluginsTests(): string
{
    $directory = storage_path('app/testing-plugin-archives/plugins-dashboard');
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

it('renders the plugins dashboard with health cards, quick actions, and install queue data', function () {
    $admin = adminUserForPluginsTests();
    $this->actingAs($admin);

    createPluginForDashboardTests('Trusted Plugin', [
        'trust_state' => 'trusted',
        'integrity_status' => 'verified',
    ]);

    createPluginForDashboardTests('Pending Review Plugin', [
        'trust_state' => 'pending_review',
        'integrity_status' => 'changed',
    ]);

    createPluginInstallForDashboardTests('Queued Upload Install', $admin->id);

    Livewire::test(PluginsDashboard::class)
        ->assertOk()
        ->assertSee('Installed Plugins')
        ->assertSee('Trusted Plugins')
        ->assertSee('Pending Plugin Installs')
        ->assertSee('Plugins Needing Attention')
        ->assertSee('Upload Plugin Archive')
        ->assertSee('Trusted Plugin')
        ->assertSee('Pending Review Plugin')
        ->assertSee('Queued Upload Install');
});

it('renames the plugin navigation surfaces to plugins and plugin installs', function () {
    expect(PluginResource::getNavigationLabel())->toBe('Plugins');
    expect(PluginInstallReviewResource::getNavigationLabel())->toBe('Installs');
    expect(PluginResource::getNavigationGroup())->toBe('Plugins');
    expect(PluginInstallReviewResource::getNavigationGroup())->toBe('Plugins');
});

it('shows the plugin installs action from the plugins list page', function () {
    $admin = adminUserForPluginsTests();
    $this->actingAs($admin);

    Livewire::test(ListPlugins::class)
        ->assertOk()
        ->assertSee('Installs')
        ->assertSee('Discover Plugins');
});

it('converts staging exceptions into user-facing notifications on the install list page', function () {
    $admin = adminUserForPluginsTests();
    $this->actingAs($admin);
    $archivePath = createMissingManifestArchiveForPluginsTests();

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

it('confirms plugin management is admin-only', function () {
    $admin = adminUserForPluginsTests();
    $nonAdmin = User::factory()->create([
        'email' => 'non-admin-'.Str::lower(Str::random(8)).'@example.com',
        'permissions' => ['use_tools'],
    ]);

    expect($admin->canManagePlugins())->toBeTrue();
    expect($nonAdmin->canManagePlugins())->toBeFalse();
});

it('blocks non-admin users from accessing the plugin install reviews resource', function () {
    $nonAdmin = User::factory()->create([
        'email' => 'non-admin-'.Str::lower(Str::random(8)).'@example.com',
        'permissions' => ['use_tools'],
    ]);

    $this->actingAs($nonAdmin);

    expect(PluginInstallReviewResource::canAccess())->toBeFalse();
});

it('allows admin users to access the plugin install reviews resource', function () {
    $admin = adminUserForPluginsTests();

    $this->actingAs($admin);

    expect(PluginInstallReviewResource::canAccess())->toBeTrue();
});

it('allows tool users to access the plugins resource but not install reviews', function () {
    $nonAdmin = User::factory()->create([
        'email' => 'tool-user-'.Str::lower(Str::random(8)).'@example.com',
        'permissions' => ['use_tools'],
    ]);

    $this->actingAs($nonAdmin);

    expect(PluginResource::canAccess())->toBeTrue();
    expect(PluginInstallReviewResource::canAccess())->toBeFalse();
});

it('blocks non-admin users from reaching the install reviews list page', function () {
    $nonAdmin = User::factory()->create([
        'email' => 'non-admin-list-'.Str::lower(Str::random(8)).'@example.com',
        'permissions' => ['use_tools'],
    ]);

    $this->actingAs($nonAdmin);

    Livewire::test(ListPluginInstallReviews::class)
        ->assertForbidden();
});

it('blocks non-admin users from editing plugin settings via the edit page save', function () {
    $admin = adminUserForPluginsTests();
    $nonAdmin = User::factory()->create([
        'email' => 'non-admin-edit-'.Str::lower(Str::random(8)).'@example.com',
        'permissions' => ['use_tools'],
    ]);

    $plugin = createPluginForDashboardTests('Settings Guard Plugin');

    // Admin can load the edit page without error
    $this->actingAs($admin);
    Livewire::test(EditPlugin::class, ['record' => $plugin->getKey()])
        ->assertOk();

    // Non-admin gets 403 when trying to save settings
    $this->actingAs($nonAdmin);
    Livewire::test(EditPlugin::class, ['record' => $plugin->getKey()])
        ->call('save')
        ->assertForbidden();
});
