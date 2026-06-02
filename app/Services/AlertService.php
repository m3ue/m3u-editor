<?php

namespace App\Services;

use App\Settings\GeneralSettings;
use Spatie\DiscordAlerts\Facades\DiscordAlert;
use Spatie\SlackAlerts\Facades\SlackAlert;
use Throwable;

class AlertService
{
    public function __construct(private readonly GeneralSettings $settings) {}

    /**
     * Send a message to all enabled alert channels (Discord and/or Slack).
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
    }

    /**
     * Returns true if at least one alert channel is configured and enabled.
     */
    public function isEnabled(): bool
    {
        return ($this->settings->discord_alerts_enabled && ! empty($this->settings->discord_webhook_url))
            || ($this->settings->slack_alerts_enabled && ! empty($this->settings->slack_webhook_url));
    }
}
