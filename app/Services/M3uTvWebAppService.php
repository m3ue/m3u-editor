<?php

namespace App\Services;

use App\Support\SafeZipExtractor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Installs and serves the M3U TV web app (the Flutter build behind /m3u-tv/)
 * independently of M3U Editor releases.
 *
 * Each M3U TV GitHub release carries `m3u-tv-v<version>-web.zip`, its
 * `.sha256`, and a `m3u-tv-v<version>-web.json` manifest (version, build
 * number, zip sha256, oldest supported editor version), produced by
 * m3u-tv's scripts/package-web.sh. Installing downloads the zip only from
 * the configured repository's releases, checks its size and sha256 and the
 * editor version, extracts it with the same zip guards as plugins, and
 * switches over in one step. The previous version is kept for rollback.
 *
 * Layout under storage/app/m3u-tv-web (persists across container upgrades):
 * releases/<version>+<build>/ holds each build; state.json records the
 * current and previous release directories.
 */
class M3uTvWebAppService
{
    public const LATEST_CACHE_KEY = 'm3u-tv-web.latest-release';

    /** Cap on the downloaded archive and on what it may extract to. */
    private const MAX_ARCHIVE_BYTES = 100 * 1024 * 1024;

    private const MAX_EXTRACTED_BYTES = 300 * 1024 * 1024;

    private const MAX_FILE_COUNT = 2000;

    private const MANIFEST_ASSET_PATTERN = '/^m3u-tv-v(\d+\.\d+\.\d+)-web\.json$/';

    /**
     * @param  string|null  $installPath  where builds are installed (default storage/app/m3u-tv-web)
     * @param  string|null  $manualPath  a hand-copied build to fall back to (default public/m3u-tv)
     */
    public function __construct(
        private readonly ?string $installPath = null,
        private readonly ?string $manualPath = null,
    ) {}

    public function rootPath(): string
    {
        return $this->installPath ?? storage_path('app/m3u-tv-web');
    }

    /**
     * The directory /m3u-tv/ serves: the installed release, else a build
     * copied into public/m3u-tv by hand (development), else null.
     */
    public function servingRoot(): ?string
    {
        $installed = $this->installed();
        if ($installed !== null && is_file($installed['path'].'/index.html')) {
            return $installed['path'];
        }

        $manual = $this->manualPath ?? public_path('m3u-tv');

        return is_file($manual.'/index.html') ? $manual : null;
    }

    /**
     * @return array{version: string, build_number: string, installed_at: string, path: string, has_previous: bool}|null
     */
    public function installed(): ?array
    {
        $state = $this->readState();
        $current = $state['current'] ?? null;
        if (! is_array($current) || ! isset($current['directory'])) {
            return null;
        }

        $path = $this->releasesPath().'/'.$current['directory'];
        if (! is_dir($path)) {
            return null;
        }

        return [
            'version' => (string) ($current['version'] ?? ''),
            'build_number' => (string) ($current['build_number'] ?? ''),
            'installed_at' => (string) ($current['installed_at'] ?? ''),
            'path' => $path,
            'has_previous' => $this->releaseExists($state['previous'] ?? null),
        ];
    }

