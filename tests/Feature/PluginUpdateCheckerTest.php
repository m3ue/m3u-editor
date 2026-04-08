<?php

use App\Models\Plugin;
use App\Plugins\PluginUpdateChecker;
use App\Plugins\Support\PluginManifest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('plugins.update_check.enabled', true);
    config()->set('plugins.update_check.github_token', null);
});

/**
 * Create a minimal plugin row with a repository for update check tests.
 *
 * @param  array<string, mixed>  $overrides
 */
function createPluginForUpdateTests(string $name, array $overrides = []): Plugin
{
    $pluginId = Str::slug($name).'-'.Str::lower(Str::random(4));

    return Plugin::query()->create(array_merge([
        'plugin_id' => $pluginId,
        'name' => $name,
        'version' => '1.0.0',
        'api_version' => '1.0.0',
        'description' => 'Update checker test fixture.',
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
        'repository' => 'test-owner/test-plugin',
        'update_check_enabled' => true,
    ], $overrides));
}

/**
 * Build a fake GitHub release response payload.
 *
 * @param  array<int, array<string, mixed>>  $assets
 * @return array<string, mixed>
 */
function fakeGitHubRelease(string $tagName = 'v2.0.0', array $assets = [], string $body = ''): array
{
    return [
        'tag_name' => $tagName,
        'name' => "Release {$tagName}",
        'published_at' => '2026-04-01T12:00:00Z',
        'html_url' => "https://github.com/test-owner/test-plugin/releases/tag/{$tagName}",
        'body' => $body,
        'assets' => $assets,
    ];
}

it('detects an update when the latest version is newer', function () {
    $plugin = createPluginForUpdateTests('Update Available', [
        'version' => '1.0.0',
        'repository' => 'test-owner/test-plugin',
    ]);

    Http::fake([
        'api.github.com/repos/test-owner/test-plugin/releases/latest' => Http::response(
            fakeGitHubRelease('v2.0.0'),
            200,
        ),
    ]);

    $checker = app(PluginUpdateChecker::class);
    $result = $checker->check($plugin);

    expect($result['update_available'])->toBeTrue();
    expect($result['latest'])->toBe('2.0.0');
    expect($result['error'])->toBeNull();

    $plugin->refresh();
    expect($plugin->latest_version)->toBe('2.0.0');
    expect($plugin->last_update_check_at)->not->toBeNull();
});

it('reports no update when the current version matches the latest', function () {
    $plugin = createPluginForUpdateTests('Already Current', [
        'version' => '2.0.0',
        'repository' => 'test-owner/current-plugin',
    ]);

    Http::fake([
        'api.github.com/repos/test-owner/current-plugin/releases/latest' => Http::response(
            fakeGitHubRelease('v2.0.0'),
            200,
        ),
    ]);

    $checker = app(PluginUpdateChecker::class);
    $result = $checker->check($plugin);

    expect($result['update_available'])->toBeFalse();
    expect($result['latest'])->toBe('2.0.0');
    expect($result['error'])->toBeNull();
});

it('handles plugins without a repository gracefully', function () {
    $plugin = createPluginForUpdateTests('No Repo', [
        'repository' => null,
    ]);

    $checker = app(PluginUpdateChecker::class);
    $result = $checker->check($plugin);

    expect($result['update_available'])->toBeFalse();
    expect($result['error'])->toBe('No repository configured.');
});

it('handles a 404 response (no releases) gracefully', function () {
    $plugin = createPluginForUpdateTests('No Releases', [
        'repository' => 'test-owner/no-releases',
    ]);

    Http::fake([
        'api.github.com/repos/test-owner/no-releases/releases/latest' => Http::response(
            ['message' => 'Not Found'],
            404,
        ),
    ]);

    $checker = app(PluginUpdateChecker::class);
    $result = $checker->check($plugin);

    expect($result['update_available'])->toBeFalse();
    expect($result['error'])->toBe('No releases found.');
});

it('captures errors from failed API requests', function () {
    $plugin = createPluginForUpdateTests('API Error', [
        'repository' => 'test-owner/api-error',
    ]);

    Http::fake([
        'api.github.com/repos/test-owner/api-error/releases/latest' => Http::response(
            ['message' => 'rate limit exceeded'],
            403,
        ),
    ]);

    $checker = app(PluginUpdateChecker::class);
    $result = $checker->check($plugin);

    expect($result['update_available'])->toBeFalse();
    expect($result['error'])->toContain('403');
});

