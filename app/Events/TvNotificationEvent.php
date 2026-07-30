<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsToEntitledTvChannels;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TvNotificationEvent implements ShouldBroadcast
{
    use BroadcastsToEntitledTvChannels, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $id,
        public readonly string $notifiableType,
        private readonly int|string $notifiableId,
        public readonly string $notifiableUuid,
        public readonly bool $adminOnly,
        public readonly string $channel,
        public readonly string $title,
        public readonly string $body,
        public readonly string $status,
        public readonly ?int $playlistAuthId = null,
        public readonly ?array $metadata = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $type = $this->notifiableType;
        $uuid = $this->notifiableUuid;

        if ($this->adminOnly) {
            return [new PrivateChannel("tv.{$type}-admin.{$uuid}")];
        }

        if ($this->playlistAuthId !== null) {
            return [new PrivateChannel("tv.{$type}.{$uuid}.{$this->playlistAuthId}")];
        }

        // An entitled auth may be assigned directly to this model, or to a PlaylistAlias
        // wrapping it — in the latter case it only ever subscribed to its alias's own
        // channel, so the channel must be built from whichever model the auth is actually
        // assigned to, not from this broadcast's own $type/$uuid.
        return self::entitledTvChannels($type, $this->notifiableId, $uuid);
    }

    public function broadcastAs(): string
    {
        return 'tv.notification';
    }
}
