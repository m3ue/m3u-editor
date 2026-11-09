<?php

namespace App\Services\WatchHistory;

use App\Enums\WatchHistoryProvider;
use App\Models\WatchHistoryIntegration;
use App\Providers\VersionServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Talks to one Trakt or SIMKL account: device sign-in, token refresh,
 * scrobbles, and reading or adding watch history. Ported from the M3U TV
 * app's TraktService / SimklService so both sides behave alike.
 */
abstract class WatchHistoryClient
{
    /**
     * A stop at or past this percentage marks a title watched on both
     * services; below it, the position is saved for resuming.
     */
    public const WATCHED_THRESHOLD = 80.0;

    public function __construct(protected WatchHistoryIntegration $integration) {}

    public static function make(WatchHistoryIntegration $integration): self
    {
        return match ($integration->provider) {
            WatchHistoryProvider::Trakt => new TraktClient($integration),
            WatchHistoryProvider::Simkl => new SimklClient($integration),
        };
    }

    /**
     * Start the device sign-in. The user enters `user_code` at
     * `verification_url`; pass `device_code` and `code_verifier` back to
     * pollDeviceAuth() until it reports 'connected'.
     *
     * @return array{device_code: string, user_code: string, verification_url: string, expires_in: int, interval: int, code_verifier: string}
     */
    abstract public function startDeviceAuth(): array;

    /**
     * Check once whether the user approved the code. On approval the tokens
     * and account name are saved to the integration.
     *
     * @return 'connected'|'pending'|'slow_down'|'expired'|'denied'
     */
    abstract public function pollDeviceAuth(string $deviceCode, string $codeVerifier): string;

    /**
     * Report playback. `$action` is 'pause' (saves resumable progress) or
     * 'stop' (at or past WATCHED_THRESHOLD, marks the title watched).
     */
    abstract public function scrobble(string $action, WatchHistoryItem $item): void;

    /**
     * Every movie and episode the account has watched.
     *
     * @return list<WatchHistoryItem>
     */
    abstract public function fetchWatched(): array;

    /**
     * Movies and episodes the account stopped part-way through, with
     * `progress` as a percentage.
     *
     * @return list<WatchHistoryItem>
     */
    abstract public function fetchPlayback(): array;

    /**
     * Invalidate the tokens at the provider. Best effort: the local copy is
     * cleared either way.
     */
    abstract public function revoke(): void;

    abstract protected function refreshTokens(): void;

    abstract protected function fetchAccountName(): ?string;

    /**
     * A request to the service's API, authenticated when a token is given.
     */
    abstract protected function http(?string $accessToken = null): PendingRequest;

    /**
     * Turn an unsuccessful response into a WatchHistoryException, with
     * `retryAfter` set when the request can be sent again later.
     */
    abstract protected function throwFor(Response $response): never;

    /**
     * Confirm the saved sign-in still works by reading the account, which
     * also refreshes an expiring token. Returns the account name and saves
     * it. Throws a signed-out WatchHistoryException (and marks the account
     * Not connected) when the service rejects the sign-in.
     */
    public function testConnection(): string
    {
        $name = $this->fetchAccountName();

        if (blank($name)) {
            throw new WatchHistoryException(__(':provider did not return the account. Try again later.', [
                'provider' => $this->integration->provider->getLabel(),
            ]));
        }

        $this->integration->forceFill(['account_name' => $name, 'last_error' => null])->save();

        return $name;
    }

    public function integration(): WatchHistoryIntegration
    {
        return $this->integration;
    }

    /**
     * Mark titles watched at their `watchedAt` time. Returns how many movies
     * and episodes the service recognised.
     *
     * @param  list<WatchHistoryItem>  $items
     */
    public function addToHistory(array $items): int
    {
        if ($items === []) {
            return 0;
        }

        $response = $this->authorized(fn (string $token): Response => $this->http($token)->post('/sync/history', self::historyPayload($items)));

        if (! $response->successful()) {
            $this->throwFor($response);
        }

        return (int) $response->json('added.movies', 0) + (int) $response->json('added.episodes', 0);
    }

