<?php

use App\Models\Plugin;
use App\Models\PluginInstallReview;
use App\Plugins\PluginManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('plugins.clamav.driver', 'fake');
    config()->set('plugins.install_mode', 'normal');
});

function generatedPluginRoot(): string
{
    return (string) (collect(config('plugins.directories', [base_path('plugins')]))->first() ?: base_path('plugins'));
}

function generatedPluginPath(string $pluginId): string
{
    return rtrim(generatedPluginRoot(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$pluginId;
}

function cleanupGeneratedPlugin(string $pluginId): void
{
    $pluginManager = app(PluginManager::class);
    $plugin = $pluginManager->findPluginById($pluginId);

    if ($plugin && ! $plugin->hasActiveRuns()) {
        $pluginManager->forgetRegistryRecord($plugin);
    }

    Plugin::query()->where('plugin_id', $pluginId)->delete();
    File::deleteDirectory(generatedPluginPath($pluginId));
}

function createPluginZipArchive(string $sourcePath, string $archivePath): void
{
    $zip = new ZipArchive;
    $opened = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($opened !== true) {
        throw new RuntimeException("Unable to create zip archive [{$archivePath}].");
    }

    $baseLength = strlen(rtrim($sourcePath, DIRECTORY_SEPARATOR)) + 1;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourcePath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $file) {
        $localName = substr($file->getPathname(), $baseLength);

        if ($file->isDir()) {
            $zip->addEmptyDir($localName);

            continue;
        }

        $zip->addFile($file->getPathname(), $localName);
    }

    $zip->close();
}

it('scaffolds a valid local plugin with optional capabilities, hooks, and lifecycle support', function () {
    $name = 'Acme XML Tools '.Str::upper(Str::random(4));
    $pluginId = Str::slug($name);
    $classSegment = Str::studly(Str::of($name)->replace(['-', '_'], ' ')->squish()->value());
    $pluginPath = generatedPluginPath($pluginId);

    cleanupGeneratedPlugin($pluginId);

    try {
        $this->artisan('make:plugin', [
            'name' => $name,
            '--capability' => ['channel_processor', 'scheduled'],
            '--hook' => ['playlist.synced'],
            '--lifecycle' => true,
        ])->assertSuccessful()
            ->expectsOutputToContain("Created plugin [{$pluginId}]");

        expect(File::exists($pluginPath.'/plugin.json'))->toBeTrue();
        expect(File::exists($pluginPath.'/Plugin.php'))->toBeTrue();
        expect(File::exists($pluginPath.'/README.md'))->toBeTrue();
        expect(File::exists($pluginPath.'/AGENTS.md'))->toBeTrue();
        expect(File::exists($pluginPath.'/CLAUDE.md'))->toBeTrue();
        expect(File::exists($pluginPath.'/.github/workflows/plugin-ci.yml'))->toBeTrue();
        expect(File::exists($pluginPath.'/scripts/package-plugin.sh'))->toBeTrue();
        expect(File::exists($pluginPath.'/scripts/validate-plugin.php'))->toBeTrue();

        $manifest = json_decode(File::get($pluginPath.'/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
        $pluginSource = File::get($pluginPath.'/Plugin.php');
        $readme = File::get($pluginPath.'/README.md');
        $workflow = File::get($pluginPath.'/.github/workflows/plugin-ci.yml');

        expect($manifest['id'])->toBe($pluginId);
        expect($manifest['name'])->toBe(Str::of($name)->replace(['-', '_'], ' ')->squish()->title()->value());
        expect($manifest['class'])->toBe("AppLocalPlugins\\{$classSegment}\\Plugin");
        expect($manifest['capabilities'])->toBe(['channel_processor', 'scheduled']);
        expect($manifest['hooks'])->toBe(['playlist.synced']);
        expect($manifest['permissions'])->toContain('queue_jobs', 'filesystem_write', 'hook_subscriptions', 'scheduled_runs');
        expect(data_get($manifest, 'schema.tables'))->toBe([]);
        expect(data_get($manifest, 'data_ownership.directories'))->toContain("plugin-data/{$pluginId}");
        expect(data_get($manifest, 'data_ownership.directories'))->toContain("plugin-reports/{$pluginId}");
        expect(data_get($manifest, 'settings.0.id'))->toBe('schedule_enabled');
        expect(data_get($manifest, 'actions.0.id'))->toBe('health_check');

        expect($pluginSource)->toContain('implements PluginInterface, ChannelProcessorPluginInterface, ScheduledPluginInterface, HookablePluginInterface, LifecyclePluginInterface');
        expect($pluginSource)->toContain('public function runHook');
        expect($pluginSource)->toContain('public function scheduledActions');
        expect($pluginSource)->toContain('public function uninstall');
        expect($readme)->toContain($pluginId);
        expect($workflow)->toContain('actions/upload-artifact@v4');
        expect($workflow)->toContain($pluginId.'-artifact');

        $this->artisan('plugins:discover')
            ->assertSuccessful()
            ->expectsOutputToContain($pluginId);

        $this->artisan('plugins:validate', [
            'pluginId' => $pluginId,
        ])->assertSuccessful()
            ->expectsOutputToContain("{$pluginId}: valid");

        $plugin = app(PluginManager::class)->findPluginById($pluginId);

        expect($plugin)->not->toBeNull();
        expect($plugin->validation_status)->toBe('valid');
        expect($plugin->trust_state)->toBe('pending_review');

        $review = app(PluginManager::class)->stageDirectoryReview($pluginPath);
        app(PluginManager::class)->scanInstallReview($review);
        app(PluginManager::class)->approveInstallReview($review, false);

        $plugin = app(PluginManager::class)->trust($plugin->fresh());
        $plugin->update(['enabled' => true]);

        $run = app(PluginManager::class)->executeAction($plugin->fresh(), 'health_check', [
            'source' => 'test-suite',
        ], [
            'trigger' => 'manual',
            'dry_run' => true,
        ]);

        expect($run->status)->toBe('completed');
        expect($run->summary)->toContain('Health check completed');
        expect(data_get($run->result, 'data.plugin_id'))->toBe($pluginId);
    } finally {
        cleanupGeneratedPlugin($pluginId);
    }
});

it('can generate a bare scaffold without starter kit files', function () {
    $name = 'Bare Scaffold '.Str::upper(Str::random(4));
    $pluginId = Str::slug($name);
    $pluginPath = generatedPluginPath($pluginId);

    cleanupGeneratedPlugin($pluginId);

    try {
        $this->artisan('make:plugin', [
            'name' => $name,
            '--bare' => true,
        ])->assertSuccessful();

        expect(File::exists($pluginPath.'/plugin.json'))->toBeTrue();
        expect(File::exists($pluginPath.'/Plugin.php'))->toBeTrue();
        expect(File::exists($pluginPath.'/README.md'))->toBeFalse();
        expect(File::exists($pluginPath.'/AGENTS.md'))->toBeFalse();
        expect(File::exists($pluginPath.'/.github/workflows/plugin-ci.yml'))->toBeFalse();
    } finally {
        cleanupGeneratedPlugin($pluginId);
    }
});

it('stages, scans, and installs a generated plugin from an archive review', function () {
    $name = 'Archive Review '.Str::upper(Str::random(4));
    $pluginId = Str::slug($name);
    $pluginPath = generatedPluginPath($pluginId);
    $archivePath = storage_path('app/testing-plugin-archives/'.$pluginId.'.zip');

    cleanupGeneratedPlugin($pluginId);
    File::ensureDirectoryExists(dirname($archivePath));

    try {
        $this->artisan('make:plugin', [
            'name' => $name,
        ])->assertSuccessful();

        createPluginZipArchive($pluginPath, $archivePath);

        $review = app(PluginManager::class)->stageArchiveReview($archivePath);

        expect($review->plugin_id)->toBe($pluginId);
        expect($review->validation_status)->toBe('valid');

        $review = app(PluginManager::class)->scanInstallReview($review);
        expect($review->scan_status)->toBe('clean');

        $review = app(PluginManager::class)->approveInstallReview($review, true);

        expect($review->status)->toBe('installed');
        expect($review->plugin()->first())->not->toBeNull();
        expect($review->plugin()->first()?->trust_state)->toBe('trusted');

        $plugin = app(PluginManager::class)->findPluginById($pluginId);
        $plugin?->update(['enabled' => true]);

        $run = app(PluginManager::class)->executeAction($plugin->fresh(), 'health_check', [
            'source' => 'archive-review',
        ], [
            'trigger' => 'manual',
            'dry_run' => true,
        ]);

        expect($run->status)->toBe('completed');
        expect(data_get($run->result, 'data.plugin_id'))->toBe($pluginId);
        expect(PluginInstallReview::query()->whereKey($review->id)->exists())->toBeTrue();
    } finally {
        File::delete($archivePath);
        cleanupGeneratedPlugin($pluginId);
    }
});

it('refuses to overwrite an existing plugin directory without force', function () {
    $name = 'Overwrite Guard '.Str::upper(Str::random(4));
    $pluginId = Str::slug($name);
    $pluginPath = generatedPluginPath($pluginId);

    cleanupGeneratedPlugin($pluginId);

    try {
        $this->artisan('make:plugin', [
            'name' => $name,
        ])->assertSuccessful();

        File::put($pluginPath.'/marker.txt', 'keep-me');

        $this->artisan('make:plugin', [
            'name' => $name,
        ])->assertFailed()
            ->expectsOutputToContain('already exists');

        expect(File::exists($pluginPath.'/marker.txt'))->toBeTrue();
    } finally {
        cleanupGeneratedPlugin($pluginId);
    }
});

it('rejects unknown capabilities before writing any files', function () {
    $name = 'Invalid Capability '.Str::upper(Str::random(4));
    $pluginId = Str::slug($name);
    $pluginPath = generatedPluginPath($pluginId);

    cleanupGeneratedPlugin($pluginId);

    $this->artisan('make:plugin', [
        'name' => $name,
        '--capability' => ['not-real'],
    ])->assertFailed()
        ->expectsOutputToContain('Unknown capability value(s)');

    expect(File::exists($pluginPath))->toBeFalse();
    expect(Plugin::query()->where('plugin_id', $pluginId)->exists())->toBeFalse();
});
