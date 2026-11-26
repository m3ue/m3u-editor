<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistProfile;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Provider Profiles "Redirect" mode.
 *
 * Instead of routing a pooled stream through m3u-proxy, pick an account with a
 * free slot and hand the player a redirect to that account's provider URL. The
 * video never passes through this server, so the editor cannot see when the
 * stream ends. Two ways of counting busy slots make up for that:
 *
 * - Polling (Xtream only): ask each account's player_api for active_cons, cached
 *   for profiles_redirect_cache_seconds. Redirects handed out in the last
 *   few seconds (before the provider reports them) are added on top.
 * - Counting: every redirect occupies a slot for profiles_redirect_hold_seconds.
 *
 * In both modes a client (IP + username) holds at most one slot per playlist: a
 * new request from the same client replaces its previous one, so changing
 * channels does not use up the pool.
 */
class ProviderProfileRedirectService
{
    /**
     * Polling mode: how long (beyond the provider count cache) a freshly handed-out
     * redirect is still counted locally, covering the gap before the provider's
     * active_cons includes it.
     */
    public const POLL_CONNECT_GRACE_SECONDS = 30;

    /**
     * How long a client's assignment is remembered for the "same client replaces
     * its previous stream" rule in polling mode.
     */
    protected const ASSIGNMENT_RETENTION_SECONDS = 86400;

    /**
     * Timeout for each provider player_api poll (seconds).
     */
    protected const POLL_TIMEOUT_SECONDS = 3;

    /**
     * Max wait for the per-playlist profile selection lock (seconds). Shared with
     * ProfileService so proxied and redirected selections cannot race each other.
     */
    protected const LOCK_TIMEOUT_SECONDS = 2;

    /**
     * The source playlist whose provider profiles should serve this item in
     * Redirect mode, or null when Redirect mode does not apply. Mirrors the
     * source playlist resolution in M3uProxyService (the streamed playlist when it
     * pools profiles itself, otherwise the item's own source playlist).
     */
    public function resolveRedirectPlaylist(mixed $playlist, Channel|Episode $item): ?Playlist
    {
        if ($playlist instanceof Playlist && $playlist->profiles_enabled) {
            return $playlist->usesProfileRedirect() ? $playlist : null;
        }

        $sourcePlaylist = $item->playlist;

        return $sourcePlaylist instanceof Playlist && $sourcePlaylist->usesProfileRedirect()
            ? $sourcePlaylist
            : null;
    }

    /**
     * Select a profile with a free slot, record the assignment, and return the
     * provider URL for the item using that profile's credentials. Returns null when
     * every profile is full (or the selection lock could not be acquired).
     */
    public function resolveStreamUrl(Playlist $sourcePlaylist, mixed $streamedPlaylist, Channel|Episode $item, ?string $clientIdentifier): ?string
    {
        $profile = $this->assignProfile($sourcePlaylist, $clientIdentifier);

        if (! $profile) {
            return null;
        }

        $url = $item instanceof Episode
            ? PlaylistUrlService::getEpisodeUrl($item, $profile)
            : PlaylistUrlService::getChannelUrl($item, $profile);

        // Same alias provider URL swap the proxy path applies to profile URLs.
        if ($streamedPlaylist instanceof PlaylistAlias && $url !== '') {
            $url = $streamedPlaylist->replaceProviderUrl($url, $item->getEffectivePlaylist()?->xtream_config);
        }

        return $url;
    }

