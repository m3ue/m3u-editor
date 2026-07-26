<?php

namespace App\Notifications;

use App\Events\TvNotificationEvent;
use App\Jobs\SendPushNotificationRelay;
use App\Models\PlaylistAuth;
use App\Models\TvNotification;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification as BaseNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class Notification extends BaseNotification
{
    public function broadcast(Model|Authenticatable|Collection|array $users): static
    {
        if (($this->getStatus() === 'success' || $this->getStatus() === 'info') && app(GeneralSettings::class)->suppress_success_notifications) {
            return $this;
        }

        return parent::broadcast($users);
    }

    public function sendToDatabase(Model|Authenticatable|Collection|array $users, bool $isEventDispatched = false): static
    {
        if (($this->getStatus() === 'success' || $this->getStatus() === 'info') && app(GeneralSettings::class)->suppress_success_notifications) {
            return $this;
        }

        return parent::sendToDatabase($users, $isEventDispatched);
    }

    /** @param array<string, mixed>|null $metadata */
    public function tvBroadcast(Model $playlist, string $channel = 'general', bool $adminOnly = false, ?PlaylistAuth $playlistAuth = null, ?array $metadata = null): static
    {
        if ($adminOnly && $playlistAuth !== null) {
            throw new InvalidArgumentException('tvBroadcast cannot target both adminOnly and a specific PlaylistAuth.');
        }

        $record = TvNotification::create([
            'notifiable_type' => $playlist->getMorphClass(),
            'notifiable_id' => $playlist->id,
            'channel' => $channel,
            'admin_only' => $adminOnly,
            'playlist_auth_id' => $playlistAuth?->id,
            'title' => $this->getTitle() ?? '',
            'body' => $this->getBody() ?? '',
            'status' => $this->getStatus() ?? 'info',
            'metadata' => $metadata,
        ]);

        broadcast(new TvNotificationEvent(
            id: $record->id,
            notifiableType: $playlist->getMorphClass(),
            notifiableId: $playlist->getKey(),
            notifiableUuid: $playlist->uuid,
            adminOnly: $adminOnly,
            channel: $channel,
            title: $this->getTitle() ?? '',
            body: $this->getBody() ?? '',
            status: $this->getStatus() ?? 'info',
            playlistAuthId: $playlistAuth?->id,
            metadata: $metadata,
        ));

        SendPushNotificationRelay::dispatch(
            $playlist->getMorphClass(),
            $playlist->id,
            $this->getTitle() ?? '',
            $this->getBody(),
            $playlistAuth?->id,
            $record->id,
            $metadata,
            $adminOnly,
        );

        return $this;
    }
}
