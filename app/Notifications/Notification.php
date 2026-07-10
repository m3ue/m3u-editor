<?php

namespace App\Notifications;

use App\Events\TvNotificationEvent;
use App\Models\TvNotification;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification as BaseNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Notification extends BaseNotification
{
    public function broadcast(Model|Authenticatable|Collection|array $users): static
    {
        if ($this->getStatus() === 'success' && app(GeneralSettings::class)->suppress_success_notifications) {
            return $this;
        }

        return parent::broadcast($users);
    }

    public function sendToDatabase(Model|Authenticatable|Collection|array $users, bool $isEventDispatched = false): static
    {
        if ($this->getStatus() === 'success' && app(GeneralSettings::class)->suppress_success_notifications) {
            return $this;
        }

        return parent::sendToDatabase($users, $isEventDispatched);
    }

    public function tvBroadcast(Model $playlist, string $channel = 'general', bool $adminOnly = false): static
    {
        $record = TvNotification::create([
            'notifiable_type' => $playlist->getMorphClass(),
            'notifiable_id' => $playlist->id,
            'channel' => $channel,
            'admin_only' => $adminOnly,
            'title' => $this->getTitle() ?? '',
            'body' => $this->getBody() ?? '',
            'status' => $this->getStatus() ?? 'info',
        ]);

        broadcast(new TvNotificationEvent(
            id: $record->id,
            notifiableType: $playlist->getMorphClass(),
            notifiableUuid: $playlist->uuid,
            adminOnly: $adminOnly,
            channel: $channel,
            title: $this->getTitle() ?? '',
            body: $this->getBody() ?? '',
            status: $this->getStatus() ?? 'info',
        ));

        return $this;
    }
}
