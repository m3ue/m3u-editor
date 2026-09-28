<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

class ProviderAuthPassthroughService
{
    public function getEnabledPlaylist(): ?Playlist
    {
        $playlist = Playlist::query()
            ->with('user')
            ->where('provider_auth_passthrough', true)
            ->where('xtream', true)
            ->first();

        if (! $playlist) {
            return null;
        }

        if (! $playlist->user?->canUseProviderAuthPassthrough()) {
            return null;
        }

        return $playlist;
    }

    public function authenticate(string $username, string $password): ?array
    {
        if ($username === '' || $password === '') {
            return null;
        }

        $playlist = $this->getEnabledPlaylist();

        if (! $playlist) {
            return null;
        }

        /*
         * Never store the provider password in cache keys or values.
         * The HMAC uniquely identifies the credential pair without exposing it.
         */
        $credentialHash = hash_hmac(
            'sha256',
            $username."\0".$password,
            (string) config('app.key')
        );

        $cacheKey = sprintf(
            'provider-passthrough-auth:%d:%s',
            $playlist->id,
            $credentialHash
        );

        /*
         * Successful authentications are cached for a short period so Xtream
         * clients making several API requests do not re-authenticate upstream
         * on every request.
         */
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            if (($cached['authenticated'] ?? false) !== true) {
                return null;
            }

            return [
                'playlist' => $playlist,
                'provider_url' => $cached['provider_url'] ?? null,
                'user_info' => is_array($cached['user_info'] ?? null)
                    ? $cached['user_info']
                    : [],
                'server_info' => is_array($cached['server_info'] ?? null)
                    ? $cached['server_info']
                    : [],
            ];
        }

        /*
         * Protect the upstream provider against credential probing.
         *
         * One limiter protects the source IP globally, while the second prevents
         * repeated attempts against the same username from that IP.
         */
        $clientIp = request()->ip() ?: 'unknown';

        $ipRateKey = 'provider-passthrough-auth-ip:'.hash(
            'sha256',
            $playlist->id.'|'.$clientIp
        );

        $userRateKey = 'provider-passthrough-auth-user:'.hash(
            'sha256',
            $playlist->id.'|'.$clientIp.'|'.$username
        );

        if (
            RateLimiter::tooManyAttempts($ipRateKey, 60)
            || RateLimiter::tooManyAttempts($userRateKey, 10)
        ) {
            return null;
        }

        RateLimiter::hit($ipRateKey, 60);
        RateLimiter::hit($userRateKey, 60);

        $verify = ! ($playlist->disable_ssl_verification ?? false);
        $userAgent = $playlist->user_agent ?: 'VLC/3.0.21 LibVLC/3.0.21';

        $providerUrls = collect($playlist->getOrderedXtreamUrls())
            ->map(fn ($url) => $this->normalizeProviderUrl((string) $url))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($providerUrls === []) {
            Cache::put($cacheKey, [
                'authenticated' => false,
            ], 10);

            return null;
        }

        /*
         * Query configured Xtream URLs concurrently rather than waiting for each
         * timeout sequentially.
         */
        try {
            $responses = Http::pool(function (Pool $pool) use (
                $providerUrls,
                $username,
                $password,
                $verify,
                $userAgent
            ) {
                $requests = [];

                foreach ($providerUrls as $index => $providerUrl) {
                    $requests[] = $pool
                        ->as((string) $index)
                        ->connectTimeout(4)
                        ->timeout(8)
                        ->withOptions([
                            'verify' => $verify,
                        ])
                        ->withHeaders([
                            'User-Agent' => $userAgent,
                        ])
                        ->get($providerUrl.'/player_api.php', [
                            'username' => $username,
                            'password' => $password,
                        ]);
                }

                return $requests;
            });
        } catch (Throwable) {
            Cache::put($cacheKey, [
                'authenticated' => false,
            ], 10);

            return null;
        }

        /*
         * Check results in the configured provider URL order, preserving the
         * existing primary/fallback preference even though requests ran in parallel.
         */
        foreach ($providerUrls as $index => $providerUrl) {
            $response = $responses[(string) $index] ?? null;

            if (! $response instanceof Response || ! $response->ok()) {
                continue;
            }

            $data = $response->json();

            if (! is_array($data)) {
                continue;
            }

            $userInfo = $data['user_info'] ?? null;

            if (! is_array($userInfo)) {
                continue;
            }

            if ((int) ($userInfo['auth'] ?? 0) !== 1) {
                continue;
            }

            $status = strtolower(trim((string) ($userInfo['status'] ?? '')));

            if (in_array($status, ['banned', 'disabled', 'expired'], true)) {
                continue;
            }

            $expDate = $userInfo['exp_date'] ?? null;

            if (
                $expDate !== null
                && $expDate !== ''
                && is_numeric($expDate)
                && (int) $expDate > 0
                && (int) $expDate <= time()
            ) {
                continue;
            }

            $serverInfo = is_array($data['server_info'] ?? null)
                ? $data['server_info']
                : [];

            $cachedResult = [
                'authenticated' => true,
                'provider_url' => $providerUrl,
                'user_info' => $userInfo,
                'server_info' => $serverInfo,
            ];

            Cache::put($cacheKey, $cachedResult, 60);

            return [
                'playlist' => $playlist,
                'provider_url' => $providerUrl,
                'user_info' => $userInfo,
                'server_info' => $serverInfo,
            ];
        }