    /**
     * Pick a profile with a free slot and record the assignment.
     */
    public function assignProfile(Playlist $playlist, ?string $clientIdentifier): ?PlaylistProfile
    {
        if (! $playlist->usesProfileRedirect()) {
            return null;
        }

        $profiles = $playlist->enabledProfiles()->get();

        if ($profiles->isEmpty()) {
            return null;
        }

        // Network calls (provider polls or the proxy count) happen before the lock;
        // the lock only guards the local assignment bookkeeping.
        $baseCounts = $this->fetchBaseCounts($playlist, $profiles);

        $lock = Cache::lock("profile_select_lock:playlist:{$playlist->id}", static::LOCK_TIMEOUT_SECONDS);

        try {
            $lock->block(static::LOCK_TIMEOUT_SECONDS);
        } catch (LockTimeoutException) {
            Log::warning('Timed out waiting for profile selection lock (redirect mode)', [
                'playlist_id' => $playlist->id,
            ]);

            return null;
        }

        try {
            $counts = $this->connectionCounts($playlist, $profiles, $baseCounts, $clientIdentifier);

            $profile = ProfileService::selectProfile(
                $playlist,
                forceSelect: (bool) $playlist->bypass_provider_limits,
                clientIdentifier: $clientIdentifier,
                proxyCounts: $counts,
            );

            if ($profile) {
                $this->recordAssignment($playlist, $profiles, $profile, $clientIdentifier);

                if ($clientIdentifier !== null && $playlist->enable_provider_affinity) {
                    ProfileService::storeClientAffinity($clientIdentifier, $playlist->id, $profile->id);
                }

                Log::info('Redirect mode assigned provider profile', [
                    'playlist_id' => $playlist->id,
                    'profile_id' => $profile->id,
                    'connections' => $counts[(string) $profile->id] ?? 0,
                    'max_streams' => $profile->effective_max_streams,
                    'polling' => $playlist->pollsProviderForProfileRedirect(),
                ]);
            }

            return $profile;
        } finally {
            $lock->release();
        }
    }

    /**
     * Connection counts for the proxy path of a Redirect-mode playlist (DVR, the
     * in-app player and channels with the proxy forced on still go through
     * m3u-proxy), so proxied and redirected streams share one view of the pool.
     *
     * @param  Collection<int, PlaylistProfile>  $profiles
     * @return array<string, int>
     */
    public function countsForProxySelection(Playlist $playlist, Collection $profiles): array
    {
        return $this->connectionCounts($playlist, $profiles, $this->fetchBaseCounts($playlist, $profiles), null);
    }

    /**
     * Counts that need a network call: the provider's active_cons per profile in
     * polling mode, or m3u-proxy's active streams per profile in counting mode.
     * A null entry means the provider could not be polled.
     *
     * @param  Collection<int, PlaylistProfile>  $profiles
     * @return array<string, int|null>
     */
    public function fetchBaseCounts(Playlist $playlist, Collection $profiles): array
    {
        if ($playlist->pollsProviderForProfileRedirect()) {
            $cacheSeconds = max(1, (int) $playlist->profiles_redirect_cache_seconds);

            return $profiles
                ->mapWithKeys(fn (PlaylistProfile $profile): array => [
                    (string) $profile->id => $this->providerActiveConnections($profile, $cacheSeconds),
                ])
                ->all();
        }

        $profileIds = $profiles->pluck('id')->map(fn ($id): string => (string) $id)->all();

        return array_map('intval', M3uProxyService::getActiveStreamsCountsBatch('provider_profile_id', $profileIds));
    }

    /**
     * Combine the network counts with the locally recorded redirects.
     *
     * Polling: provider active_cons + other clients' redirects from the last few
     * seconds. When this client was the most recent one sent to a profile, its old
     * stream (still in active_cons) is about to be replaced, so it is not counted.
     * If a provider poll failed, that profile falls back to counting mode.
     *
     * Counting: proxied streams + other clients' redirects within the hold period.
     *
     * @param  Collection<int, PlaylistProfile>  $profiles
     * @param  array<string, int|null>  $baseCounts
     * @return array<string, int>
     */
    public function connectionCounts(Playlist $playlist, Collection $profiles, array $baseCounts, ?string $clientIdentifier): array
    {
        $now = now()->getTimestamp();
        $polling = $playlist->pollsProviderForProfileRedirect();
        $holdSeconds = max(1, (int) $playlist->profiles_redirect_hold_seconds);
        $graceSeconds = max(1, (int) $playlist->profiles_redirect_cache_seconds) + static::POLL_CONNECT_GRACE_SECONDS;
        $counts = [];

        foreach ($profiles as $profile) {
            $key = (string) $profile->id;
            $assignments = $this->assignments($profile);
            $others = array_filter(
                $assignments,
                fn (string $client): bool => $client !== $clientIdentifier,
                ARRAY_FILTER_USE_KEY,
            );
            $providerCount = $baseCounts[$key] ?? null;

            if ($polling && $providerCount !== null) {
                $recentOthers = count(array_filter($others, fn (int $assignedAt): bool => $assignedAt > $now - $graceSeconds));
                $replacesOwnStream = $clientIdentifier !== null
                    && isset($assignments[$clientIdentifier])
                    && $assignments[$clientIdentifier] >= max([0, ...array_values($others)]);

                $counts[$key] = max(0, $providerCount - ($replacesOwnStream ? 1 : 0)) + $recentOthers;

                continue;
            }

            $heldByOthers = count(array_filter($others, fn (int $assignedAt): bool => $assignedAt > $now - $holdSeconds));
            $counts[$key] = (int) ($providerCount ?? 0) + $heldByOthers;
        }

        return $counts;
    }

