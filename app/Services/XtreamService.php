<?php

namespace App\Services;

use App\Models\Playlist;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service to interact with Xtream Codes API for IPTV services.
 */
class XtreamService
{
    protected string $server;

    protected string $user;

    protected string $pass;

    protected int $retryLimit;

    protected ?Playlist $playlist;

    protected ?array $xtream_config;

    /**
     * @var string[] Alternative server URLs for failover.
     */
    protected array $fallbackUrls = [];

    /**
     * Factory method to create an instance of XtreamService.
     *
     * @param  int  $retryLimit  Number of retries for HTTP requests
     */
    public static function make(
        ?Playlist $playlist = null,
        ?array $xtream_config = null,
        $retryLimit = 5
    ): self {
        $instance = new self;

        return $instance->init($playlist, $xtream_config, $retryLimit);
    }

    /**
     * Initialize the XtreamService with a Playlist or Xtream config.
     *
     * @param  int  $retryLimit  Number of retries for HTTP requests
     * @return bool|self Returns false if initialization fails, otherwise returns the instance.
     */
    public function init(
        ?Playlist $playlist = null,
        ?array $xtream_config = null,
        $retryLimit = 5
    ): bool|self {
        // If Playlist, and not an xtream playlist, return false
        if ($playlist && ! $playlist->xtream) {
            return false;
        }

        // Set Playlist and Xtream config
        $this->playlist = $playlist;
        $this->xtream_config = $xtream_config;

        // Setup server, user, and pass
        // Prefer Xtream config if provided directly
        if ($xtream_config) {
            $this->server = $xtream_config['url'] ?? '';
            $this->user = $xtream_config['username'] ?? '';
            $this->pass = $xtream_config['password'] ?? '';
        } elseif ($playlist) {
            $config = $playlist->xtream_config;
            $this->server = $config['url'] ?? '';
            $this->user = $config['username'] ?? '';
            $this->pass = $config['password'] ?? '';
        } else {
            return false;
        }

        $this->retryLimit = $retryLimit;

        // Load fallback URLs from playlist if available
        if ($playlist) {
            $allUrls = $playlist->getOrderedXtreamUrls();
            // Remove the primary URL (already set as $this->server)
            $primary = rtrim($this->server, '/');
            $this->fallbackUrls = array_values(array_filter(
                $allUrls,
                fn (string $u) => rtrim($u, '/') !== $primary
            ));
        }

        return $this;
    }

    protected function call(string $url, int $timeout = 60 * 15)
    {
        if (! ($this->playlist || $this->xtream_config)) {
            throw new Exception('Config not initialized. Call init() first with Playlist or Xtream config array.');
        }

        $user_agent = $this->playlist?->user_agent ?? 'VLC/3.0.21 LibVLC/3.0.21';
        $verify = ! ($this->playlist?->disable_ssl_verification ?? false);

        // Try the primary URL first
        $result = $this->attemptCall($url, $timeout, $user_agent, $verify);
        if ($result !== null) {
            return $result;
        }

        // Primary failed — try fallback URLs
        if (! empty($this->fallbackUrls)) {
            $originalServer = $this->server;

            foreach ($this->fallbackUrls as $fallbackUrl) {
                // Normalize for use as a base URL in HTTP calls (no trailing slash, spaces encoded)
                $fallbackUrl = rtrim($fallbackUrl, '/');
                $fallbackCallUrl = str_replace(
                    rtrim($originalServer, '/'),
                    str($fallbackUrl)->replace(' ', '%20')->toString(),
                    $url
                );

                Log::info("Xtream failover: trying fallback URL {$fallbackUrl}", [
                    'playlist_id' => $this->playlist?->id,
                    'original_server' => $originalServer,
                ]);

                $result = $this->attemptCall($fallbackCallUrl, $timeout, $user_agent, $verify);
                if ($result !== null) {
                    // Failover succeeded — update the primary URL.
                    // Pass the raw (unencoded) URL so promoteXtreamUrl can match it
                    // against getOrderedXtreamUrls() which also returns raw URLs.
                    $this->server = $fallbackUrl;
                    if ($this->playlist) {
                        $this->playlist->promoteXtreamUrl($fallbackUrl);
                        Log::info("Xtream failover: rotated primary URL to {$fallbackUrl}", [
                            'playlist_id' => $this->playlist->id,
                        ]);
                    }

                    // Rebuild fallback list without the new primary
                    $this->fallbackUrls = array_values(array_filter(
                        $this->fallbackUrls,
                        fn (string $u) => rtrim($u, '/') !== rtrim($fallbackUrl, '/')
                    ));
                    if ($originalServer) {
                        $this->fallbackUrls[] = $originalServer;
                    }

                    return $result;
                }
            }
        }

        // All URLs exhausted — throw
        throw new Exception('All Xtream API URLs failed (primary + '.count($this->fallbackUrls).' fallbacks)');
    }