it('prefers zip assets over tar.gz', function () {
    $plugin = createPluginForUpdateTests('Zip Preferred', [
        'version' => '1.0.0',
        'repository' => 'test-owner/zip-test',
    ]);

    Http::fake([
        'api.github.com/repos/test-owner/zip-test/releases/latest' => Http::response(
            fakeGitHubRelease('v2.0.0', [
                ['name' => 'plugin.tar.gz', 'browser_download_url' => 'https://example.com/plugin.tar.gz'],
                ['name' => 'plugin.zip', 'browser_download_url' => 'https://example.com/plugin.zip'],
            ]),
            200,
        ),
    ]);

    $checker = app(PluginUpdateChecker::class);
    $checker->check($plugin);

    $plugin->refresh();
    expect($plugin->latest_release_url)->toBe('https://example.com/plugin.zip');
});

it('skips signature and checksum assets when selecting download', function () {
    $plugin = createPluginForUpdateTests('Skip Sigs', [
        'version' => '1.0.0',
        'repository' => 'test-owner/sig-test',
    ]);

    Http::fake([
        'api.github.com/repos/test-owner/sig-test/releases/latest' => Http::response(
            fakeGitHubRelease('v2.0.0', [
                ['name' => 'plugin.zip.sha256', 'browser_download_url' => 'https://example.com/plugin.zip.sha256'],
                ['name' => 'plugin.zip.asc', 'browser_download_url' => 'https://example.com/plugin.zip.asc'],
                ['name' => 'plugin.zip', 'browser_download_url' => 'https://example.com/plugin.zip'],
            ]),
            200,
        ),
    ]);

    $checker = app(PluginUpdateChecker::class);
    $checker->check($plugin);

    $plugin->refresh();
    expect($plugin->latest_release_url)->toBe('https://example.com/plugin.zip');
});

it('resolves sha256 from companion file', function () {
    $expectedHash = str_repeat('ab', 32);

    $plugin = createPluginForUpdateTests('SHA Companion', [
        'version' => '1.0.0',
        'repository' => 'test-owner/sha-companion',
    ]);

    Http::fake([
        'api.github.com/repos/test-owner/sha-companion/releases/latest' => Http::response(
            fakeGitHubRelease('v2.0.0', [
                ['name' => 'plugin.zip', 'browser_download_url' => 'https://example.com/plugin.zip'],
                ['name' => 'plugin.zip.sha256', 'browser_download_url' => 'https://example.com/plugin.zip.sha256'],
            ]),
            200,
        ),
        'example.com/plugin.zip.sha256' => Http::response("{$expectedHash}  plugin.zip\n", 200),
    ]);

    $checker = app(PluginUpdateChecker::class);
    $checker->check($plugin);

    $plugin->refresh();
    expect($plugin->latest_release_sha256)->toBe($expectedHash);
});

it('resolves sha256 from release body', function () {
    $expectedHash = str_repeat('cd', 32);

    $plugin = createPluginForUpdateTests('SHA Body', [
        'version' => '1.0.0',
        'repository' => 'test-owner/sha-body',
    ]);

    Http::fake([
        'api.github.com/repos/test-owner/sha-body/releases/latest' => Http::response(
            fakeGitHubRelease('v2.0.0', [
                ['name' => 'plugin.zip', 'browser_download_url' => 'https://example.com/plugin.zip'],
            ], "## Checksums\n\nSHA-256: {$expectedHash}"),
            200,
        ),
    ]);

    $checker = app(PluginUpdateChecker::class);
    $checker->check($plugin);

    $plugin->refresh();
    expect($plugin->latest_release_sha256)->toBe($expectedHash);
});

it('stores release metadata', function () {
    $plugin = createPluginForUpdateTests('Metadata', [
        'version' => '1.0.0',
        'repository' => 'test-owner/metadata-test',
    ]);

    Http::fake([
        'api.github.com/repos/test-owner/metadata-test/releases/latest' => Http::response(
            fakeGitHubRelease('v2.0.0', [
                ['name' => 'plugin.zip', 'browser_download_url' => 'https://example.com/plugin.zip'],
            ]),
            200,
        ),
    ]);

    $checker = app(PluginUpdateChecker::class);
    $checker->check($plugin);

    $plugin->refresh();
    expect($plugin->latest_release_metadata)->toBeArray();
    expect($plugin->latest_release_metadata['tag'])->toBe('v2.0.0');
    expect($plugin->latest_release_metadata['html_url'])->toContain('test-owner/test-plugin');
});