    /**
     * The provider's reported active connections for a profile, cached for the
     * playlist's configured window. Null when the provider could not be polled.
     */
    public function providerActiveConnections(PlaylistProfile $profile, int $cacheSeconds): ?int
    {
        $cacheKey = "profile_redirect_active_cons:{$profile->id}";
        $cached = Cache::get($cacheKey);

        if (is_int($cached)) {
            return $cached;
        }

        $xtreamConfig = $profile->xtream_config;

        if (! $xtreamConfig) {
            return null;
        }

        try {
            // Single attempt: this runs while the player waits for its stream.
            $userInfo = XtreamService::make(playlist: $profile->playlist, xtream_config: $xtreamConfig, retryLimit: 1)
                ->userInfo(timeout: static::POLL_TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            Log::warning('Failed to poll provider connections for profile', [
                'profile_id' => $profile->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        $activeConnections = $userInfo['user_info']['active_cons'] ?? null;

        if (! is_numeric($activeConnections)) {
            Log::warning('Provider did not report active connections for profile', [
                'profile_id' => $profile->id,
            ]);

            return null;
        }

        $activeConnections = (int) $activeConnections;
        Cache::put($cacheKey, $activeConnections, $cacheSeconds);

        return $activeConnections;
    }

    /**
     * Record that a client was sent to a profile, replacing the client's earlier
     * assignment on any profile of this playlist.
     *
     * @param  Collection<int, PlaylistProfile>  $profiles
     */
    protected function recordAssignment(Playlist $playlist, Collection $profiles, PlaylistProfile $profile, ?string $clientIdentifier): void
    {
        $now = now()->getTimestamp();
        $retention = max(static::ASSIGNMENT_RETENTION_SECONDS, (int) $playlist->profiles_redirect_hold_seconds);
        // Clients we cannot identify never replace each other.
        $member = $clientIdentifier ?? 'anonymous:'.bin2hex(random_bytes(8));

        try {
            foreach ($profiles as $otherProfile) {
                if ($otherProfile->id !== $profile->id) {
                    Redis::hdel(static::assignmentsKey($otherProfile), $member);
                }
            }

            $key = static::assignmentsKey($profile);
            Redis::hset($key, $member, $now);
            Redis::expire($key, $retention);

            $this->pruneAssignments($profile, $now - $retention);
        } catch (\Throwable $e) {
            Log::warning('Failed to record redirect profile assignment', [
                'profile_id' => $profile->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Assignments recorded for a profile, keyed by client, valued by unix time.
     *
     * @return array<string, int>
     */
    protected function assignments(PlaylistProfile $profile): array
    {
        try {
            $raw = Redis::hgetall(static::assignmentsKey($profile)) ?: [];
        } catch (\Throwable $e) {
            Log::warning('Failed to read redirect profile assignments', [
                'profile_id' => $profile->id,
                'exception' => $e->getMessage(),
            ]);

            return [];
        }

        return array_map('intval', $raw);
    }

    protected function pruneAssignments(PlaylistProfile $profile, int $olderThan): void
    {
        $stale = array_keys(array_filter($this->assignments($profile), fn (int $assignedAt): bool => $assignedAt <= $olderThan));

        if ($stale !== []) {
            Redis::hdel(static::assignmentsKey($profile), ...$stale);
        }
    }

    /**
     * Redis hash of redirect assignments for a profile (client => unix time).
     */
    public static function assignmentsKey(PlaylistProfile $profile): string
    {
        return "profile_redirect:{$profile->id}:assignments";
    }
}
