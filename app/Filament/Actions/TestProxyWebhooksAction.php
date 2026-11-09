<?php

namespace App\Filament\Actions;

use App\Services\M3uProxyService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Checks that m3u-proxy's webhooks reach M3U Editor, registering the
 * webhook first if it's missing (see M3uProxyService::checkWebhook()).
 */
class TestProxyWebhooksAction
{
    public static function make(): Action
    {
        return Action::make('testProxyWebhooks')
            ->label(__('Test webhooks'))
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->action(function (): void {
                $result = app(M3uProxyService::class)->checkWebhook();

                Notification::make()
                    ->title($result['ok'] ? __('Webhooks are working') : __('Webhooks aren\'t reaching M3U Editor'))
                    ->body($result['message'])
                    ->status($result['ok'] ? 'success' : 'danger')
                    ->send();
            });
    }
}
