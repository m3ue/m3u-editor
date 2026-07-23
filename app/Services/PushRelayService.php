<?php

namespace App\Services;

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Http;

class PushRelayService
{
    public function __construct(private readonly GeneralSettings $settings) {}

    /**
     * Returns true if the push relay is enabled and a relay URL is configured.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->settings->push_relay_enabled
            && ! empty($this->url());
    }

    /**
     * Sends a single push notification through the relay.
     *
     * Throws on failure so callers can decide how to handle/log it -
     * this service does not swallow errors itself.
     */
    public function send(
        string $token,
        string $platform,
        string $title,
        ?string $body = null,
        ?array $data = null,
    ): void {
        $url = rtrim((string) $this->url(), '/').'/push';

        Http::timeout(10)
            ->post($url, array_filter([
                'token' => $token,
                'platform' => $platform,
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ], fn ($value) => $value !== null))
            ->throw();
    }

    /**
     * The relay is config-only (services.push_relay.url / PUSH_RELAY_URL env)
     * so it isn't exposed as an editable Settings field.
     */
    private function url(): ?string
    {
        return config('services.push_relay.url');
    }
}
