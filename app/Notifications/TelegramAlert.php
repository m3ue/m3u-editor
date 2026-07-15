<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Telegram\TelegramChannel;
use NotificationChannels\Telegram\TelegramMessage;
use Throwable;

/**
 * On-demand notification used by the AlertService to forward alert messages
 * to a Telegram chat. The chat ID is provided via on-demand routing
 * (Notification::route('telegram', $chatId)) and the bot token comes from
 * GeneralSettings, so no services config entry is required.
 */
class TelegramAlert extends BaseNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 3;

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
            ->token(Crypt::decryptString($this->botToken))
            ->normal()
            ->content($this->message);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("Telegram alert job failed: {$exception->getMessage()}");
    }
}
