<?php

namespace App\Filament\GuestPanel\Pages;

use App\Facades\PlaylistFacade;
use App\Filament\GuestPanel\Pages\Concerns\HasGuestAuth;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class GuestAioStreams extends Page
{
    use HasGuestAuth;

    protected string $view = 'filament.guest-panel.pages.aiostreams';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-play-circle';

    protected static ?int $navigationSort = 8;

    public static function getNavigationLabel(): string
    {
        return __('AIOStreams');
    }

    public function getTitle(): string|Htmlable
    {
        return __('AIOStreams');
    }

    protected static ?string $slug = 'aiostreams';

    /**
     * Only show the page if the guest is authenticated, the playlist has an
     * AIOStreams integration attached and enabled, and (when authenticated via
     * PlaylistAuth) that auth profile has AIOStreams access enabled.
     */
    public static function canAccess(): bool
    {
        if (! config('services.aiostreams.enabled', true) || ! static::isSessionAuthenticated()) {
            return false;
        }

        $uuid = static::getCurrentUuid();
        if (! $uuid) {
            return false;
        }

        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return false;
        }

        $actualPlaylist = $playlist instanceof PlaylistAlias
            ? Playlist::find($playlist->playlist_id)
            : $playlist;

        if (! $actualPlaylist || ! $actualPlaylist->aiostreams_integration_id) {
            return false;
        }

        $integration = MediaServerIntegration::query()
            ->where('id', $actualPlaylist->aiostreams_integration_id)
            ->where('type', 'aiostreams')
            ->where('enabled', true)
            ->first();

        if (! $integration) {
            return false;
        }

        $prefix = base64_encode($uuid).'_';
        $username = session("{$prefix}guest_auth_username");
        $password = session("{$prefix}guest_auth_password");
        $authResult = PlaylistFacade::authenticate($username, $password);

        if (! $authResult || ! ($authResult[0] ?? null)) {
            return false;
        }

        if (($authResult[1] ?? null) === 'playlist_auth') {
            $auth = PlaylistAuth::where('username', $username)
                ->where('password', $password)
                ->where('enabled', true)
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->first();

            if (! $auth?->aiostreams_enabled) {
                return false;
            }
        }

        return true;
    }

    public static function getUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        $tenant = null,
        bool $shouldGuessMissingParameters = false,
        ?string $configuration = null
    ): string {
        $parameters['uuid'] = static::getCurrentUuid();

        return route(static::getRouteName($panel), $parameters, $isAbsolute);
    }

    /**
     * Resolve the AIOStreams integration attached to the current guest's playlist.
     */
    public function getIntegrationProperty(): ?MediaServerIntegration
    {
        $uuid = static::getCurrentUuid();
        if (! $uuid) {
            return null;
        }

        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return null;
        }

        $actualPlaylist = $playlist instanceof PlaylistAlias
            ? Playlist::find($playlist->playlist_id)
            : $playlist;

        if (! $actualPlaylist || ! $actualPlaylist->aiostreams_integration_id) {
            return null;
        }

        return MediaServerIntegration::find($actualPlaylist->aiostreams_integration_id);
    }

    /**
     * Resolve the PlaylistAuth ID for the currently authenticated guest, if any.
     */
    public function getPlaylistAuthIdProperty(): ?int
    {
        $uuid = static::getCurrentUuid();
        if (! $uuid) {
            return null;
        }

        $prefix = base64_encode($uuid).'_';
        $username = session("{$prefix}guest_auth_username");

        if (! $username) {
            return null;
        }

        return PlaylistAuth::where('username', $username)->value('id');
    }
}
