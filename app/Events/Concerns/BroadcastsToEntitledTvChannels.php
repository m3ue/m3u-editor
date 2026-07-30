<?php

namespace App\Events\Concerns;

use App\Models\PlaylistAuth;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Shared channel-set resolution for TV push events that must reach every
 * session subscribed under a playlist (owner/admin channel, and one channel
 * per entitled PlaylistAuth/alias — see PlaylistAuth::scopeEntitledToNotificationRecipient()).
 *
 * TvNotificationEvent originated this set; DvrRecordingStatusEvent previously
 * broadcast only on the bare owner channel, so pushes never reached TV
 * sessions logged in via PlaylistAuth credentials (channel `tv.{type}.{uuid}.{authId}`)
 * even though TvNotificationEvent's pushes did. Sharing this resolution keeps
 * both event families targeting the same recipients by construction.
 */
trait BroadcastsToEntitledTvChannels
{
    /**
     * @return array<int, PrivateChannel>
     */
    private static function entitledTvChannels(string $notifiableType, int|string $notifiableId, string $notifiableUuid): array
    {
        $channels = [
            new PrivateChannel("tv.{$notifiableType}.{$notifiableUuid}"),
            new PrivateChannel("tv.{$notifiableType}-admin.{$notifiableUuid}"),
        ];

        $entitled = PlaylistAuth::query()
            ->entitledToNotificationRecipient($notifiableType, $notifiableId)
            ->with('assignedPlaylist.authenticatable')
            ->get();

        foreach ($entitled as $playlistAuth) {
            $authenticatable = $playlistAuth->assignedPlaylist?->authenticatable;

            if (! $authenticatable) {
                continue;
            }

            $channels[] = new PrivateChannel(
                "tv.{$authenticatable->getMorphClass()}.{$authenticatable->uuid}.{$playlistAuth->id}"
            );
        }

        return $channels;
    }
}
