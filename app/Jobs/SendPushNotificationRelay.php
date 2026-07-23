<?php

namespace App\Jobs;

use App\Models\PushDeviceToken;
use App\Services\PushRelayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendPushNotificationRelay implements ShouldQueue
{
    use Queueable;

    // Best-effort, same philosophy as AlertService - a bad/expired device
    // token shouldn't hold up the queue with retries.
    public $tries = 1;

    public function __construct(
        public string $notifiableType,
        public int|string $notifiableId,
        public string $title,
        public ?string $body = null,
    ) {}

    public function handle(PushRelayService $relay): void
    {
        if (! $relay->isEnabled()) {
            return;
        }

        $devices = PushDeviceToken::where('notifiable_type', $this->notifiableType)
            ->where('notifiable_id', $this->notifiableId)
            ->get();

        foreach ($devices as $device) {
            try {
                $relay->send($device->token, $device->platform, $this->title, $this->body);
            } catch (Throwable $e) {
                Log::warning("Push relay delivery failed for device token {$device->id}: {$e->getMessage()}");
            }
        }
    }
}
