<?php

namespace App\Notifications;

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
}
