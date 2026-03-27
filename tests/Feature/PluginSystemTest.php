<?php

use App\Filament\Resources\Plugins\Pages\ViewPluginRun;
use App\Jobs\ExecutePluginInvocation;
use App\Models\Plugin;
use App\Models\PluginInstallReview;
use App\Models\PluginRun;
use App\Models\User;
use App\Plugins\PluginManager;
use App\Plugins\PluginValidator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('plugins.clamav.driver', 'fake');
    config()->set('plugins.install_mode', 'normal');
});

function approvePluginReviewForTests(string $sourcePath, bool $trust = true, bool $devSource = false): PluginInstallReview
{
    $pluginManager = app(PluginManager::class);
    $review = $pluginManager->stageDirectoryReview($sourcePath, null, $devSource);
    $review = $pluginManager->scanInstallReview($review);

    return $pluginManager->approveInstallReview($review, $trust);
}

function pluginReviewFixturePaths(string $pluginId): array
{
    return [
        'source' => storage_path('app/testing-plugin-sources/'.$pluginId),
        'archive' => storage_path('app/testing-plugin-archives/'.$pluginId.'.zip'),
        'sentinel' => storage_path('app/testing-plugin-sentinels/'.$pluginId.'.txt'),
    ];
}

function pluginReviewFixtureClassName(string $pluginId): string
{
    return 'AppLocalPlugins\\'.Str::studly(str_replace('-', ' ', $pluginId)).'\\Plugin';
}

function createReviewFixturePlugin(string $pluginId, bool $withSideEffect = false, array $manifestOverrides = []): array
{
    $paths = pluginReviewFixturePaths($pluginId);
    $classSegment = Str::studly(str_replace('-', ' ', $pluginId));

    File::deleteDirectory($paths['source']);
    File::ensureDirectoryExists($paths['source']);
    File::ensureDirectoryExists(dirname($paths['sentinel']));
    File::delete($paths['sentinel']);

    $manifest = array_replace_recursive([
        'id' => $pluginId,
        'name' => Str::title(str_replace('-', ' ', $pluginId)),
        'version' => '0.1.0',
        'description' => 'Temporary test plugin fixture.',
        'api_version' => config('plugins.api_version'),
        'entrypoint' => 'Plugin.php',
        'class' => "AppLocalPlugins\\{$classSegment}\\Plugin",
        'capabilities' => [],
        'hooks' => [],
        'permissions' => [],
        'settings' => [],
        'actions' => [],
        'schema' => [
            'tables' => [],
        ],
        'data_ownership' => [
            'plugin_id' => $pluginId,
            'table_prefix' => 'plugin_'.str_replace('-', '_', $pluginId).'_',
            'tables' => [],
            'directories' => [],
            'files' => [],
            'default_cleanup_policy' => 'preserve',
        ],
    ], $manifestOverrides);

    $sideEffect = $withSideEffect
        ? "\nfile_put_contents(".var_export($paths['sentinel'], true).", 'executed');\n"
        : "\n";

    $pluginSource = <<<PHP
<?php

namespace AppLocalPlugins\\{$classSegment};

use App\\Plugins\\Contracts\\PluginInterface;
use App\\Plugins\\Support\\PluginActionResult;
use App\\Plugins\\Support\\PluginExecutionContext;{$sideEffect}
class Plugin implements PluginInterface
{
    public function runAction(string \$action, array \$payload, PluginExecutionContext \$context): PluginActionResult
    {
        return PluginActionResult::success('Fixture plugin action completed.', [
            'action' => \$action,
        ]);
    }
}
PHP;

    File::put(
        $paths['source'].'/plugin.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
    );
    File::put($paths['source'].'/Plugin.php', $pluginSource);

    return $paths;
}

function installFixturePluginForTests(string $pluginId, bool $trust = true, bool $enabled = false, array $manifestOverrides = []): array
{
    $paths = createReviewFixturePlugin($pluginId, manifestOverrides: $manifestOverrides);
    $review = approvePluginReviewForTests($paths['source'], $trust);
    $plugin = app(PluginManager::class)->findPluginById($pluginId);

    if ($enabled && $plugin) {
        $plugin->update(['enabled' => true]);
        $plugin = $plugin->fresh();
    }

    return [
        'paths' => $paths,
        'review' => $review,
        'plugin' => $plugin?->fresh(),
    ];
}

