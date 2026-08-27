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

    public static function forDevice(TvDevice $device): self
    {
        $notifiable = $device->notifiable;

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
