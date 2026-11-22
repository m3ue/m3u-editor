<?php

use App\Services\M3uTvWebAppService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * The M3U TV web app installer (M3uTvWebAppService) and the /m3u-tv/ route
 * that serves the installed build. Every test installs into its own temp
 * directory with no hand-copied fallback build, and GitHub is faked.
 */
beforeEach(function () {
    $this->m3uTvRoot = sys_get_temp_dir().'/m3u-tv-web-test-'.uniqid();
    $this->m3uTvService = new M3uTvWebAppService(
        installPath: $this->m3uTvRoot.'/install',
        manualPath: $this->m3uTvRoot.'/no-manual-build',
    );
    app()->instance(M3uTvWebAppService::class, $this->m3uTvService);
    Cache::forget(M3uTvWebAppService::LATEST_CACHE_KEY);
    Http::preventStrayRequests();
    config(['dev.tv_repo' => 'm3ue/m3u-tv', 'dev.version' => '0.14.5']);
});

afterEach(function () {
    File::deleteDirectory($this->m3uTvRoot);
});

/**
 * Builds a web zip like m3u-tv's scripts/package-web.sh and fakes the GitHub
 * release that publishes it. Returns the zip's bytes.
 *
 * @param  array<string, string>  $extraEntries
 * @param  array<string, mixed>  $manifestOverrides
 */
function m3uTvWebFakeRelease(string $version = '1.3.1', string $build = '31', array $extraEntries = [], array $manifestOverrides = [], ?string $archiveUrl = null): string
{
    $zipPath = tempnam(sys_get_temp_dir(), 'm3u-tv-web-zip');
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::OVERWRITE);
    $zip->addFromString('index.html', "<!doctype html><title>M3U TV {$version}</title>");
    $zip->addFromString('flutter_bootstrap.js', '// bootstrap');
    $zip->addFromString('main.dart.39d7f4a8.wasm', "\0asm");
    $zip->addFromString('mkv_player.mjs', 'export {};');
    $zip->addFromString('m3u-tv-web.json', json_encode(['name' => 'm3u-tv-web', 'version' => $version, 'build_number' => $build]));
    foreach ($extraEntries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
    $bytes = (string) file_get_contents($zipPath);
    unlink($zipPath);

    // A fresh fake per call, so a later release replaces the earlier stubs.
    Http::swap(new HttpFactory);
    Http::preventStrayRequests();

    $download = "https://github.com/m3ue/m3u-tv/releases/download/v{$version}";
    $manifest = array_merge([
        'name' => 'm3u-tv-web',
        'version' => $version,
        'build_number' => $build,
        'min_editor_version' => '0.14.5',
        'archive' => "m3u-tv-v{$version}-web.zip",
        'archive_sha256' => hash('sha256', $bytes),
    ], $manifestOverrides);

    Http::fake([
        'api.github.com/repos/m3ue/m3u-tv/releases/latest' => Http::response([
            'tag_name' => "v{$version}",
            'html_url' => "https://github.com/m3ue/m3u-tv/releases/tag/v{$version}",
            'assets' => [
                ['name' => "m3u-tv-v{$version}-web.json", 'browser_download_url' => "{$download}/m3u-tv-v{$version}-web.json"],
                ['name' => "m3u-tv-v{$version}-web.zip", 'browser_download_url' => $archiveUrl ?? "{$download}/m3u-tv-v{$version}-web.zip"],
            ],
        ]),
        "github.com/m3ue/m3u-tv/releases/download/v{$version}/m3u-tv-v{$version}-web.json" => Http::response($manifest),
        "github.com/m3ue/m3u-tv/releases/download/v{$version}/m3u-tv-v{$version}-web.zip" => Http::response($bytes),
    ]);

    return $bytes;
}

/** The file a /m3u-tv/ response serves (BinaryFileResponse has no in-memory body). */
function m3uTvWebServedBody(TestResponse $response): string
{
    return (string) file_get_contents($response->baseResponse->getFile()->getPathname());
}

it('installs the latest web build and serves it at /m3u-tv/', function () {
    m3uTvWebFakeRelease();

    expect($this->m3uTvService->updateAvailable())->toBeTrue();
    expect($this->m3uTvService->installLatest())->toBe(['version' => '1.3.1', 'build_number' => '31']);

    $installed = $this->m3uTvService->installed();
    expect($installed['version'])->toBe('1.3.1')
        ->and($installed['build_number'])->toBe('31')
        ->and($installed['has_previous'])->toBeFalse()
        ->and($this->m3uTvService->updateAvailable())->toBeFalse();

    $index = $this->get('/m3u-tv/')
        ->assertSuccessful()
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeader('Cross-Origin-Embedder-Policy', 'credentialless');
    expect(m3uTvWebServedBody($index))->toContain('M3U TV 1.3.1');
});