    /**
     * @return array{code_verifier: string, code_challenge: string}
     */
    protected function pkcePair(): array
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return ['code_verifier' => $verifier, 'code_challenge' => $challenge];
    }

    /**
     * Store a token response. The account name is looked up separately by
     * saveAccountName() once a sign-in completes.
     *
     * @param  array<string, mixed>  $data
     */
    protected function saveTokens(array $data, int $defaultLifetimeSeconds): void
    {
        $accessToken = (string) ($data['access_token'] ?? '');
        if ($accessToken === '') {
            throw new WatchHistoryException(__('The sign-in response had no access token.'));
        }

        $this->integration->forceFill([
            'access_token' => $accessToken,
            'refresh_token' => filled($data['refresh_token'] ?? null) ? $data['refresh_token'] : $this->integration->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? $defaultLifetimeSeconds)),
            'last_error' => null,
        ])->save();
    }

    protected function saveAccountName(): void
    {
        try {
            $name = $this->fetchAccountName();
        } catch (WatchHistoryException|ConnectionException) {
            return;
        }

        if (filled($name)) {
            $this->integration->forceFill(['account_name' => $name])->save();
        }
    }

    /**
     * Refresh an access token that is about to expire.
     */
    protected function ensureFreshToken(): void
    {
        if (! $this->integration->isConnected()) {
            throw new WatchHistoryException(__('This account is not connected.'), signedOut: true);
        }

        $expiresAt = $this->integration->token_expires_at;
        if ($expiresAt && $expiresAt->lte(now()->addMinutes(5))) {
            $this->refreshTokens();
        }
    }

    /**
     * Send an authenticated request, refreshing and retrying once on a 401.
     *
     * @param  callable(string): Response  $send  Sends the request with the given access token
     */
    protected function authorized(callable $send): Response
    {
        $this->ensureFreshToken();

        try {
            $response = $send($this->integration->access_token);
            if ($response->status() === 401) {
                $this->refreshTokens();
                $response = $send($this->integration->access_token);
            }
        } catch (ConnectionException $exception) {
            throw new WatchHistoryException($exception->getMessage(), retryAfter: 60);
        }

        if ($response->status() === 401) {
            $this->signOutRejected();
        }

        return $response;
    }

    /**
     * Handle a refresh-token response: save new tokens, or sign out when the
     * provider rejected the refresh token. A network failure keeps the
     * session so the next attempt can try again.
     */
    protected function handleRefreshResponse(Response $response, int $defaultLifetimeSeconds): void
    {
        if ($response->successful()) {
            $this->saveTokens($response->json() ?? [], $defaultLifetimeSeconds);

            return;
        }

        if (in_array($response->status(), [400, 401, 403], true)) {
            $this->signOutRejected();
        }

        throw new WatchHistoryException(__(':provider could not refresh the sign-in (HTTP :status).', [
            'provider' => $this->integration->provider->getLabel(),
            'status' => $response->status(),
        ]), retryAfter: 300);
    }

    protected function signOut(string $reason): never
    {
        $this->integration->markSignedOut($reason);

        throw new WatchHistoryException($reason, signedOut: true);
    }

    protected function signOutRejected(): never
    {
        $this->signOut(__(':provider rejected the saved sign-in. Reconnect the account.', ['provider' => $this->integration->provider->getLabel()]));
    }

    protected function userAgent(): string
    {
        return 'M3U-Editor/'.VersionServiceProvider::getVersion();
    }

    /**
     * @param  array<string, mixed>  $ids
     */
    protected static function itemFromRemote(string $type, array $media, array $ids, ?int $season = null, ?int $episode = null, ?float $progress = null, ?string $watchedAt = null): ?WatchHistoryItem
    {
        $normalized = WatchHistoryItem::normalizeIds($ids);
        if ($normalized === []) {
            return null;
        }

        return new WatchHistoryItem(
            type: $type,
            ids: $normalized,
            title: $media['title'] ?? null,
            year: isset($media['year']) ? (int) $media['year'] : null,
            season: $season,
            episode: $episode,
            progress: $progress,
            // Eloquent stores datetimes as wall-clock time without converting,
            // so bring the services' UTC timestamps into the app's timezone.
            watchedAt: $watchedAt ? now()->parse($watchedAt)->setTimezone(config('app.timezone')) : null,
        );
    }

    /**
     * Read a `/sync/playback` response, which both services return in the
     * same shape (SIMKL may put an anime's show under `anime`).
     *
     * @param  array<int|string, mixed>  $entries
     * @return list<WatchHistoryItem>
     */
    protected static function playbackItems(array $entries): array
    {
        $items = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $isEpisode = ($entry['type'] ?? '') === 'episode';
            $media = $isEpisode ? ($entry['show'] ?? $entry['anime'] ?? []) : ($entry['movie'] ?? []);
            $item = self::itemFromRemote(
                $isEpisode ? 'episode' : 'movie',
                $media,
                $media['ids'] ?? [],
                season: $isEpisode ? (int) ($entry['episode']['season'] ?? 0) : null,
                episode: $isEpisode ? (int) ($entry['episode']['number'] ?? 0) : null,
                progress: (float) ($entry['progress'] ?? 0),
                watchedAt: $entry['paused_at'] ?? null,
            );
            if ($item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Group items into the `{movies, shows}` body both services' history
     * endpoints take.
     *
     * @param  list<WatchHistoryItem>  $items
     * @return array{movies: list<array<string, mixed>>, shows: list<array<string, mixed>>}
     */
    protected static function historyPayload(array $items): array
    {
        $movies = [];
        $shows = [];

        foreach ($items as $item) {
            $watchedAt = ($item->watchedAt ?? now())->toIso8601ZuluString();

            if (! $item->isEpisode()) {
                $movies[] = ['ids' => $item->ids, 'watched_at' => $watchedAt];

                continue;
            }

            $showKey = json_encode($item->ids);
            $shows[$showKey] ??= ['ids' => $item->ids, 'seasons' => []];
            $shows[$showKey]['seasons'][$item->season] ??= ['number' => $item->season, 'episodes' => []];
            $shows[$showKey]['seasons'][$item->season]['episodes'][] = ['number' => $item->episode, 'watched_at' => $watchedAt];
        }

        return [
            'movies' => $movies,
            'shows' => array_values(array_map(function (array $show): array {
                $show['seasons'] = array_values($show['seasons']);

                return $show;
            }, $shows)),
        ];
    }
}