    /**
     * Attempt to call a URL with retries.
     *
     * @return array|null Response JSON on success, null on failure.
     */
    protected function attemptCall(string $url, int $timeout, string $userAgent, bool $verify): ?array
    {
        $attempts = 0;
        $response = null;

        do {
            try {
                $response = Http::timeout($timeout)
                    ->withOptions(['verify' => $verify])
                    ->withHeaders(['User-Agent' => $userAgent])
                    ->get($url);

                if ($response->ok()) {
                    return $response->json();
                }
            } catch (Exception $e) {
                // Connection error — continue retrying
            }

            $attempts++;
            if ($attempts < $this->retryLimit) {
                sleep(1);
            }
        } while ($attempts < $this->retryLimit);

        return null;
    }

    protected function makeUrl(string $action, array $extra = []): string
    {
        $params = array_merge([
            'username' => $this->user,
            'password' => $this->pass,
            'action' => $action,
        ], $extra);

        if (! Str::startsWith($this->server, 'http://') && ! Str::startsWith($this->server, 'https://')) {
            $this->server = 'http://'.$this->server; // ensure server URL starts with http:// or https://
        }

        return $this->server
            .'/player_api.php?'.http_build_query($params);
    }

    public function authenticate(): array
    {
        $url = $this->server
            ."/player_api.php?username={$this->user}&password={$this->pass}";

        return $this->call(url: $url, timeout: 5)['user_info'] ?? []; // set short timeout
    }

    public function userInfo($timeout = 5): array
    {
        $url = $this->server
            ."/player_api.php?username={$this->user}&password={$this->pass}";

        return $this->call(url: $url, timeout: $timeout) ?? []; // set short timeout
    }

    public function getLiveCategories(): array
    {
        return $this->call($this->makeUrl('get_live_categories')) ?? [];
    }

    public function getLiveStreams(string $catId): array
    {
        return $this->call($this->makeUrl('get_live_streams', ['category_id' => $catId])) ?? [];
    }

    public function getVodCategories(): array
    {
        return $this->call($this->makeUrl('get_vod_categories')) ?? [];
    }

    public function getVodStreams(string $catId): array
    {
        return $this->call($this->makeUrl('get_vod_streams', ['category_id' => $catId])) ?? [];
    }

    public function getSeriesCategories(): array
    {
        return $this->call($this->makeUrl('get_series_categories')) ?? [];
    }

    public function getSeries(string $catId): array
    {
        return $this->call($this->makeUrl('get_series', ['category_id' => $catId])) ?? [];
    }

    public function getVodInfo(string $vodId, int $timeout = 60): array
    {
        return $this->call($this->makeUrl('get_vod_info', ['vod_id' => $vodId]), $timeout) ?? [];
    }

    public function getSeriesInfo(string $seriesId, int $timeout = 60): array
    {
        return $this->call($this->makeUrl('get_series_info', ['series_id' => $seriesId]), $timeout) ?? [];
    }

    public function buildMovieUrl(string $id, ?string $ext): string
    {
        $ext = $ext ? ".{$ext}" : '';

        return "{$this->server}/movie/{$this->user}/{$this->pass}/{$id}{$ext}";
    }

    public function buildSeriesUrl(string $id, ?string $ext): string
    {
        $ext = $ext ? ".{$ext}" : '';

        return "{$this->server}/series/{$this->user}/{$this->pass}/{$id}{$ext}";
    }
}
