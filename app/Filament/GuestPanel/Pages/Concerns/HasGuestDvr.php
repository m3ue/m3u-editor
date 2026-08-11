<?php

namespace App\Filament\GuestPanel\Pages\Concerns;

use App\Facades\PlaylistFacade;
use App\Models\CustomPlaylist;
use App\Models\DvrSetting;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;

trait HasGuestDvr
{
    use HasPlaylist;

    /**
     * Resolve the PlaylistAuth for the current guest session.
     */
    public static function getCurrentPlaylistAuth(): ?PlaylistAuth
    {
        $credentials = static::getCurrentAuth();
        if (! $credentials) {
            return null;
        }

        return PlaylistAuth::where('username', $credentials['username'])
            ->where('password', $credentials['password'])
            ->where('enabled', true)
            ->first();
    }

    /**
     * Whether the current guest-panel session belongs to the playlist owner,
     * authenticated with their m3u-editor username and the playlist UUID as
     * the password (PlaylistService::authenticate()'s "owner_auth" fallback),
     * rather than a PlaylistAuth record. Owners have no PlaylistAuth of their
     * own, so this is the only way to recognize them in the guest panel.
     */
    protected static function isOwnerAuth(): bool
    {
        if (static::getCurrentPlaylistAuth() !== null) {
            return false;
        }

        $credentials = static::getCurrentAuth();
        if (! $credentials) {
            return false;
        }

        $result = PlaylistFacade::authenticate($credentials['username'], $credentials['password']);

        return is_array($result) && ($result[1] ?? null) === 'owner_auth';
    }

    /**
     * Resolve the DvrSetting for the current guest's assigned playlist
     * (Playlist, CustomPlaylist, MergedPlaylist, or an alias of one of those).
     */
    public static function getDvrSetting(): ?DvrSetting
    {
        $uuid = static::getCurrentUuid();
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);

        if ($playlist instanceof Playlist || $playlist instanceof CustomPlaylist || $playlist instanceof MergedPlaylist) {
            return $playlist->dvrSetting;
        }

        if ($playlist instanceof PlaylistAlias) {
            return $playlist->getEffectivePlaylist()?->dvrSetting;
        }

        return null;
    }

    /**
     * Whether the current session is permitted to use DVR features.
     *
     * Guests (PlaylistAuth) are gated by their own dvr_enabled flag. The
     * playlist owner has no PlaylistAuth record, so their access is gated by
     * the playlist-level DvrSetting::$enabled flag instead.
     */
    protected static function guestCanAccessDvr(): bool
    {
        if (! (config('dvr.dvr_enabled', true) && config('proxy.proxy_integration_enabled', true))) {
            return false;
        }

        $dvrSetting = static::getDvrSetting();
        if (! $dvrSetting) {
            return false;
        }

        $auth = static::getCurrentPlaylistAuth();
        if ($auth) {
            return (bool) $auth->dvr_enabled;
        }

        return $dvrSetting->enabled && static::isOwnerAuth();
    }
}
