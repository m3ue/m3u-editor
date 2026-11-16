<?php

namespace App\Services;

use App\Facades\PlaylistFacade;
use App\Models\CustomPlaylist;
use App\Models\MediaServerIntegration;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;

/**
 * Shared AIOStreams authorization logic, used by both the Xtream API's
 * feature-advertisement response and the direct AIOStreams proxy routes so
 * the two can never drift out of sync (see #1384).
 */
class AIOStreamsAuthorizationService
{
    /**
     * Unwrap a PlaylistAlias to its effective playlist; otherwise return the
     * playlist as-is if it's one of the three playlist types that carry
     * their own AIOStreams integration assignment.
     */
    public function resolveEffectivePlaylist($playlist): Playlist|CustomPlaylist|MergedPlaylist|null
    {
        $effective = $playlist instanceof PlaylistAlias
            ? $playlist->getEffectivePlaylist()
            : $playlist;

        return $effective instanceof Playlist || $effective instanceof CustomPlaylist || $effective instanceof MergedPlaylist
            ? $effective
            : null;
    }

    /**
     * Whether the given credentials may use AIOStreams at all (i.e. an
     * enabled integration is assigned to the effective playlist and, for
     * PlaylistAuth credentials, the individual auth has it enabled).
     */
    public function isEnabled($playlist, string $authMethod, ?PlaylistAuth $playlistAuth): bool
    {
        $effectivePlaylist = $this->resolveEffectivePlaylist($playlist);

        if (! $effectivePlaylist) {
            return false;
        }

        $hasEnabledAiostreams = $effectivePlaylist->aiostreams_integration_id !== null
            && optional($effectivePlaylist->aiostreamsIntegration)->enabled;

        if (! $hasEnabledAiostreams) {
            return false;
        }

        if ($authMethod !== 'playlist_auth') {
            return true;
        }

        return (bool) $playlistAuth?->aiostreams_enabled;
    }

    /**
     * Whether the given credentials are authorized to reach the specific
     * AIOStreams integration ID (i.e. it's the one assigned to their
     * effective playlist, not just any enabled integration owned by the
     * same user).
     */
    public function isAuthorizedForIntegration($playlist, string $authMethod, ?PlaylistAuth $playlistAuth, int $integrationId): bool
    {
        if (! $this->isEnabled($playlist, $authMethod, $playlistAuth)) {
            return false;
        }

        $effectivePlaylist = $this->resolveEffectivePlaylist($playlist);

        return $effectivePlaylist?->aiostreams_integration_id === $integrationId;
    }

    /**
     * The AIOStreams integration an already-authenticated caller may reach, or
     * null when AIOStreams is turned off, the caller isn't authorized for this
     * integration ID, or the integration can't serve requests (disabled, wrong
     * type, no manifest URL).
     */
    public function authorizedIntegration($playlist, string $authMethod, ?PlaylistAuth $playlistAuth, int $integrationId): ?MediaServerIntegration
    {
        if (! config('services.aiostreams.enabled', true)) {
            return null;
        }

        if (! $this->isAuthorizedForIntegration($playlist, $authMethod, $playlistAuth, $integrationId)) {
            return null;
        }

        return MediaServerIntegration::where('id', $integrationId)
            ->where('type', 'aiostreams')
            ->where('enabled', true)
            ->whereNotNull('manifest_url')
            ->first();
    }

    /**
     * authorizedIntegration() for a raw username/password pair, for routes that
     * carry the credentials themselves rather than going through player_api.php.
     *
     * @param  Playlist|CustomPlaylist|MergedPlaylist|PlaylistAlias|null  $playlist
     *                                                                               Out-param set to the authenticated playlist when authentication
     *                                                                               succeeds, so callers can read playlist-level settings (e.g.
     *                                                                               enable_logo_proxy) without a second authenticate() round trip.
     */
    public function authorizedIntegrationForCredentials(string $username, string $password, int $integrationId, &$playlist = null): ?MediaServerIntegration
    {
        if (! config('services.aiostreams.enabled', true)) {
            return null;
        }

        $auth = PlaylistFacade::authenticate($username, $password);

        if (! $auth || $auth[0] === null || $auth[1] === 'none') {
            return null;
        }

        [$playlist, $authMethod] = $auth;

        $playlistAuth = $authMethod === 'playlist_auth'
            ? app(XtreamLoginAuthenticator::class)->activePlaylistAuth($username, $password)
            : null;

        if ($authMethod === 'playlist_auth' && ! $playlistAuth) {
            return null;
        }

        return $this->authorizedIntegration($playlist, $authMethod, $playlistAuth, $integrationId);
    }
}
