<?php

namespace App\Services\WatchHistory;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Trakt API v2. Signs in with the device flow and PKCE (no client secret),
 * like the M3U TV app's TraktService.
 */
class TraktClient extends WatchHistoryClient
{
    private const BASE_URL = 'https://api.trakt.tv';

    /**
     * Used when a token response omits `expires_in`.
     */
    private const DEFAULT_TOKEN_LIFETIME = 86400;

    public function startDeviceAuth(): array
    {
        $pkce = $this->pkcePair();

        $response = $this->http()->post('/oauth/device/code', [
            'client_id' => $this->clientId(),
            'code_challenge' => $pkce['code_challenge'],
            'code_challenge_method' => 'S256',
        ]);

        if (! $response->successful()) {
            throw new WatchHistoryException(__('Trakt did not start the sign-in (HTTP :status).', ['status' => $response->status()]));
        }

        return [
            'device_code' => (string) $response->json('device_code'),
            'user_code' => (string) $response->json('user_code'),
            'verification_url' => (string) ($response->json('verification_url') ?: 'https://trakt.tv/activate'),
            'expires_in' => (int) ($response->json('expires_in') ?: 600),
            'interval' => (int) ($response->json('interval') ?: 5),
            'code_verifier' => $pkce['code_verifier'],
        ];
    }

    public function pollDeviceAuth(string $deviceCode, string $codeVerifier): string
    {
        $response = $this->http()->post('/oauth/device/token', [
            'code' => $deviceCode,
            'client_id' => $this->clientId(),
            'code_verifier' => $codeVerifier,
        ]);

        if ($response->successful()) {
            $this->saveTokens($response->json() ?? [], self::DEFAULT_TOKEN_LIFETIME);
            $this->saveAccountName();

            return 'connected';
        }

        return match ($response->status()) {
            400 => 'pending',
            429 => 'slow_down',
            418 => 'denied',
            default => 'expired',
        };
    }

    public function scrobble(string $action, WatchHistoryItem $item): void
    {
        $response = $this->authorized(fn (string $token): Response => $this->http($token)->post("/scrobble/{$action}", [
            ...$item->toScrobblePayload(),
            'progress' => round($item->progress ?? 0, 2),
        ]));

        // 409: this title was already scrobbled moments ago.
        if ($response->successful() || $response->status() === 409) {
            return;
        }

        $this->throwFor($response);
    }

    public function fetchWatched(): array
    {
        $items = [];

        foreach ($this->getJson('/sync/watched/movies') as $entry) {
            $movie = $entry['movie'] ?? [];
            $item = self::itemFromRemote('movie', $movie, $movie['ids'] ?? [], watchedAt: $entry['last_watched_at'] ?? null);
            if ($item) {
                $items[] = $item;
            }
        }

        foreach ($this->getJson('/sync/watched/shows') as $entry) {
            $show = $entry['show'] ?? [];
            foreach ($entry['seasons'] ?? [] as $season) {
                foreach ($season['episodes'] ?? [] as $episode) {
                    $item = self::itemFromRemote(
                        'episode',
                        $show,
                        $show['ids'] ?? [],
                        season: (int) ($season['number'] ?? 0),
                        episode: (int) ($episode['number'] ?? 0),
                        watchedAt: $episode['last_watched_at'] ?? null,
                    );
                    if ($item) {
                        $items[] = $item;
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
        $token = $this->integration->access_token;
        if (! $token) {
            return;
        }

        try {
            $this->http()->post('/oauth/revoke', [
                'token' => $token,
                'client_id' => $this->clientId(),
            ]);
        } catch (ConnectionException) {
            // Best effort: the local tokens are cleared regardless.
        }
    }

    protected function refreshTokens(): void
    {
        $refreshToken = $this->integration->refresh_token;
        if (! $refreshToken) {
            $this->signOut(__('Trakt has no refresh token saved. Reconnect the account.'));
        }

        try {
            $response = $this->http()->post('/oauth/token', [
                'refresh_token' => $refreshToken,
                'client_id' => $this->clientId(),
                'grant_type' => 'refresh_token',
            ]);
        } catch (ConnectionException $exception) {
            throw new WatchHistoryException($exception->getMessage(), retryAfter: 60);
        }

        $this->handleRefreshResponse($response, self::DEFAULT_TOKEN_LIFETIME);
    }

    protected function fetchAccountName(): ?string
    {
        $response = $this->authorized(fn (string $token): Response => $this->http($token)->get('/users/settings'));

        return $response->successful()
            ? ($response->json('user.username') ?: $response->json('user.name'))
            : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getJson(string $path): array
    {
        $response = $this->authorized(fn (string $token): Response => $this->http($token)->get($path));

        if (! $response->successful()) {
            $this->throwFor($response);
        }

        $data = $response->json();

        return is_array($data) ? array_values($data) : [];
    }

    protected function throwFor(Response $response): never
    {
        $retryAfter = match (true) {
            $response->status() === 429 => max(1, (int) ($response->header('Retry-After') ?: 10)),
            $response->serverError() => 300,
            default => null,
        };

        throw new WatchHistoryException(__('Trakt returned HTTP :status.', ['status' => $response->status()]), retryAfter: $retryAfter);
    }

    private function clientId(): string
    {
        return (string) config('services.trakt.client_id');
    }

    protected function http(?string $accessToken = null): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->withUserAgent($this->userAgent())
            ->withHeaders([
                'trakt-api-version' => '2',
                'trakt-api-key' => $this->clientId(),
            ])
            ->when($accessToken, fn (PendingRequest $request) => $request->withToken($accessToken));
    }
}