it('checkAll skips plugins without repository or with update checks disabled', function () {
    createPluginForUpdateTests('Has Repo', [
        'repository' => 'test-owner/has-repo',
        'update_check_enabled' => true,
    ]);

    createPluginForUpdateTests('No Repo', [
        'repository' => null,
        'update_check_enabled' => true,
    ]);

    createPluginForUpdateTests('Disabled Check', [
        'repository' => 'test-owner/disabled',
        'update_check_enabled' => false,
    ]);

    Http::fake([
        'api.github.com/repos/test-owner/has-repo/releases/latest' => Http::response(
            fakeGitHubRelease('v2.0.0'),
            200,
        ),
    ]);

    $checker = app(PluginUpdateChecker::class);
    $results = $checker->checkAll();

    // Only the plugin with repo + enabled should be checked
    expect($results)->toHaveCount(1);
    expect(array_values($results)[0]['latest'])->toBe('2.0.0');
});

it('checkAll returns empty when update checking is disabled globally', function () {
    config()->set('plugins.update_check.enabled', false);

    createPluginForUpdateTests('Should Skip', [
        'repository' => 'test-owner/skip',
    ]);

    $checker = app(PluginUpdateChecker::class);
    $results = $checker->checkAll();

    expect($results)->toBeEmpty();
});

it('hasUpdateAvailable returns true when latest is newer', function () {
    $plugin = createPluginForUpdateTests('Model Check', [
        'version' => '1.0.0',
        'latest_version' => '2.0.0',
    ]);

    expect($plugin->hasUpdateAvailable())->toBeTrue();
});

it('hasUpdateAvailable returns false when versions match', function () {
    $plugin = createPluginForUpdateTests('Same Version', [
        'version' => '2.0.0',
        'latest_version' => '2.0.0',
    ]);

    expect($plugin->hasUpdateAvailable())->toBeFalse();
});

it('hasUpdateAvailable handles v-prefixed versions', function () {
    $plugin = createPluginForUpdateTests('V Prefix', [
        'version' => 'v1.0.0',
        'latest_version' => 'v2.0.0',
    ]);

    expect($plugin->hasUpdateAvailable())->toBeTrue();
});

it('hasUpdateAvailable returns false when latest_version is null', function () {
    $plugin = createPluginForUpdateTests('No Latest', [
        'version' => '1.0.0',
        'latest_version' => null,
    ]);

    expect($plugin->hasUpdateAvailable())->toBeFalse();
});

// -- Artisan command tests --

it('runs the plugins:check-updates command successfully', function () {
    createPluginForUpdateTests('Command Test', [
        'repository' => 'test-owner/command-test',
        'version' => '1.0.0',
    ]);

    Http::fake([
        'api.github.com/repos/test-owner/command-test/releases/latest' => Http::response(
            fakeGitHubRelease('v2.0.0'),
            200,
        ),
    ]);

    $this->artisan('plugins:check-updates')
        ->assertSuccessful();
});

it('the command reports when update checking is disabled', function () {
    config()->set('plugins.update_check.enabled', false);

    $this->artisan('plugins:check-updates')
        ->expectsOutputToContain('disabled')
        ->assertSuccessful();
});

it('the command handles a missing plugin gracefully', function () {
    $this->artisan('plugins:check-updates', ['--plugin' => 'nonexistent-plugin-xyz'])
        ->assertFailed();
});

// -- Manifest repository validation tests --

it('normalizes github URLs to owner/repo format', function () {
    $manifest = PluginManifest::fromArray([
        'id' => 'test-normalize',
        'name' => 'Test Normalize',
        'version' => '1.0.0',
        'description' => 'Test',
        'entrypoint' => 'Plugin.php',
        'api_version' => '1.0.0',
        'repository' => 'https://github.com/owner/repo',
    ], '/tmp/test-normalize');

    expect($manifest->repository)->toBe('owner/repo');
});

it('keeps owner/repo format unchanged in manifest', function () {
    $manifest = PluginManifest::fromArray([
        'id' => 'test-passthrough',
        'name' => 'Test Passthrough',
        'version' => '1.0.0',
        'description' => 'Test',
        'entrypoint' => 'Plugin.php',
        'api_version' => '1.0.0',
        'repository' => 'owner/repo',
    ], '/tmp/test-passthrough');

    expect($manifest->repository)->toBe('owner/repo');
});
