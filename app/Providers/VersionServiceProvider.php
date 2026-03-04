<?php

namespace App\Providers;

use App\Facades\GitInfo;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class VersionServiceProvider extends ServiceProvider
{
    public static string $cacheKey = 'app.remoteVersion';

    public static string $branch = 'master'; // Default branch, can be overridden

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        self::$branch = GitInfo::getBranch() ?? 'master';
    }

    public static function updateAvailable(): bool
    {
        $remoteVersion = self::getRemoteVersion();
        if ($remoteVersion) {
            $installedVersion = self::getVersion();

            return version_compare($installedVersion, $remoteVersion, '<');
        }

        return false;
    }

    public static function getVersion(): string
    {
        switch (self::$branch) {
            case 'dev':
                $version = config('dev.dev_version');
                break;
            case 'experimental':
                $version = config('dev.experimental_version');
                break;
            default:
                $version = config('dev.version');
        }

        return $version;
    }

    public static string $releasesFile = 'app/m3u_releases.json';

    public static function getRemoteVersion($refresh = false): string
    {
        // If using redis, may not be initialized yet, so catch the exception
        try {
            $remoteVersion = Cache::get(self::$cacheKey);
        } catch (Exception $e) {
            $remoteVersion = null;
        }
        if ($remoteVersion === null || $refresh) {
            $remoteVersion = '';
            try {
                $response = Http::get('https://raw.githubusercontent.com/m3ue/m3u-editor/refs/heads/'.self::$branch.'/config/dev.php');
                if ($response->ok()) {
                    $results = $response->body();
                    switch (self::$branch) {
                        case 'dev':
                            preg_match("/'dev_version'\s*=>\s*'([^']+)'/", $results, $matches);
                            break;
                        case 'experimental':
                            preg_match("/'experimental_version'\s*=>\s*'([^']+)'/", $results, $matches);
                            break;
                        default:
                            preg_match("/'version'\s*=>\s*'([^']+)'/", $results, $matches);
                    }
                    if (! empty($matches[1])) {
                        $remoteVersion = $matches[1];
                        Cache::put(self::$cacheKey, $remoteVersion, 60 * 5);
                    }
                }
            } catch (Exception $e) {
                // Ignore
            }
        }

        return $remoteVersion;
    }

    /**
     * Fetch the latest releases from GitHub and store them in a flat file.
     * Returns an array of releases (decoded JSON).
     */
    public static function fetchReleases(int $perPage = 5, bool $refresh = false): array
    {
        $path = storage_path(self::$releasesFile);

        // If file exists and no refresh requested, return it
        if (! $refresh && file_exists($path)) {
            try {
                $contents = file_get_contents($path);
                $decoded = json_decode($contents, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (Exception $e) {
                // ignore and fallback to fetch
            }
        }

        // Prepare headers for an unauthenticated public API request
        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'm3u-editor',
        ];

        try {
            $response = Http::withHeaders($headers)->get('https://api.github.com/repos/m3ue/m3u-editor/releases', [
                'per_page' => $perPage,
            ]);
            if ($response->ok()) {
                $results = $response->json();
                // Ensure storage directory exists
                $dir = dirname($path);
                if (! is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                file_put_contents($path, json_encode($results));

                return is_array($results) ? $results : [];
            }
        } catch (Exception $e) {
            // ignore
        }

        // Fallback: attempt to read existing file
        if (file_exists($path)) {
            try {
                $contents = file_get_contents($path);
                $decoded = json_decode($contents, true);

                return is_array($decoded) ? $decoded : [];
            } catch (Exception $e) {
                // ignore
            }
        }

        return [];
    }

    /**
     * Return locally stored releases (if any) without performing a network request.
     */
    public static function getStoredReleases(): array
    {
        $path = storage_path(self::$releasesFile);
        if (file_exists($path)) {
            try {
                $contents = file_get_contents($path);
                $decoded = json_decode($contents, true);

                return is_array($decoded) ? $decoded : [];
            } catch (Exception $e) {
                // ignore
            }
        }

        return [];
    }
}
