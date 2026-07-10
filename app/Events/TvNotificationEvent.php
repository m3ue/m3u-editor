<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TvNotificationEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $id,
        public readonly string $notifiableType,
        public readonly string $notifiableUuid,
        public readonly bool $adminOnly,
        public readonly string $channel,
        public readonly string $title,
        public readonly string $body,
        public readonly string $status,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $type = $this->notifiableType;
        $uuid = $this->notifiableUuid;
        $adminChannel = new PrivateChannel("tv.{$type}-admin.{$uuid}");

        if ($this->adminOnly) {
            return [$adminChannel];
        }

        return [
            new PrivateChannel("tv.{$type}.{$uuid}"),
            $adminChannel,
        ];
    }

    public function broadcastAs(): string
    {
        return 'tv.notification';
    }
}
