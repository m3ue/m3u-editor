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
                $response = Http::get('https://raw.githubusercontent.com/sparkison/m3u-editor/refs/heads/'.self::$branch.'/config/dev.php');
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
}
