<?php

namespace App\Services;

use App\Mail\AlertMail;
use App\Notifications\TelegramAlert;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\DiscordAlerts\Facades\DiscordAlert;
use Spatie\SlackAlerts\Facades\SlackAlert;
use Throwable;

class AlertService
{
    public function __construct(private readonly GeneralSettings $settings) {}

    /**
     * Send a message to all enabled alert channels and an optional email recipient.
     * Silently ignores failures to avoid cascading errors.
     */
    public function send(string $message, ?string $email = null, ?string $subject = null): void
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

        if ($email && $this->smtpConfigured()) {
            try {
                $this->configureSmtp();
                Mail::to($email)->send(new AlertMail(
                    emailSubject: $subject ?? 'm3u editor alert',
                    body: $message,
                ));
            } catch (Throwable) {
                // Silently ignore.
            }
        }
    }

    /**
     * Returns true if at least one alert channel is configured and enabled.
     */
    public function isEnabled(?string $email = null): bool
    {
        return ($this->settings->discord_alerts_enabled && ! empty($this->settings->discord_webhook_url))
            || ($this->settings->slack_alerts_enabled && ! empty($this->settings->slack_webhook_url))
            || $this->telegramConfigured()
            || ($email && $this->smtpConfigured());
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

    private function smtpConfigured(): bool
    {
        return ! empty($this->settings->smtp_host)
            && ! empty($this->settings->smtp_port);
    }

    private function configureSmtp(): void
    {
        Config::set('mail.default', 'smtp');
        Config::set('mail.from.address', $this->settings->smtp_from_address ?? 'no-reply@m3u-editor.dev');
        Config::set('mail.from.name', 'm3u editor');
        Config::set('mail.mailers.smtp.host', $this->settings->smtp_host);
        Config::set('mail.mailers.smtp.username', $this->settings->smtp_username);
        Config::set('mail.mailers.smtp.password', $this->settings->smtp_password);
        Config::set('mail.mailers.smtp.port', $this->settings->smtp_port);
        Config::set('mail.mailers.smtp.encryption', $this->settings->smtp_encryption);
    }
}