    /**
     * The newest M3U TV release that has a web build, or null when none can
     * be found (no web assets yet, GitHub unreachable, malformed manifest).
     *
     * @return array{version: string, build_number: string, min_editor_version: string, archive_url: string, archive_sha256: string, release_url: string, compatible: bool}|null
     */
    public function latestRelease(bool $refresh = false): ?array
    {
        if (! $refresh) {
            $cached = Cache::get(self::LATEST_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
            if ($cached === false) {
                return null;
            }
        }

        try {
            $release = $this->fetchLatestRelease();
        } catch (Throwable) {
            $release = null;
        }

        // A miss is cached too (as false), so an install without internet
        // doesn't wait on GitHub every time the settings page renders.
        Cache::put(self::LATEST_CACHE_KEY, $release ?? false, now()->addMinutes($release === null ? 10 : 30));

        return $release;
    }

    public function updateAvailable(): bool
    {
        $latest = $this->latestRelease();
        if ($latest === null || ! $latest['compatible']) {
            return false;
        }

        $installed = $this->installed();

        return $installed === null || $this->isNewer($latest, $installed);
    }

    /**
     * Downloads, verifies and switches to the latest compatible web build.
     *
     * @return array{version: string, build_number: string}
     */
    public function installLatest(): array
    {
        $release = $this->latestRelease(refresh: true);
        if ($release === null) {
            throw new RuntimeException('No M3U TV release with a web build was found.');
        }

        if (! $release['compatible']) {
            throw new RuntimeException("M3U TV {$release['version']} needs M3U Editor {$release['min_editor_version']} or newer.");
        }

        $tempPath = $this->rootPath().'/tmp/'.Str::random(12);
        File::ensureDirectoryExists($tempPath);

        try {
            $archivePath = $tempPath.'/web.zip';
            $this->download($release['archive_url'], $archivePath);

            $actualSha256 = hash_file('sha256', $archivePath) ?: '';
            if (! hash_equals(Str::lower($release['archive_sha256']), $actualSha256)) {
                throw new RuntimeException('The downloaded M3U TV web build failed its checksum check.');
            }

            $extractPath = $tempPath.'/extract';
            File::ensureDirectoryExists($extractPath);
            $this->extract($archivePath, $extractPath);
            $this->assertBuildMatches($extractPath, $release);

            $directory = $this->releaseDirectory($release['version'], $release['build_number']);
            $destination = $this->releasesPath().'/'.$directory;
            File::deleteDirectory($destination);
            File::ensureDirectoryExists($this->releasesPath());
            if (! File::moveDirectory($extractPath, $destination)) {
                throw new RuntimeException('Unable to move the M3U TV web build into place.');
            }

            $this->switchTo([
                'directory' => $directory,
                'version' => $release['version'],
                'build_number' => $release['build_number'],
                'installed_at' => now()->toIso8601String(),
            ]);
        } finally {
            File::deleteDirectory($tempPath);
        }

        return ['version' => $release['version'], 'build_number' => $release['build_number']];
    }

    /** Switches back to the previously installed build. */
    public function rollback(): void
    {
        $state = $this->readState();
        if (! $this->releaseExists($state['previous'] ?? null)) {
            throw new RuntimeException('There is no previous M3U TV web build to roll back to.');
        }

        $this->writeState(['current' => $state['previous'], 'previous' => $state['current'] ?? null]);
    }

    /** Removes every installed build; /m3u-tv/ stops being served. */
    public function remove(): void
    {
        File::deleteDirectory($this->rootPath());
    }

    /**
     * @return array{version: string, build_number: string, min_editor_version: string, archive_url: string, archive_sha256: string, release_url: string, compatible: bool}|null
     */
    private function fetchLatestRelease(): ?array
    {
        $repository = (string) config('dev.tv_repo');
        $response = Http::acceptJson()
            ->timeout(8)
            ->get("https://api.github.com/repos/{$repository}/releases/latest");
        if (! $response->ok()) {
            return null;
        }

        $assets = collect($response->json('assets', []));
        $manifestAsset = $assets->first(
            fn ($asset): bool => is_array($asset) && preg_match(self::MANIFEST_ASSET_PATTERN, (string) ($asset['name'] ?? '')) === 1,
        );
        if ($manifestAsset === null) {
            return null;
        }

        $manifestUrl = (string) ($manifestAsset['browser_download_url'] ?? '');
        if (! $this->isReleaseDownloadUrl($manifestUrl, $repository)) {
            return null;
        }

        $manifest = Http::timeout(8)->get($manifestUrl)->json();
        if (! is_array($manifest)
            || ! preg_match('/^\d+\.\d+\.\d+$/', (string) ($manifest['version'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/i', (string) ($manifest['archive_sha256'] ?? ''))) {
            return null;
        }

        $archiveName = (string) ($manifest['archive'] ?? '');
        $archiveAsset = $assets->first(fn ($asset): bool => is_array($asset) && ($asset['name'] ?? null) === $archiveName);
        $archiveUrl = (string) ($archiveAsset['browser_download_url'] ?? '');
        if ($archiveName === '' || ! $this->isReleaseDownloadUrl($archiveUrl, $repository)) {
            return null;
        }

        $minEditorVersion = (string) ($manifest['min_editor_version'] ?? '0.0.0');

        return [
            'version' => (string) $manifest['version'],
            'build_number' => (string) ($manifest['build_number'] ?? ''),
            'min_editor_version' => $minEditorVersion,
            'archive_url' => $archiveUrl,
            'archive_sha256' => Str::lower((string) $manifest['archive_sha256']),
            'release_url' => (string) $response->json('html_url', ''),
            'compatible' => version_compare((string) config('dev.version'), $minEditorVersion, '>='),
        ];
    }

    /** Only the configured repository's GitHub release downloads are fetched. */
    private function isReleaseDownloadUrl(string $url, string $repository): bool
    {
        return Str::startsWith($url, "https://github.com/{$repository}/releases/download/");
    }

    private function download(string $url, string $destination): void
    {
        $response = Http::timeout(300)
            ->sink($destination)
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Downloading the M3U TV web build failed (HTTP {$response->status()}).");
        }

        if ((int) filesize($destination) > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('The M3U TV web build is larger than expected.');
        }
    }

    private function extract(string $archivePath, string $extractPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('The M3U TV web build is not a valid zip file.');
        }

        try {
            (new SafeZipExtractor('M3U TV web archive', self::MAX_FILE_COUNT, self::MAX_EXTRACTED_BYTES))
                ->extract($zip, $extractPath, basename($archivePath));
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array{version: string, build_number: string}  $release
     */
    private function assertBuildMatches(string $extractPath, array $release): void
    {
        $manifest = json_decode((string) @file_get_contents($extractPath.'/m3u-tv-web.json'), true);
        if (! is_file($extractPath.'/index.html') || ! is_array($manifest)
            || ($manifest['version'] ?? null) !== $release['version']) {
            throw new RuntimeException('The M3U TV web build does not match its release.');
        }
    }

    /**
     * @param  array{directory: string, version: string, build_number: string, installed_at: string}  $release
     */
    private function switchTo(array $release): void
    {
        $state = $this->readState();
        $current = $state['current'] ?? null;
        $keepPrevious = is_array($current) && ($current['directory'] ?? null) !== $release['directory'];
        $previous = $keepPrevious ? $current : ($state['previous'] ?? null);

        $this->writeState(['current' => $release, 'previous' => $previous]);

        $keep = array_filter([$release['directory'], is_array($previous) ? ($previous['directory'] ?? null) : null]);
        foreach (File::directories($this->releasesPath()) as $directory) {
            if (! in_array(basename($directory), $keep, true)) {
                File::deleteDirectory($directory);
            }
        }
    }

    private function isNewer(array $latest, array $installed): bool
    {
        $comparison = version_compare($latest['version'], $installed['version']);

        return $comparison > 0
            || ($comparison === 0 && (int) $latest['build_number'] > (int) $installed['build_number']);
    }

    private function releaseDirectory(string $version, string $buildNumber): string
    {
        $build = preg_replace('/[^0-9]/', '', $buildNumber) ?: '0';

        return "{$version}+{$build}";
    }

    /** Whether a state entry points at a release directory still on disk. */
    private function releaseExists(mixed $entry): bool
    {
        return is_array($entry) && isset($entry['directory'])
            && is_dir($this->releasesPath().'/'.$entry['directory']);
    }

    private function releasesPath(): string
    {
        return $this->rootPath().'/releases';
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        $path = $this->rootPath().'/state.json';
        if (! is_file($path)) {
            return [];
        }

        $state = json_decode((string) file_get_contents($path), true);

        return is_array($state) ? $state : [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(array $state): void
    {
        File::ensureDirectoryExists($this->rootPath());
        $path = $this->rootPath().'/state.json';
        $temp = $path.'.'.Str::random(8);
        File::put($temp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::move($temp, $path);
    }
}