function createZipArchiveForTests(string $sourcePath, string $archivePath, array $extraEntries = []): void
{
    File::delete($archivePath);
    File::ensureDirectoryExists(dirname($archivePath));

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

    foreach ($extraEntries as $localName => $contents) {
        $zip->addFromString($localName, $contents);
    }

    $zip->close();
}

function createGitHubReleaseUrlForTests(string $pluginId): string
{
    return "https://github.com/example/{$pluginId}-plugin/releases/download/v1.0.0/{$pluginId}.zip";
}

function storeUploadedArchiveForTests(string $pluginId, string $archivePath): string
{
    $relativePath = trim((string) config('plugins.upload_directory', 'plugin-review-uploads'), '/').'/'.$pluginId.'.zip';

    Storage::disk('local')->delete($relativePath);
    Storage::disk('local')->put($relativePath, File::get($archivePath));

    return $relativePath;
}

function cleanupReviewFixturePlugin(string $pluginId): void
{
    $pluginManager = app(PluginManager::class);
    $plugin = $pluginManager->findPluginById($pluginId);

    if ($plugin && ! $plugin->hasActiveRuns()) {
        $pluginManager->forgetRegistryRecord($plugin);
    }

    $reviews = PluginInstallReview::query()
        ->where('plugin_id', $pluginId)
        ->get();

    foreach ($reviews as $review) {
        if ($review->staging_path && is_dir($review->staging_path)) {
            File::deleteDirectory($review->staging_path);
        }
    }

    PluginInstallReview::query()->where('plugin_id', $pluginId)->delete();
    Plugin::query()->where('plugin_id', $pluginId)->delete();

    foreach (config('plugins.directories', [base_path('plugins')]) as $directory) {
        File::deleteDirectory(rtrim((string) $directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$pluginId);
    }

    $paths = pluginReviewFixturePaths($pluginId);

    File::deleteDirectory($paths['source']);
    File::delete($paths['archive']);
    File::delete($paths['sentinel']);
    Storage::disk('local')->delete(trim((string) config('plugins.upload_directory', 'plugin-review-uploads'), '/').'/'.$pluginId.'.zip');
}

it('discovers and validates a generated local plugin fixture', function () {
    $pluginId = 'discover-fixture-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    $discoverablePath = base_path('plugins/'.$pluginId);

    try {
        File::deleteDirectory($discoverablePath);
        File::copyDirectory($paths['source'], $discoverablePath);

        $plugins = app(PluginManager::class)->discover();
        $plugin = collect($plugins)->firstWhere('plugin_id', $pluginId);

        expect($plugin)->not->toBeNull();
        expect($plugin->validation_status)->toBe('valid');
        expect($plugin->available)->toBeTrue();
        expect($plugin->trust_state)->toBe('pending_review');
        expect($plugin->integrity_status)->toBe('unknown');

        $validated = app(PluginManager::class)->validate($plugin);

        expect($validated->validation_status)->toBe('valid');
        expect($validated->validation_errors)->toBe([]);
    } finally {
        File::deleteDirectory($discoverablePath);
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('rejects top-level executable plugin php while staging a directory review', function () {
    $pluginId = 'review-safety-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId, withSideEffect: true);
    $className = pluginReviewFixtureClassName($pluginId);

    try {
        $review = app(PluginManager::class)->stageDirectoryReview($paths['source']);

        expect($review->validation_status)->toBe('invalid');
        expect(File::exists($paths['sentinel']))->toBeFalse();
        expect(class_exists($className, false))->toBeFalse();
        expect($review->validation_errors)->not->toBeEmpty();
        expect(collect($review->validation_errors)->join(' '))->toContain('top-level executable code');
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('rejects top-level executable plugin php while staging an archive review', function () {
    $pluginId = 'archive-safety-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId, withSideEffect: true);
    createZipArchiveForTests($paths['source'], $paths['archive']);
    $className = pluginReviewFixtureClassName($pluginId);

    try {
        $review = app(PluginManager::class)->stageArchiveReview($paths['archive']);

        expect($review->validation_status)->toBe('invalid');
        expect(File::exists($paths['sentinel']))->toBeFalse();
        expect(class_exists($className, false))->toBeFalse();
        expect($review->validation_errors)->not->toBeEmpty();
        expect(collect($review->validation_errors)->join(' '))->toContain('top-level executable code');
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('rejects archive entries that try to escape the staging root', function () {
    $pluginId = 'archive-escape-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    createZipArchiveForTests($paths['source'], $paths['archive'], [
        '../escape.txt' => 'nope',
    ]);

    try {
        expect(fn () => app(PluginManager::class)->stageArchiveReview($paths['archive']))
            ->toThrow(RuntimeException::class, 'unsafe path entry');
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('cleans staged archive reviews when archive extraction fails before refresh', function () {
    $pluginId = 'archive-missing-manifest-'.Str::lower(Str::random(6));
    $archivePath = storage_path('app/testing-plugin-archives/'.$pluginId.'.zip');

    File::delete($archivePath);
    File::ensureDirectoryExists(dirname($archivePath));

    $zip = new ZipArchive;
    $opened = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        throw new RuntimeException("Unable to create zip archive [{$archivePath}].");
    }

    $zip->addFromString('README.txt', 'No plugin manifest here.');
    $zip->close();

    try {
        expect(fn () => app(PluginManager::class)->stageArchiveReview($archivePath))
            ->toThrow(RuntimeException::class, 'exactly one plugin.json manifest');

        expect(PluginInstallReview::query()->where('source_path', $archivePath)->exists())->toBeFalse();
    } finally {
        File::delete($archivePath);
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('cleans staged directory reviews when refresh fails after staging starts', function () {
    $pluginId = 'directory-stage-fail-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    $stagingDirectory = config('plugins.staging_directory');
    $stagingPattern = rtrim((string) $stagingDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'review-*';
    $stagingDirectoriesBefore = File::glob($stagingPattern) ?: [];
    $validator = Mockery::mock(PluginValidator::class);
    $validator->shouldReceive('validatePath')
        ->once()
        ->andThrow(new RuntimeException('validation exploded'));

    app()->instance(PluginValidator::class, $validator);
    app()->forgetInstance(PluginManager::class);

    try {
        expect(fn () => app(PluginManager::class)->stageDirectoryReview($paths['source']))
            ->toThrow(RuntimeException::class, 'validation exploded');

        expect(PluginInstallReview::query()->where('source_path', $paths['source'])->exists())->toBeFalse();
        expect(File::glob($stagingPattern) ?: [])->toEqualCanonicalizing($stagingDirectoriesBefore);
    } finally {
        cleanupReviewFixturePlugin($pluginId);
        app()->forgetInstance(PluginManager::class);
    }
});

it('discards stale install reviews when staged payload disappears before scan', function () {
    $pluginId = 'stale-scan-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    createZipArchiveForTests($paths['source'], $paths['archive']);

    try {
        $review = app(PluginManager::class)->stageArchiveReview($paths['archive']);

        expect(is_dir((string) $review->staging_path))->toBeTrue();

        File::deleteDirectory((string) $review->staging_path);

        expect(fn () => app(PluginManager::class)->scanInstallReview($review))
            ->toThrow(RuntimeException::class, 'lost its staged plugin files');

        $review = $review->fresh();

        expect($review->status)->toBe('discarded');
        expect($review->scan_status)->toBe('scan_failed');
        expect($review->scan_summary)->toContain('Restage the plugin and try again');
        expect($review->review_notes)->toContain('discarded automatically');
        expect($review->archive_path)->toBeNull();
        expect($review->staging_path)->toBeNull();
        expect($review->extracted_path)->toBeNull();
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('discards stale clean reviews when staged payload disappears before approval', function () {
    $pluginId = 'stale-approve-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    createZipArchiveForTests($paths['source'], $paths['archive']);

    try {
        $review = app(PluginManager::class)->stageArchiveReview($paths['archive']);
        $review = app(PluginManager::class)->scanInstallReview($review);

        File::deleteDirectory((string) $review->staging_path);

        expect(fn () => app(PluginManager::class)->approveInstallReview($review, true))
            ->toThrow(RuntimeException::class, 'lost its staged plugin files');

        $review = $review->fresh();

        expect($review->status)->toBe('discarded');
        expect($review->scan_status)->toBe('scan_failed');
        expect($review->scan_summary)->toContain('Restage the plugin and try again');
        expect($review->archive_path)->toBeNull();
        expect($review->staging_path)->toBeNull();
        expect($review->extracted_path)->toBeNull();
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('stages a GitHub release archive with a pinned checksum', function () {
    $pluginId = 'github-release-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    createZipArchiveForTests($paths['source'], $paths['archive']);
    $releaseUrl = createGitHubReleaseUrlForTests($pluginId);
    $checksum = hash_file('sha256', $paths['archive']);

    Http::fake([
        $releaseUrl => Http::response(File::get($paths['archive']), 200, [
            'Content-Type' => 'application/zip',
        ]),
    ]);

    try {
        $review = app(PluginManager::class)->stageGithubReleaseReview($releaseUrl, (string) $checksum);

        expect($review->source_type)->toBe('github_release');
        expect($review->source_origin)->toBe("example/{$pluginId}-plugin@v1.0.0");
        expect(data_get($review->source_metadata, 'asset_name'))->toBe("{$pluginId}.zip");
        expect($review->expected_archive_sha256)->toBe($checksum);
        expect($review->archive_sha256)->toBe($checksum);
        expect($review->validation_status)->toBe('valid');
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('rejects a GitHub release archive when the pinned checksum does not match', function () {
    $pluginId = 'github-checksum-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    createZipArchiveForTests($paths['source'], $paths['archive']);
    $releaseUrl = createGitHubReleaseUrlForTests($pluginId);

    Http::fake([
        $releaseUrl => Http::response(File::get($paths['archive']), 200, [
            'Content-Type' => 'application/zip',
        ]),
    ]);

    try {
        expect(fn () => app(PluginManager::class)->stageGithubReleaseReview($releaseUrl, str_repeat('a', 64)))
            ->toThrow(RuntimeException::class, 'checksum mismatch');

        expect(PluginInstallReview::query()->where('source_path', $releaseUrl)->exists())->toBeFalse();
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('rejects dev-source reviews outside dev mode', function () {
    $pluginId = 'dev-policy-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);

    try {
        expect(fn () => app(PluginManager::class)->stageDirectoryReview($paths['source'], null, true))
            ->toThrow(RuntimeException::class, 'PLUGIN_INSTALL_MODE=dev');
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('requires dev-source reviews to come from configured dev directories', function () {
    $pluginId = 'dev-dir-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    config()->set('plugins.install_mode', 'dev');
    config()->set('plugins.dev_directories', [storage_path('app/somewhere-else')]);

    try {
        expect(fn () => app(PluginManager::class)->stageDirectoryReview($paths['source'], null, true))
            ->toThrow(RuntimeException::class, 'PLUGIN_DEV_DIRECTORIES');
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('allows dev-source reviews only from configured dev directories in dev mode', function () {
    $pluginId = 'dev-ok-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    config()->set('plugins.install_mode', 'dev');
    config()->set('plugins.dev_directories', [dirname($paths['source'])]);

    try {
        $review = app(PluginManager::class)->stageDirectoryReview($paths['source'], null, true);

        expect($review->source_type)->toBe('local_dev');
        expect($review->validation_status)->toBe('valid');
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('stages an uploaded archive from local storage for install review', function () {
    $pluginId = 'upload-stage-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    createZipArchiveForTests($paths['source'], $paths['archive']);
    $uploadedPath = storeUploadedArchiveForTests($pluginId, $paths['archive']);

    try {
        $review = app(PluginManager::class)->stageUploadedArchiveReview($uploadedPath);

        expect($review->source_type)->toBe('uploaded_archive');
        expect($review->source_origin)->toBe('browser_upload');
        expect(data_get($review->source_metadata, 'upload_path'))->toBe($uploadedPath);
        expect($review->validation_status)->toBe('valid');
        expect($review->archive_sha256)->toBe(hash_file('sha256', $paths['archive']));
        expect(Storage::disk('local')->exists($uploadedPath))->toBeFalse();
        expect(is_file((string) $review->archive_path))->toBeTrue();
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('rejects uploaded archives outside the configured upload directory', function () {
    $pluginId = 'upload-policy-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    createZipArchiveForTests($paths['source'], $paths['archive']);
    $uploadedPath = 'wrong-place/'.$pluginId.'.zip';
    Storage::disk('local')->put($uploadedPath, File::get($paths['archive']));

    try {
        expect(fn () => app(PluginManager::class)->stageUploadedArchiveReview($uploadedPath))
            ->toThrow(RuntimeException::class, 'configured plugin upload directory');
    } finally {
        Storage::disk('local')->delete($uploadedPath);
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('rejects uploaded archives that try to escape the upload directory with dot segments', function () {
    $pluginId = 'upload-dotdot-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    createZipArchiveForTests($paths['source'], $paths['archive']);
    $uploadedPath = trim((string) config('plugins.upload_directory', 'plugin-review-uploads'), '/').'/../wrong-place/'.$pluginId.'.zip';
    File::ensureDirectoryExists(dirname(Storage::disk('local')->path($uploadedPath)));
    File::put(Storage::disk('local')->path($uploadedPath), File::get($paths['archive']));

    try {
        expect(fn () => app(PluginManager::class)->stageUploadedArchiveReview($uploadedPath))
            ->toThrow(RuntimeException::class, 'configured plugin upload directory');
    } finally {
        Storage::disk('local')->delete($uploadedPath);
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('cleans uploaded archive artifacts when staging fails', function () {
    $pluginId = 'upload-fail-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    createZipArchiveForTests($paths['source'], $paths['archive'], [
        '../escape.php' => '<?php echo "bad";',
    ]);
    $uploadedPath = storeUploadedArchiveForTests($pluginId, $paths['archive']);

    try {
        expect(fn () => app(PluginManager::class)->stageUploadedArchiveReview($uploadedPath))
            ->toThrow(RuntimeException::class, 'unsafe path entry');

        expect(Storage::disk('local')->exists($uploadedPath))->toBeFalse();
        expect(PluginInstallReview::query()
            ->where('source_type', 'uploaded_archive')
            ->where('source_path', 'browser-upload://'.$pluginId.'.zip')
            ->exists())->toBeFalse();
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('keeps uploaded archive staging through rejection and removes it on discard', function () {
    $pluginId = 'upload-discard-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    createZipArchiveForTests($paths['source'], $paths['archive']);
    $uploadedPath = storeUploadedArchiveForTests($pluginId, $paths['archive']);

    try {
        $review = app(PluginManager::class)->stageUploadedArchiveReview($uploadedPath);
        $review = app(PluginManager::class)->rejectInstallReview($review);

        expect($review->status)->toBe('rejected');
        expect(is_file((string) $review->archive_path))->toBeTrue();
        expect(is_dir((string) $review->staging_path))->toBeTrue();

        app(PluginManager::class)->discardInstallReview($review);

        expect(is_dir((string) $review->staging_path))->toBeFalse();
        expect($review->fresh()->archive_path)->toBeNull();
        expect($review->fresh()->staging_path)->toBeNull();
        expect($review->fresh()->extracted_path)->toBeNull();
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('cleans uploaded archive staging after a successful install', function () {
    $pluginId = 'upload-install-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    createZipArchiveForTests($paths['source'], $paths['archive']);
    $uploadedPath = storeUploadedArchiveForTests($pluginId, $paths['archive']);

    try {
        $review = app(PluginManager::class)->stageUploadedArchiveReview($uploadedPath);
        $review = app(PluginManager::class)->scanInstallReview($review);
        $review = app(PluginManager::class)->approveInstallReview($review, true);

        expect($review->status)->toBe('installed');
        expect($review->archive_path)->toBeNull();
        expect($review->staging_path)->toBeNull();
        expect($review->extracted_path)->toBeNull();

        $plugin = app(PluginManager::class)->findPluginById($pluginId);

        expect($plugin?->trust_state)->toBe('trusted');
        expect($plugin?->source_type)->toBe('uploaded_archive');
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('requires admin trust before a fixture plugin becomes runnable', function () {
    $pluginId = 'trust-fixture-'.Str::lower(Str::random(6));

    try {
        $installed = installFixturePluginForTests($pluginId, trust: false);
        $plugin = $installed['plugin'];

        expect($plugin)->not->toBeNull();
        expect($plugin->isTrusted())->toBeFalse();
        expect($plugin->hasVerifiedIntegrity())->toBeFalse();
        expect($installed['review']->scan_status)->toBe('clean');

        $trusted = app(PluginManager::class)->trust($plugin->fresh());

        expect($trusted->isTrusted())->toBeTrue();
        expect($trusted->hasVerifiedIntegrity())->toBeTrue();
        expect($trusted->trusted_hashes)->toMatchArray([
            'manifest_hash' => $trusted->manifest_hash,
            'entrypoint_hash' => $trusted->entrypoint_hash,
            'plugin_hash' => $trusted->plugin_hash,
        ]);
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('downgrades trust when a trusted fixture plugin file changes', function () {
    $pluginId = 'tamper-fixture-'.Str::lower(Str::random(6));
    $pluginManager = app(PluginManager::class);

    try {
        $installed = installFixturePluginForTests($pluginId, trust: true);
        $plugin = $installed['plugin'];
        $manifestPath = rtrim((string) $plugin?->path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'plugin.json';
        $originalManifest = File::get($manifestPath);
        $decoded = json_decode($originalManifest, true, flags: JSON_THROW_ON_ERROR);
        $decoded['description'] = 'Tampered during test '.Str::random(6);

        File::put($manifestPath, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $plugin = $pluginManager->verifyIntegrity($plugin->fresh());

        expect($plugin->trust_state)->toBe('pending_review');
        expect($plugin->integrity_status)->toBe('changed');
        expect($plugin->enabled)->toBeFalse();
    } finally {
        if (isset($originalManifest)) {
            File::put($manifestPath, $originalManifest);
        }
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('supports plugin lifecycle and trust commands for a fixture plugin', function () {
    $pluginId = 'cli-fixture-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);

    try {
        $this->artisan('plugins:stage-directory', [
            'path' => $paths['source'],
        ])->assertSuccessful()
            ->expectsOutputToContain('Created install review');

        $review = PluginInstallReview::query()->latest('id')->first();

        expect($review)->not->toBeNull();
        expect($review?->plugin_id)->toBe($pluginId);

        $this->artisan('plugins:scan-install', [
            'reviewId' => $review->id,
        ])->assertSuccessful()
            ->expectsOutputToContain('scan status: clean');

        $this->artisan('plugins:approve-install', [
            'reviewId' => $review->id,
            '--trust' => true,
        ])->assertSuccessful()
            ->expectsOutputToContain("installed plugin [{$pluginId}]");

        $this->artisan('plugins:block', [
            'pluginId' => $pluginId,
        ])->assertSuccessful()
            ->expectsOutputToContain('now blocked');

        $this->artisan('plugins:reinstall', [
            'pluginId' => $pluginId,
        ])->assertSuccessful()
            ->expectsOutputToContain("Plugin [{$pluginId}] reinstalled.");

        $this->artisan('plugins:forget', [
            'pluginId' => $pluginId,
        ])->assertSuccessful()
            ->expectsOutputToContain('registry record deleted');

        expect(Plugin::query()->where('plugin_id', $pluginId)->exists())->toBeFalse();
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('updates an installed fixture plugin from a reviewed archive and keeps it enabled when trusted', function () {
    $pluginId = 'update-fixture-'.Str::lower(Str::random(6));
    $pluginManager = app(PluginManager::class);

    try {
        $installed = installFixturePluginForTests($pluginId, trust: true, enabled: true);
        $archivePath = $installed['paths']['archive'];
        $updatedSourcePath = storage_path('app/testing-plugin-sources/'.$pluginId.'-update');

        File::deleteDirectory($updatedSourcePath);
        File::copyDirectory($installed['paths']['source'], $updatedSourcePath);

        $manifestPath = $updatedSourcePath.'/plugin.json';
        $manifest = json_decode(File::get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $manifest['version'] = '1.0.1';
        $manifest['description'] = 'Reviewed update package for fixture plugin.';
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        createZipArchiveForTests($updatedSourcePath, $archivePath);

        $secondReview = $pluginManager->stageArchiveReview($archivePath);
        $secondReview = $pluginManager->scanInstallReview($secondReview);
        $secondReview = $pluginManager->approveInstallReview($secondReview, true);
        $updatedPlugin = $pluginManager->findPluginById($pluginId);

        expect($secondReview->status)->toBe('installed');
        expect($updatedPlugin?->version)->toBe('1.0.1');
        expect($updatedPlugin?->description)->toBe('Reviewed update package for fixture plugin.');
        expect($updatedPlugin?->trust_state)->toBe('trusted');
        expect($updatedPlugin?->integrity_status)->toBe('verified');
        expect($updatedPlugin?->enabled)->toBeTrue();
    } finally {
        File::deleteDirectory($updatedSourcePath ?? '');
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('supports the reviewed install command flow for GitHub release archives', function () {
    $pluginId = 'github-cli-'.Str::lower(Str::random(6));
    $paths = createReviewFixturePlugin($pluginId);
    createZipArchiveForTests($paths['source'], $paths['archive']);
    $releaseUrl = createGitHubReleaseUrlForTests($pluginId);
    $checksum = hash_file('sha256', $paths['archive']);

    Http::fake([
        $releaseUrl => Http::response(File::get($paths['archive']), 200, [
            'Content-Type' => 'application/zip',
        ]),
    ]);

    try {
        $this->artisan('plugins:stage-github-release', [
            'url' => $releaseUrl,
            '--sha256' => $checksum,
        ])->assertSuccessful()
            ->expectsOutputToContain('Created install review');

        $review = PluginInstallReview::query()->latest('id')->first();

        expect($review)->not->toBeNull();
        expect($review?->plugin_id)->toBe($pluginId);
        expect($review?->source_type)->toBe('github_release');

        $this->artisan('plugins:scan-install', [
            'reviewId' => $review->id,
        ])->assertSuccessful();

        $this->artisan('plugins:approve-install', [
            'reviewId' => $review->id,
            '--trust' => true,
        ])->assertSuccessful();

        $plugin = app(PluginManager::class)->findPluginById($pluginId);

        expect($plugin?->trust_state)->toBe('trusted');
        expect($plugin?->integrity_status)->toBe('verified');
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('reports plugin registry health through the doctor command', function () {
    $pluginId = 'doctor-fixture-'.Str::lower(Str::random(6));

    try {
        installFixturePluginForTests($pluginId, trust: true);

        $this->artisan('plugins:doctor')
            ->assertSuccessful()
            ->expectsOutputToContain('Plugin registry looks healthy.');
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('loads a plugin run detail page inside the plugin resource', function () {
    $pluginId = 'run-page-fixture-'.Str::lower(Str::random(6));
    $installed = installFixturePluginForTests($pluginId, trust: true, enabled: true);
    $plugin = $installed['plugin'];

    try {
        $user = User::factory()->create([
            'permissions' => ['use_tools'],
        ]);

        $this->actingAs($user);

        $run = PluginRun::query()->create([
            'extension_plugin_id' => $plugin->id,
            'user_id' => $user->id,
            'status' => 'running',
            'invocation_type' => 'action',
            'action' => 'scan',
            'trigger' => 'manual',
            'dry_run' => true,
            'payload' => ['playlist_id' => 123],
            'summary' => 'Queued for inspection.',
            'started_at' => now(),
        ]);

        $run->logs()->create([
            'level' => 'info',
            'message' => 'Plugin run started.',
            'context' => ['playlist_id' => 123],
        ]);

        Livewire::test(ViewPluginRun::class, [
            'record' => $plugin->id,
            'run' => $run->id,
        ])
            ->assertOk()
            ->assertSee('Plugin run started.')
            ->assertSee('Queued for inspection.');
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('marks stale runs, supports cancellation requests, and queues resume for stale runs', function () {
    $pluginId = 'stale-run-fixture-'.Str::lower(Str::random(6));
    $pluginManager = app(PluginManager::class);
    $installed = installFixturePluginForTests($pluginId, trust: true, enabled: true);
    $plugin = $installed['plugin'];

    try {
        $user = User::factory()->create([
            'permissions' => ['use_tools'],
        ]);

        $staleRun = PluginRun::query()->create([
            'extension_plugin_id' => $plugin->id,
            'user_id' => $user->id,
            'status' => 'running',
            'invocation_type' => 'action',
            'action' => 'scan',
            'trigger' => 'manual',
            'dry_run' => true,
            'payload' => ['playlist_id' => 123],
            'progress' => 42,
            'progress_message' => 'Still working through checkpoint 3.',
            'last_heartbeat_at' => now()->subMinutes(20),
            'started_at' => now()->subMinutes(25),
            'run_state' => [
                'resume' => [
                    'last_step' => 'checkpoint-3',
                ],
            ],
        ]);

        expect($pluginManager->recoverStaleRuns())->toBe(1);

        $staleRun->refresh();

        expect($staleRun->status)->toBe('stale');
        expect($staleRun->stale_at)->not->toBeNull();
        expect(data_get($staleRun->result, 'status'))->toBe('stale');

        $runningRun = PluginRun::query()->create([
            'extension_plugin_id' => $plugin->id,
            'user_id' => $user->id,
            'status' => 'running',
            'invocation_type' => 'action',
            'action' => 'scan',
            'trigger' => 'manual',
            'dry_run' => true,
            'payload' => ['playlist_id' => 456],
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        $pluginManager->requestCancellation($runningRun, $user->id);
        $runningRun->refresh();

        expect($runningRun->cancel_requested)->toBeTrue();
        expect($runningRun->cancel_requested_at)->not->toBeNull();
        expect($runningRun->progress_message)->toContain('Cancellation requested');

        Queue::fake();

        $pluginManager->resumeRun($staleRun, $user->id);

        Queue::assertPushed(ExecutePluginInvocation::class, function (ExecutePluginInvocation $job) use ($plugin, $staleRun, $user) {
            return $job->pluginId === $plugin->id
                && $job->invocationType === 'action'
                && $job->name === 'scan'
                && $job->options['existing_run_id'] === $staleRun->id
                && $job->options['user_id'] === $user->id;
        });
    } finally {
        cleanupReviewFixturePlugin($pluginId);
    }
});

it('rejects zip archive entries that contain symlinks', function () {
    $pluginId = 'zip-symlink-'.Str::lower(Str::random(6));
    $archivePath = storage_path('app/testing-plugin-archives/'.$pluginId.'-symlink.zip');

    File::delete($archivePath);
    File::ensureDirectoryExists(dirname($archivePath));

    $zip = new ZipArchive;
    if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Unable to create test archive [{$archivePath}].");
    }

    $zip->addFromString('plugin.json', json_encode(['id' => $pluginId]));
    $zip->addFromString('Plugin.php', '<?php // fixture');
    $zip->addFromString('link.php', '/etc/passwd');
    // Mark link.php as a Unix symlink (file type 0xA000)
    $symlinkIndex = $zip->locateName('link.php');
    $zip->setExternalAttributesIndex($symlinkIndex, ZipArchive::OPSYS_UNIX, 0xA000 << 16);
    $zip->close();

    try {
        expect(fn () => app(PluginManager::class)->stageArchiveReview($archivePath))
            ->toThrow(RuntimeException::class, 'symlink entry');
    } finally {
        File::delete($archivePath);
    }
});
