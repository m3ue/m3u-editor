<?php

namespace App\Services;

use App\Jobs\SendAlertEmail;
use App\Notifications\TelegramAlert;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Spatie\DiscordAlerts\Facades\DiscordAlert;
use Spatie\DiscordAlerts\Jobs\SendToDiscordChannelJob;
use Spatie\SlackAlerts\Facades\SlackAlert;
use Spatie\SlackAlerts\Jobs\SendToSlackChannelJob;
use Throwable;

class AlertService
{
    public const EMAIL_SUBJECT = 'm3u editor alert';

    /**
     * Queued jobs that deliver an alert to a channel. A failure inside one of
     * these must never produce another alert: every failed attempt is reported
     * by the queue worker as an error log, which the alerts log channel would
     * otherwise turn into a fresh alert, queueing more deliveries through the
     * same broken channel without end.
     *
     * @var array<int, class-string>
     */
    public const DELIVERY_JOBS = [
        TelegramAlert::class,
        SendAlertEmail::class,
        SendToDiscordChannelJob::class,
        SendToSlackChannelJob::class,
    ];

    /**
     * True while this process is running an alert delivery job. Set on
     * JobProcessing and cleared on JobProcessed and on the worker's next
     * loop, so it still covers the worker reporting a failed attempt.
     */
    private static bool $deliveringAlert = false;

    public function __construct(
        private readonly GeneralSettings $settings,
        private readonly SmtpMailService $smtp,
    ) {}

    /**
     * Send a message to all enabled alert channels (Discord, Slack, Telegram and/or Email).
     * Silently ignores failures to avoid cascading errors, and sends nothing
     * while an alert delivery job is running (see DELIVERY_JOBS).
     */
    public function send(string $message): void
    {
        if (self::$deliveringAlert) {
            return;
        }

        try {
            $this->deliver($message);
        } finally {
            // With the sync queue driver a delivery job runs inline inside
            // deliver(), so its flag would otherwise outlive it here.
            self::$deliveringAlert = false;
        }
    }

    /**
     * Whether the given resolved queue job name is an alert delivery job.
     */
    public static function isDeliveryJob(string $jobName): bool
    {
        foreach (self::DELIVERY_JOBS as $deliveryJob) {
            if (str_contains($jobName, $deliveryJob)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Track whether the job this process is about to run delivers an alert.
     * Pass null when no job is running.
     */
    public static function trackRunningJob(?string $jobName): void
    {
        self::$deliveringAlert = $jobName !== null && self::isDeliveryJob($jobName);
    }

    private function deliver(string $message): void
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
