<?php

namespace App\Filament\GuestPanel\Pages\Concerns;

use App\Facades\PlaylistFacade;
use App\Models\DvrSetting;
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
     * Resolve the DvrSetting for the current guest's assigned playlist.
     * Only regular Playlists (and PlaylistAliases backed by one) have DVR support.
     */
    public static function getDvrSetting(): ?DvrSetting
    {
        $uuid = static::getCurrentUuid();
        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);

        if ($playlist instanceof Playlist) {
            return $playlist->dvrSetting;
        }

        if ($playlist instanceof PlaylistAlias && $playlist->playlist_id) {
            return $playlist->playlist?->dvrSetting;
        }

        return null;
    }

    /**
     * Whether the current guest is permitted to use DVR features.
     * Requires dvr_enabled on their PlaylistAuth AND the playlist must have a DvrSetting.
     */
    protected static function guestCanAccessDvr(): bool
    {
        $auth = static::getCurrentPlaylistAuth();
        if (! $auth || ! $auth->dvr_enabled) {
            return false;
        }

        return static::getDvrSetting() !== null;
    }
}
