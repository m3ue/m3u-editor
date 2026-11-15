<?php

namespace App\Services;

use App\Jobs\SendAlertEmail;
use App\Notifications\TelegramAlert;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Spatie\DiscordAlerts\Facades\DiscordAlert;
use Spatie\SlackAlerts\Facades\SlackAlert;
use Throwable;

class AlertService
{
    public const EMAIL_SUBJECT = 'm3u editor alert';

    public function __construct(
        private readonly GeneralSettings $settings,
        private readonly SmtpMailService $smtp,
    ) {}

    /**
     * Send a message to all enabled alert channels (Discord, Slack, Telegram and/or Email).
     * Silently ignores failures to avoid cascading errors.
     */
    public function send(string $message): void
    {
        if ($this->settings->discord_alerts_enabled && ! empty($this->settings->discord_webhook_url)) {
            try {
                DiscordAlert::to($this->settings->discord_webhook_url)->message($message);
            } catch (Throwable) {
                // Silently ignore.
            }
        }

        if ($this->settings->slack_alerts_enabled && ! empty($this->settings->slack_webhook_url)) {
            try {
                SlackAlert::to($this->settings->slack_webhook_url)->message($message);
            } catch (Throwable) {
                // Silently ignore.
            }
        }

        if ($this->telegramConfigured()) {
            try {
                Notification::route('telegram', $this->settings->telegram_chat_id)
                    ->notify(new TelegramAlert($message, Crypt::encryptString($this->settings->telegram_bot_token)));
            } catch (Throwable) {
                // Silently ignore.
            }
        }

        if ($this->emailConfigured()) {
            try {
                SendAlertEmail::dispatch($this->emailRecipients(), self::EMAIL_SUBJECT, $message);
            } catch (Throwable) {
                // Silently ignore.
            }
        }
    }

    /**
     * Returns true if at least one alert channel is configured and enabled.
     */
    public function isEnabled(): bool
    {
        return ($this->settings->discord_alerts_enabled && ! empty($this->settings->discord_webhook_url))
            || ($this->settings->slack_alerts_enabled && ! empty($this->settings->slack_webhook_url))
            || $this->telegramConfigured()
            || $this->emailConfigured();
    }

    /**
     * Returns true if Telegram alerts are enabled and fully configured.
     */
    private function telegramConfigured(): bool
    {
        return $this->settings->telegram_alerts_enabled
            && ! empty($this->settings->telegram_bot_token)
            && ! empty($this->settings->telegram_chat_id);
    }

    /**
     * Returns true if email alerts are enabled, have at least one recipient,
     * and the shared SMTP settings are filled in.
     */
    private function emailConfigured(): bool
    {
        return $this->settings->email_alerts_enabled
            && $this->emailRecipients() !== []
            && $this->smtp->isConfigured();
    }

    /**
     * @return array<int, string>
     */
    private function emailRecipients(): array
    {
        return array_values(array_filter(
            array_map('trim', (array) ($this->settings->email_alerts_recipients ?? [])),
            fn (string $address): bool => $address !== '',
        ));
    }
}
