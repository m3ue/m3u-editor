<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells M3U TV installs their TV Client Profile changed, so they refetch
 * `get_client_config` instead of waiting for the next launch. Rides the
 * `tv.{type}.{uuid}[.{authId}]` channels the app already listens on; the
 * channel list comes from the device registry (TvClientConfigService).
 */
class TvClientConfigUpdatedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<int, string>  $channelNames
     */
    public function __construct(public readonly array $channelNames) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (string $name): PrivateChannel => new PrivateChannel($name), $this->channelNames);
    }

    public function broadcastAs(): string
    {
        return 'client_config.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
