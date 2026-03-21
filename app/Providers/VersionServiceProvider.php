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
     * Fetch the latest releases from GitHub, paginating until each branch type
     * (latest / dev / experimental) has $perBranchLimit entries, then stop.
     * Results are stored in a flat file and returned.
     */
    public static function fetchReleases(int $perBranchLimit = 15, bool $refresh = false): array
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

        $buckets = ['latest' => [], 'dev' => [], 'experimental' => []];
        $page = 1;
        $pageFetchSize = 30; // items per API page
        $maxPages = 10;      // hard stop: 300 releases max regardless of bucket state

        $isFull = function () use (&$buckets, $perBranchLimit): bool {
            return count($buckets['latest']) >= $perBranchLimit
                && count($buckets['dev']) >= $perBranchLimit
                && count($buckets['experimental']) >= $perBranchLimit;
        };

        try {
            while (! $isFull() && $page <= $maxPages) {
                $response = Http::withHeaders($headers)->timeout(15)->get('https://api.github.com/repos/m3ue/m3u-editor/releases', [
                    'per_page' => $pageFetchSize,
                    'page' => $page,
                ]);

                if (! $response->ok()) {
                    break;
                }

                $pageResults = $response->json();

                if (! is_array($pageResults) || empty($pageResults)) {
                    break; // no more releases
                }

                foreach ($pageResults as $r) {
                    $tag = $r['tag_name'] ?? '';
                    if (str_ends_with($tag, '-dev')) {
                        $type = 'dev';
                    } elseif (str_ends_with($tag, '-exp')) {
                        $type = 'experimental';
                    } else {
                        $type = 'latest';
                    }

                    if (count($buckets[$type]) < $perBranchLimit) {
                        $buckets[$type][] = $r;
                    }
                }

                if (count($pageResults) < $pageFetchSize) {
                    break; // last page reached
                }

                $page++;
            }
        } catch (Exception $e) {
            // ignore — fall through to file fallback
        }

        $results = array_merge($buckets['latest'], $buckets['dev'], $buckets['experimental']);

        if (! empty($results)) {
            // Ensure storage directory exists
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            file_put_contents($path, json_encode($results));

            return $results;
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
