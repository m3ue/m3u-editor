<?php

namespace App\Events;

use App\Models\TvDevice;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the M3U TV app to drop a specific install back to the pairing screen.
 * Rides the same `tv.{type}.{uuid}[.{authId}]` channel the app is already
 * subscribed to; the client filters on `device_id` so only the revoked device
 * reacts. Force-logout only - the underlying credential is untouched.
 *
 * Deliberately does not use App\Events\Concerns\BroadcastsToEntitledTvChannels:
 * that trait fans a broadcast out to every PlaylistAuth entitled to a playlist,
 * whereas a revoke targets one known install whose own `playlist_auth_id` (or
 * lack of one) already tells us the single channel it listens on. The
 * owner/admin channels are included so an admin-paired device is reachable too.
 */
class DeviceDeregisteredEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $deviceId,
        public readonly string $notifiableType,
        public readonly string $notifiableUuid,
        public readonly ?int $playlistAuthId = null,
    ) {}

    /**
     * Builds the event for a device, or null when the row has no resolvable
     * notifiable (nothing to broadcast to). Mirrors the nullable factory
     * pattern used by DvrRecordingStatusEvent.
     */
    public static function forDevice(TvDevice $device): ?self
    {
        $notifiable = $device->notifiable;

        if ($notifiable === null) {
            return null;
        }

        return new self(
            deviceId: $device->device_id,
            notifiableType: $notifiable->getMorphClass(),
            notifiableUuid: $notifiable->uuid,
            playlistAuthId: $device->playlist_auth_id,
        );
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $type = $this->notifiableType;
        $uuid = $this->notifiableUuid;

        $channels = [
            new PrivateChannel("tv.{$type}.{$uuid}"),
            new PrivateChannel("tv.{$type}-admin.{$uuid}"),
        ];

        if ($this->playlistAuthId !== null) {
            $channels[] = new PrivateChannel("tv.{$type}.{$uuid}.{$this->playlistAuthId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'device.deregister';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['device_id' => $this->deviceId];
    }
}
