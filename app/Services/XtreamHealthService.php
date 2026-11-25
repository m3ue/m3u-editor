<?php

namespace App\Services;

use App\Models\Playlist;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class XtreamHealthService
{
    /**
     * Check if a single Xtream API URL is reachable.
     *
     * @return array{reachable: bool, response_time_ms: int, error: ?string}
     */
    public static function checkUrl(string $url, string $username, string $password, int $timeout = 5, bool $verify = true, ?string $userAgent = null): array
    {
        $start = microtime(true);

        try {
            $normalizedUrl = rtrim($url, '/');
            $response = Http::timeout($timeout)
                ->withOptions(['verify' => $verify])
                ->withUserAgent($userAgent ?: XtreamService::DEFAULT_USER_AGENT)
                ->get("{$normalizedUrl}/player_api.php", [
                    'username' => $username,
                    'password' => $password,
                ]);

            $elapsed = (int) round((microtime(true) - $start) * 1000);

            if ($response->ok()) {
                return [
                    'reachable' => true,
                    'response_time_ms' => $elapsed,
                    'error' => null,
                ];
            }

            return [
                'reachable' => false,
                'response_time_ms' => $elapsed,
                'error' => "HTTP {$response->status()}",
            ];
        } catch (\Exception $e) {
            $elapsed = (int) round((microtime(true) - $start) * 1000);

            return [
                'reachable' => false,
                'response_time_ms' => $elapsed,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check all URLs (primary + fallbacks) for a playlist.
     *
     * @return array<int, array{url: string, reachable: bool, response_time_ms: int, error: ?string, is_primary: bool}>
     */
    public static function checkAllUrls(Playlist $playlist): array
    {
        $config = $playlist->xtream_config;
        if (! $config) {
            return [];
        }

        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';

        if (! $username || ! $password) {
            return [];
        }

        $urls = $playlist->getOrderedXtreamUrls();
        $primaryUrl = rtrim($config['url'] ?? '', '/');
        $verify = ! ($playlist->disable_ssl_verification ?? false);
        $results = [];

        foreach ($urls as $url) {
            $check = self::checkUrl($url, $username, $password, verify: $verify, userAgent: $playlist->user_agent);
            $results[] = array_merge($check, [
                'url' => $url,
                'is_primary' => $url === $primaryUrl,
            ]);
        }

        return $results;
    }

    /**
     * Find the first working URL from all available URLs for a playlist.
     */
    public static function findWorkingUrl(Playlist $playlist): ?string
    {
        $config = $playlist->xtream_config;
        if (! $config) {
            return null;
        }

        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';

        if (! $username || ! $password) {
            return null;
        }

        $urls = $playlist->getOrderedXtreamUrls();
        $verify = ! ($playlist->disable_ssl_verification ?? false);

        foreach ($urls as $url) {
            $result = self::checkUrl($url, $username, $password, verify: $verify, userAgent: $playlist->user_agent);
            if ($result['reachable']) {
                Log::info("Xtream health check: {$url} is reachable ({$result['response_time_ms']}ms)", [
                    'playlist_id' => $playlist->id,
                ]);

                return $url;
            }

            Log::warning("Xtream health check: {$url} is unreachable", [
                'playlist_id' => $playlist->id,
                'error' => $result['error'],
            ]);
        }

        return null;
    }
}
