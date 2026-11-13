<?php

namespace App\Services\WatchHistory;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * MDBList API. Signs in with the OAuth device flow (a public "Device Code
 * App", no client secret), like the M3U TV app's MdblistService. Its sync
 * endpoints follow Trakt's shapes with a few differences: history is added
 * at `/sync/watched`, `/sync/watched` reads are paged, and an episode
 * scrobble nests the season and episode inside `show`.
 *
 * Every account shares one request quota across all of its apps (1000 a day
 * on a free account), so like SIMKL each viewing sends one event and bulk
 * reads only happen when the user starts an import.
 */
class MdblistClient extends WatchHistoryClient
{
    private const BASE_URL = 'https://api.mdblist.com';

    /**
     * Access tokens last 30 days; used when a token response omits `expires_in`.
     */
    private const DEFAULT_TOKEN_LIFETIME = 2592000;

    /**
     * The most entries `/sync/watched` returns per page.
     */
    private const WATCHED_PAGE_SIZE = 1000;

    protected string $historyPath = '/sync/watched';

    protected string $historyAddedKey = 'updated';

    public function startDeviceAuth(): array
    {
        $this->ensureClientId();

        $response = $this->http()->asForm()->post('/oauth/device-authorization/', [
            'client_id' => $this->clientId(),
            'scope' => 'write',
        ]);

        if (! $response->successful()) {
            throw new WatchHistoryException(__('MDBList did not start the sign-in (HTTP :status).', ['status' => $response->status()]));
        }

        return [
            'device_code' => (string) $response->json('device_code'),
            'user_code' => (string) $response->json('user_code'),
            'verification_url' => (string) ($response->json('verification_uri_complete') ?: $response->json('verification_uri') ?: 'https://mdblist.com/oauth/device/'),
            'expires_in' => (int) ($response->json('expires_in') ?: 300),
            'interval' => (int) ($response->json('interval') ?: 5),
            // The device flow doesn't use PKCE.
            'code_verifier' => '',
        ];
    }

    public function pollDeviceAuth(string $deviceCode, string $codeVerifier): string
    {
        $response = $this->http()->asForm()->post('/oauth/token/', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $this->clientId(),
            'device_code' => $deviceCode,
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
            ...self::scrobbleMedia($item),
            'progress' => round($item->progress ?? 0, 2),
        ]));

        if ($response->successful() || $response->status() === 409) {
            return;
        }

        $this->throwFor($response);
    }

    public function fetchWatched(): array
    {
        $items = [];
        $offset = 0;

        do {
            $data = $this->getJson('/sync/watched', ['limit' => self::WATCHED_PAGE_SIZE, 'offset' => $offset]);

            foreach ($data['movies'] ?? [] as $entry) {
                $movie = $entry['movie'] ?? [];
                $item = self::itemFromRemote('movie', $movie, $movie['ids'] ?? [], watchedAt: $entry['last_watched_at'] ?? null);
                if ($item) {
                    $items[] = $item;
                }
            }

            // `shows` and `seasons` are per-show summaries; `episodes` holds
            // each watched episode with its show.
            foreach ($data['episodes'] ?? [] as $entry) {
                $episode = $entry['episode'] ?? [];
                $show = $episode['show'] ?? [];
                $item = self::itemFromRemote(
                    'episode',
                    $show,
                    $show['ids'] ?? [],
                    season: (int) ($episode['season'] ?? 0),
                    episode: (int) ($episode['number'] ?? 0),
                    watchedAt: $entry['last_watched_at'] ?? null,
                );
                if ($item) {
                    $items[] = $item;
                }
            }

            $offset += self::WATCHED_PAGE_SIZE;
        } while ((bool) ($data['pagination']['has_more'] ?? false));

        return $items;
    }

    public function fetchPlayback(): array
    {
        return self::playbackItems($this->getJson('/sync/playback'));
    }

    public function revoke(): void
    {
        $token = $this->integration->access_token;
        if (! $token || blank($this->clientId())) {
            return;
        }

        try {
            $this->http()->asForm()->post('/oauth/revoke_token/', [
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
            $this->signOut(__('MDBList has no refresh token saved. Reconnect the account.'));
        }

        try {
            $response = $this->http()->asForm()->post('/oauth/token/', [
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
        $response = $this->authorized(fn (string $token): Response => $this->http($token)->get('/user'));

        return $response->successful()
            ? ($response->json('username') ?: $response->json('name'))
            : null;
    }

    protected function throwFor(Response $response): never
    {
        $retryAfter = match (true) {
            $response->status() === 429 => max(1, ((int) $response->header('Retry-After')) ?: self::secondsUntilReset($response)),
            $response->serverError() => 300,
            default => null,
        };

        $error = $response->json('error');

        throw new WatchHistoryException(
            __('MDBList returned HTTP :status:error.', ['status' => $response->status(), 'error' => is_string($error) ? " ({$error})" : '']),
            retryAfter: $retryAfter,
        );
    }

    protected function http(?string $accessToken = null): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->withUserAgent($this->userAgent())
            ->when($accessToken, fn (PendingRequest $request) => $request->withToken($accessToken));
    }

    /**
     * The `movie`, or `show` with its season and episode nested inside, that
     * MDBList's scrobble endpoints take.
     *
     * @return array<string, mixed>
     */
    private static function scrobbleMedia(WatchHistoryItem $item): array
    {
        $payload = $item->toScrobblePayload();

        if (! $item->isEpisode()) {
            return $payload;
        }

        return [
            'show' => [
                ...$payload['show'],
                'season' => ['number' => $item->season, 'episode' => ['number' => $item->episode]],
            ],
        ];
    }

    /**
     * Without a Retry-After, wait until the daily quota resets (the
     * `X-RateLimit-Reset` Unix time), capped at an hour so the job tries again.
     */
    private static function secondsUntilReset(Response $response): int
    {
        $reset = (int) $response->header('X-RateLimit-Reset');

        return $reset > 0 ? min(3600, max(1, $reset - now()->getTimestamp())) : 60;
    }

    private function ensureClientId(): void
    {
        if (blank($this->clientId())) {
            throw new WatchHistoryException(__('No MDBList client id is configured. Set MDBLIST_CLIENT_ID to the client id of an MDBList "Device Code App".'));
        }
    }

    private function clientId(): string
    {
        return (string) config('services.mdblist.client_id');
    }
}
