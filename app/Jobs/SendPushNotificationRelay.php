<?php

namespace App\Jobs;

use App\Models\PlaylistAuth;
use App\Models\PushDeviceToken;
use App\Services\PushRelayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
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
        public ?int $playlistAuthId = null,
        public ?string $notificationUuid = null,
        public ?array $data = null,
        public bool $adminOnly = false,
    ) {}

    public function handle(PushRelayService $relay): void
    {
        if (! $relay->isEnabled()) {
            return;
        }

        $query = PushDeviceToken::where('notifiable_type', $this->notifiableType)
            ->where('notifiable_id', $this->notifiableId);

        if ($this->playlistAuthId !== null) {
            $query->where('playlist_auth_id', $this->playlistAuthId);
        } elseif ($this->adminOnly) {
            $query->whereNull('playlist_auth_id');
        } else {
            $query->where(function (Builder $query): void {
                $query->whereNull('playlist_auth_id')
                    ->orWhereIn(
                        'playlist_auth_id',
                        PlaylistAuth::query()
                            ->entitledToNotificationRecipient($this->notifiableType, $this->notifiableId)
                            ->select('id'),
                    );
            });
        }

        $devices = $query->get();

        foreach ($devices as $device) {
            try {
                $relay->send(
                    $device->token,
                    $device->platform,
                    $this->title,
                    $this->body,
                    $this->pushData(),
                );
            } catch (Throwable $e) {
                Log::warning("Push relay delivery failed for device token {$device->id}: {$e->getMessage()}");
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function pushData(): ?array
    {
        $data = $this->data ?? [];

        if ($this->notificationUuid !== null) {
            $data['notification_id'] = $this->notificationUuid;
        }

        return $data === [] ? null : $data;
    }
}