it('serves hashed files as immutable and everything else with revalidation', function () {
    m3uTvWebFakeRelease();
    $this->m3uTvService->installLatest();

    $wasm = $this->get('/m3u-tv/main.dart.39d7f4a8.wasm')->assertSuccessful();
    expect($wasm->headers->get('Content-Type'))->toBe('application/wasm')
        ->and($wasm->headers->get('Cache-Control'))->toContain('immutable');

    $module = $this->get('/m3u-tv/mkv_player.mjs')->assertSuccessful();
    expect($module->headers->get('Content-Type'))->toStartWith('text/javascript')
        ->and($module->headers->get('Cache-Control'))->toContain('no-cache');

    expect($this->get('/m3u-tv/flutter_bootstrap.js')->headers->get('Cache-Control'))->toContain('no-cache');
});

it('does not serve files outside the installed build', function () {
    m3uTvWebFakeRelease();
    $this->m3uTvService->installLatest();
    File::put($this->m3uTvRoot.'/install/secret.txt', 'secret');

    $this->get('/m3u-tv/../secret.txt')->assertNotFound();
    $this->get('/m3u-tv/%2e%2e/secret.txt')->assertNotFound();
    $this->get('/m3u-tv/missing.js')->assertNotFound();
});

it('explains how to install when nothing is installed', function () {
    $this->get('/m3u-tv/')
        ->assertNotFound()
        ->assertSee('Settings > TV App', escape: false);
});

it('rejects a download that fails its checksum', function () {
    m3uTvWebFakeRelease(manifestOverrides: ['archive_sha256' => str_repeat('a', 64)]);

    expect(fn () => $this->m3uTvService->installLatest())
        ->toThrow(RuntimeException::class, 'checksum');
    expect($this->m3uTvService->installed())->toBeNull();
});

it('refuses a build that needs a newer editor', function () {
    m3uTvWebFakeRelease(manifestOverrides: ['min_editor_version' => '9.0.0']);

    expect($this->m3uTvService->latestRelease()['compatible'])->toBeFalse()
        ->and($this->m3uTvService->updateAvailable())->toBeFalse();
    expect(fn () => $this->m3uTvService->installLatest())
        ->toThrow(RuntimeException::class, 'needs M3U Editor 9.0.0');
});

it('rejects archive entries that escape the install directory', function () {
    m3uTvWebFakeRelease(extraEntries: ['../escaped.txt' => 'nope']);

    expect(fn () => $this->m3uTvService->installLatest())
        ->toThrow(RuntimeException::class, 'unsafe path entry');
    expect($this->m3uTvService->installed())->toBeNull()
        ->and(file_exists($this->m3uTvRoot.'/install/escaped.txt'))->toBeFalse();
});

it('only downloads from the configured repository releases', function () {
    m3uTvWebFakeRelease(archiveUrl: 'https://example.com/m3u-tv-v1.3.1-web.zip');

    expect($this->m3uTvService->latestRelease())->toBeNull();
});

it('keeps the previous build for rollback and prunes older ones', function () {
    m3uTvWebFakeRelease('1.3.1', '31');
    $this->m3uTvService->installLatest();
    m3uTvWebFakeRelease('1.3.2', '32');
    $this->m3uTvService->installLatest();
    m3uTvWebFakeRelease('1.3.3', '33');
    $this->m3uTvService->installLatest();

    $releases = collect(File::directories($this->m3uTvRoot.'/install/releases'))->map(fn ($dir) => basename($dir))->sort()->values()->all();
    expect($releases)->toBe(['1.3.2+32', '1.3.3+33']);

    $this->m3uTvService->rollback();
    expect($this->m3uTvService->installed()['version'])->toBe('1.3.2');
    expect(m3uTvWebServedBody($this->get('/m3u-tv/')))->toContain('M3U TV 1.3.2');
});

it('removes the installed build', function () {
    m3uTvWebFakeRelease();
    $this->m3uTvService->installLatest();

    $this->m3uTvService->remove();

    expect($this->m3uTvService->installed())->toBeNull();
    $this->get('/m3u-tv/')->assertNotFound();
});