        /*
         * Briefly cache failed credentials too, preventing the same invalid login
         * from repeatedly hitting the upstream provider.
         */
        Cache::put($cacheKey, [
            'authenticated' => false,
        ], 10);

        return null;
    }

    public function buildLiveUrl(
        Playlist $playlist,
        Channel $channel,
        string $username,
        string $password,
        ?string $providerUrl = null
    ): ?string {
        $providerUrl = $this->resolveProviderUrl($playlist, $providerUrl);
        $sourceId = trim((string) $channel->source_id);

        if (! $providerUrl || $sourceId === '') {
            return null;
        }

        $extension = $this->normalizeExtension(
            $playlist->xtream_config['output'] ?? 'ts',
            'ts'
        );

        return $providerUrl
            .'/live/'
            .rawurlencode($username)
            .'/'
            .rawurlencode($password)
            .'/'
            .rawurlencode($sourceId)
            .'.'
            .$extension;
    }

    public function buildTimeshiftUrl(
        Playlist $playlist,
        Channel $channel,
        string $username,
        string $password,
        int $duration,
        string $date,
        ?string $providerUrl = null
    ): ?string {
        $providerUrl = $this->resolveProviderUrl($playlist, $providerUrl);
        $sourceId = trim((string) $channel->source_id);

        if (! $providerUrl || $sourceId === '') {
            return null;
        }

        $duration = max(1, $duration);

        $providerTimezone = $playlist->server_timezone
            ?? $playlist->xtream_status['server_info']['timezone']
            ?? 'Etc/UTC';

        try {
            if (ctype_digit($date)) {
                $stamp = Carbon::createFromTimestampUTC((int) $date)
                    ->setTimezone($providerTimezone)
                    ->format('Y-m-d:H-i');
            } else {
                $normalizedDate = preg_replace(
                    '/^(\d{4}-\d{2}-\d{2}:\d{2}-\d{2})-\d{2}$/',
                    '$1',
                    $date
                );

                $stamp = Carbon::createFromFormat(
                    'Y-m-d:H-i',
                    $normalizedDate,
                    config('app.timezone', 'UTC')
                )
                    ->setTimezone($providerTimezone)
                    ->format('Y-m-d:H-i');
            }
        } catch (Throwable) {
            return null;
        }

        $extension = $this->normalizeExtension(
            $playlist->xtream_config['output'] ?? 'ts',
            'ts'
        );

        return $providerUrl
            .'/timeshift/'
            .rawurlencode($username)
            .'/'
            .rawurlencode($password)
            .'/'
            .$duration
            .'/'
            .$stamp
            .'/'
            .rawurlencode($sourceId)
            .'.'
            .$extension;
    }

    public function buildVodUrl(
        Playlist $playlist,
        Channel $channel,
        string $username,
        string $password,
        ?string $providerUrl = null
    ): ?string {
        $providerUrl = $this->resolveProviderUrl($playlist, $providerUrl);
        $sourceId = trim((string) $channel->source_id);

        if (! $providerUrl || $sourceId === '') {
            return null;
        }

        $extension = $this->normalizeExtension(
            $channel->container_extension ?? 'mp4',
            'mp4'
        );

        return $providerUrl
            .'/movie/'
            .rawurlencode($username)
            .'/'
            .rawurlencode($password)
            .'/'
            .rawurlencode($sourceId)
            .'.'
            .$extension;
    }

    public function buildSeriesUrl(
        Playlist $playlist,
        Episode $episode,
        string $username,
        string $password,
        ?string $providerUrl = null
    ): ?string {
        $providerUrl = $this->resolveProviderUrl($playlist, $providerUrl);
        $sourceId = trim((string) $episode->source_episode_id);

        if (! $providerUrl || $sourceId === '') {
            return null;
        }

        $extension = $this->normalizeExtension(
            $episode->container_extension ?? 'mp4',
            'mp4'
        );

        return $providerUrl
            .'/series/'
            .rawurlencode($username)
            .'/'
            .rawurlencode($password)
            .'/'
            .rawurlencode($sourceId)
            .'.'
            .$extension;
    }

    private function resolveProviderUrl(
        Playlist $playlist,
        ?string $providerUrl = null
    ): ?string {
        if ($providerUrl) {
            return $this->normalizeProviderUrl($providerUrl);
        }

        $urls = $playlist->getOrderedXtreamUrls();

        if (empty($urls)) {
            return null;
        }

        return $this->normalizeProviderUrl($urls[0]);
    }

    private function normalizeExtension(mixed $extension, string $default): string
    {
        $extension = strtolower(
            ltrim(trim((string) $extension), '.')
        );

        if ($extension === '' || ! preg_match('/^[a-z0-9]+$/i', $extension)) {
            return $default;
        }

        return $extension;
    }

    private function normalizeProviderUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (! Str::startsWith($url, ['http://', 'https://'])) {
            $url = 'http://'.$url;
        }

        return rtrim($url, '/');
    }
}
