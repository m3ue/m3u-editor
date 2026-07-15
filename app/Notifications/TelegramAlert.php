<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification as BaseNotification;
use NotificationChannels\Telegram\TelegramChannel;
use NotificationChannels\Telegram\TelegramMessage;

/**
 * On-demand notification used by the AlertService to forward alert messages
 * to a Telegram chat. The chat ID is provided via on-demand routing
 * (Notification::route('telegram', $chatId)) and the bot token comes from
 * GeneralSettings, so no services config entry is required.
 */
class TelegramAlert extends BaseNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $message,
        private readonly string $botToken,
    ) {}

    /**
     * @return array<int, class-string>
     */
    public function via(object $notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        // Send as plain text (no parse mode) so forwarded log content can
        // never break Telegram's Markdown entity parsing.
        return TelegramMessage::create()
            ->token($this->botToken)
            ->normal()
            ->content($this->message);
    }
}
