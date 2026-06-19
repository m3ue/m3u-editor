<?php

namespace App\Filament\GuestPanel\Pages;

use App\Facades\PlaylistFacade;
use App\Filament\GuestPanel\Pages\Concerns\HasGuestAuth;
use App\Models\ArrIntegration;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistRequestSetting;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

class GuestRequestContent extends Page
{
    use HasGuestAuth;

    protected string $view = 'filament.guest-panel.pages.request-content';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?int $navigationSort = 7;

    public static function getNavigationLabel(): string
    {
        return __('Request Content');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Request Content');
    }

    protected static ?string $slug = 'request-content';

    /**
     * Only show the page if the guest is authenticated, the playlist has content
     * requests enabled, and the playlist owner has at least one enabled, guest-enabled
     * Sonarr/Radarr integration.
     */
    public static function canAccess(): bool
    {
        if (! static::isSessionAuthenticated()) {
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

        $playlistId = $playlist instanceof PlaylistAlias ? $playlist->playlist_id : $playlist->id;
        if (! $playlistId) {
            return false;
        }

        $requestSetting = PlaylistRequestSetting::where('playlist_id', $playlistId)->first();
        if (! $requestSetting?->enabled) {
            return false;
        }

        $actualPlaylist = $playlist instanceof PlaylistAlias
            ? Playlist::find($playlistId)
            : $playlist;

        if (! $actualPlaylist) {
            return false;
        }

        return ArrIntegration::query()
            ->where('user_id', $actualPlaylist->user_id)
            ->enabled()
            ->guestEnabled()
            ->exists();
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
     * Resolve the first guest-enabled integration for the playlist owner.
     */
    public function getIntegrationProperty(): ?ArrIntegration
    {
        $userId = $this->resolvePlaylistOwnerId();
        if (! $userId) {
            return null;
        }

        return ArrIntegration::query()
            ->where('user_id', $userId)
            ->enabled()
            ->guestEnabled()
            ->orderBy('name')
            ->first();
    }

    /**
     * All guest-enabled integrations for the playlist owner.
     *
     * @return Collection<int, ArrIntegration>
     */
    public function getIntegrationsProperty(): Collection
    {
        $userId = $this->resolvePlaylistOwnerId();
        if (! $userId) {
            return collect();
        }

        return ArrIntegration::query()
            ->where('user_id', $userId)
            ->enabled()
            ->guestEnabled()
            ->orderBy('name')
            ->get();
    }

    /**
     * Resolve the user_id of the playlist owner from the current guest session UUID.
     */
    private function resolvePlaylistOwnerId(): ?int
    {
        $uuid = static::getCurrentUuid();
        if (! $uuid) {
            return null;
        }

        $playlist = PlaylistFacade::resolvePlaylistByUuid($uuid);
        if (! $playlist) {
            return null;
        }

        if ($playlist instanceof PlaylistAlias) {
            $actual = Playlist::find($playlist->playlist_id);

            return $actual?->user_id;
        }

        return $playlist->user_id;
    }
}
