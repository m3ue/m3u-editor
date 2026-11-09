<?php

namespace App\Services\WatchHistory;

use App\Providers\VersionServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * SIMKL API with AUTH V2 (device flow, no client secret), like the M3U TV
 * app's SimklService. SIMKL counts requests against a per-user daily quota
 * (500 on a free account) and holds a 20 second per-user lock after each
 * write, so callers send one event per viewing and retry a locked write.
 */
class SimklClient extends WatchHistoryClient
{
    private const BASE_URL = 'https://api.simkl.com';

    private const APP_NAME = 'm3u-editor';

    /**
     * Access tokens last 7 days; used when a token response omits `expires_in`.
     */
    private const DEFAULT_TOKEN_LIFETIME = 604800;

    /**
     * SIMKL's per-user write lock is 20 seconds.
     */
    public const WRITE_LOCK_SECONDS = 21;

    public function startDeviceAuth(): array
    {
        $pkce = $this->pkcePair();

        $response = $this->http()->asForm()->post('/oauth2/device', [
            'client_id' => $this->clientId(),
            'scope' => 'media:read media:write',
            'code_challenge' => $pkce['code_challenge'],
            'code_challenge_method' => 'S256',
        ]);

        if (! $response->successful()) {
            throw new WatchHistoryException(__('SIMKL did not start the sign-in (HTTP :status).', ['status' => $response->status()]));
        }

        return [
            'device_code' => (string) $response->json('device_code'),
            'user_code' => (string) $response->json('user_code'),
            'verification_url' => (string) ($response->json('verification_uri_complete') ?: $response->json('verification_uri') ?: 'https://simkl.com/pin'),
            'expires_in' => (int) ($response->json('expires_in') ?: 900),
            'interval' => (int) ($response->json('interval') ?: 5),
            'code_verifier' => $pkce['code_verifier'],
        ];
    }

    public function pollDeviceAuth(string $deviceCode, string $codeVerifier): string
    {
        $response = $this->http()->asForm()->post('/oauth2/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $this->clientId(),
            'device_code' => $deviceCode,
            'code_verifier' => $codeVerifier,
        ]);

        if ($response->successful()) {
            $this->saveTokens($response->json() ?? [], self::DEFAULT_TOKEN_LIFETIME);
            $this->saveAccountName();

            return 'connected';
        }

        return match ($response->json('error')) {
            'authorization_pending' => 'pending',
            'slow_down' => 'slow_down',
            'access_denied' => 'denied',
            default => 'expired',
        };
    }

    public function scrobble(string $action, WatchHistoryItem $item): void
    {
        $response = $this->authorized(fn (string $token): Response => $this->http($token)->post("/scrobble/{$action}", [
            'progress' => round($item->progress ?? 0, 2),
            ...$item->toScrobblePayload(),
        ]));

        // 409: this title was already marked watched within the last hour.
        if ($response->successful() || $response->status() === 409) {
            return;
        }

        $this->throwFor($response);
    }

    public function fetchWatched(): array
    {
        $data = $this->getJson('/sync/all-items', [
            'extended' => 'full',
            'episode_watched_at' => 'yes',
        ]);

        $items = [];

        foreach ($data['movies'] ?? [] as $entry) {
            if (($entry['status'] ?? null) !== 'completed') {
                continue;
            }
            $movie = $entry['movie'] ?? [];
            $item = self::itemFromRemote('movie', $movie, $movie['ids'] ?? [], watchedAt: $entry['last_watched_at'] ?? null);
            if ($item) {
                $items[] = $item;
            }
        }

        foreach (['shows', 'anime'] as $bucket) {
            foreach ($data[$bucket] ?? [] as $entry) {
                $show = $entry['show'] ?? [];
                foreach ($entry['seasons'] ?? [] as $season) {
                    foreach ($season['episodes'] ?? [] as $episode) {
                        $item = self::itemFromRemote(
                            'episode',
                            $show,
                            $show['ids'] ?? [],
                            season: (int) ($season['number'] ?? 0),
                            episode: (int) ($episode['number'] ?? 0),
                            watchedAt: $episode['watched_at'] ?? $entry['last_watched_at'] ?? null,
                        );
                        if ($item) {
                            $items[] = $item;
                        }
                    }
                }
            }
        }

        return $items;
    }

    public function fetchPlayback(): array
    {
        return self::playbackItems($this->getJson('/sync/playback'));
    }

    public function revoke(): void
    {
        $token = $this->integration->refresh_token ?: $this->integration->access_token;
        if (! $token) {
            return;
        }

        try {
            $this->http()->asForm()->post('/oauth2/revoke', [
                'client_id' => $this->clientId(),
                'token' => $token,
            ]);
        } catch (ConnectionException) {
            // Best effort: the local tokens are cleared regardless.
        }
    }

    protected function refreshTokens(): void
    {
        $refreshToken = $this->integration->refresh_token;
        if (! $refreshToken) {
            $this->signOut(__('SIMKL has no refresh token saved. Reconnect the account.'));
        }

        try {
            $response = $this->http()->asForm()->post('/oauth2/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $this->clientId(),
                'refresh_token' => $refreshToken,
            ]);
        } catch (ConnectionException $exception) {
            throw new WatchHistoryException($exception->getMessage(), retryAfter: 60);
        }

        $this->handleRefreshResponse($response, self::DEFAULT_TOKEN_LIFETIME);
    }

    protected function fetchAccountName(): ?string
    {
        $response = $this->authorized(fn (string $token): Response => $this->http($token)->get('/users/settings'));

        return $response->successful() ? $response->json('user.name') : null;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<int|string, mixed>
     */
    private function getJson(string $path, array $query = []): array
    {
        $response = $this->authorized(fn (string $token): Response => $this->http($token)->get($path, $query));

        if (! $response->successful()) {
            $this->throwFor($response);
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    protected function throwFor(Response $response): never
    {
        $error = $response->json('error');

        // 400 RATE_LIMIT is SIMKL's 20 second per-user write lock, not a quota.
        $retryAfter = match (true) {
            $response->status() === 400 && $error === 'RATE_LIMIT' => self::WRITE_LOCK_SECONDS,
            $response->status() === 429 => max(1, (int) ($response->header('Retry-After') ?: ($error === 'rate_limit' ? 2 : 3600))),
            $response->serverError() => 300,
            default => null,
        };

        throw new WatchHistoryException(
            __('SIMKL returned HTTP :status:error.', ['status' => $response->status(), 'error' => $error ? " ({$error})" : '']),
            retryAfter: $retryAfter,
        );
    }

    private function clientId(): string
    {
        return (string) config('services.simkl.client_id');
    }

    protected function http(?string $accessToken = null): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->asJson()
            ->timeout(60)
            ->withUserAgent($this->userAgent())
            ->withQueryParameters([
                'client_id' => $this->clientId(),
                'app-name' => self::APP_NAME,
                'app-version' => VersionServiceProvider::getVersion(),
            ])
            ->when($accessToken, fn (PendingRequest $request) => $request->withToken($accessToken));
    }
}
