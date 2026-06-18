<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ArrQueueUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly int $userId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('arr-queue.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'queue.updated';
    }

    /**
     * No payload needed — the Livewire component re-fetches on receipt.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }
}
