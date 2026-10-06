<?php

namespace App\Services;

use App\Models\MediaServerIntegration;
use App\Support\PrivateNetworkGuard;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class EmbyManagedSetupService
{
    private const int CONTRACT_VERSION = 1;

    private const string ORIGIN_BLOCKED_MESSAGE = 'Emby managed setup was blocked by the integration security policy.';

    private const string CONNECTION_FAILED_MESSAGE = 'Emby managed setup could not connect. Check that Emby is reachable, then retry.';

    private const string ENDPOINT_NOT_FOUND_MESSAGE = 'The Emby managed setup endpoint was not found. Check the companion installation, then retry.';

    private const string REQUEST_REJECTED_MESSAGE = 'Emby rejected the managed setup request. Check the administrator credential and permissions, then retry.';

    private const string BINDING_CONFLICT_MESSAGE = 'Emby reported a managed setup binding conflict. Reconnect the integration, then retry.';

    private const string NOT_READY_MESSAGE = 'Emby is not ready for managed setup. Check the companion configuration, then retry.';

    private const string INVALID_RESPONSE_MESSAGE = 'Emby returned an invalid managed setup response. Check the companion configuration, then retry.';

    private const string UNSUPPORTED_VERSION_MESSAGE = 'The Emby companion does not support managed setup version 1. Update the companion, then retry.';

    /** @return array{success: bool, message: string} */
    public function setup(MediaServerIntegration $integration): array
    {
        if (! $integration->isEmby() || ! $this->originIsAllowed($integration)) {
            return $this->failure(self::ORIGIN_BLOCKED_MESSAGE);
        }

        try {
            $response = Http::baseUrl($integration->base_url)
                ->connectTimeout(5)
                ->timeout(15)
                ->withoutRedirecting()
                ->withHeaders([
                    'X-Emby-Token' => $integration->api_key,
                    'Accept' => 'application/json',
                ])
                ->put('/M3uEditor/Managed/Setup/V1', [
                    'IntegrationId' => $integration->id,
                ]);
        } catch (Throwable) {
            return $this->failure(self::CONNECTION_FAILED_MESSAGE);
        }

        if (! $this->responseOriginIsValid($response, $integration)) {
            return $this->failure(self::ORIGIN_BLOCKED_MESSAGE);
        }

        if ($response->status() === 404) {
            return $this->failure(self::ENDPOINT_NOT_FOUND_MESSAGE);
        }

        if (! $response->successful()) {
            return $this->failure(self::REQUEST_REJECTED_MESSAGE);
        }

        $data = $response->json();

        if (! is_array($data)) {
            return $this->failure(self::INVALID_RESPONSE_MESSAGE);
        }

        if (($data['Ready'] ?? null) === false) {
            return $this->failure(self::NOT_READY_MESSAGE);
        }

        if (($data['Ready'] ?? null) !== true) {
            return $this->failure(self::INVALID_RESPONSE_MESSAGE);
        }

        if (($data['CapabilityVersion'] ?? null) !== self::CONTRACT_VERSION) {
            return $this->failure(self::UNSUPPORTED_VERSION_MESSAGE);
        }

        if (($data['IntegrationId'] ?? null) !== $integration->id) {
            return $this->failure(self::BINDING_CONFLICT_MESSAGE);
        }

        $root = $data['ConfirmedRoot'] ?? null;

        if (! is_string($root) || ! MediaServerIntegration::isSafeWritablePath($root)) {
            return $this->failure(self::INVALID_RESPONSE_MESSAGE);
        }

        $integration->updateQuietly([
            'emby_managed_setup_binding_id' => $data['IntegrationId'],
            'emby_managed_setup_root' => $root,
            'emby_managed_setup_capability_version' => $data['CapabilityVersion'],
            'emby_managed_setup_contract_version' => self::CONTRACT_VERSION,
            'emby_publisher_capabilities_updated_at' => now(),
        ]);

        return [
            'success' => true,
            'message' => 'Ready',
        ];
    }

    private function originIsAllowed(MediaServerIntegration $integration): bool
    {
        $host = trim((string) $integration->host, '[]');
        if (! $this->hostIsValid($host)) {
            return false;
        }

        if ($integration->ssl) {
            return true;
        }

        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            return ! str_contains($host, '.')
                && preg_match('/^(?:\d+|0x[0-9a-f]+)$/i', $host) !== 1;
        }

        return PrivateNetworkGuard::ipIsPrivate($host);
    }

    private function hostIsValid(string $host): bool
    {
        if ($host === '' || preg_match('/[\x00-\x20\x7F@\/?#]/', $host) === 1) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function responseOriginIsValid(Response $response, MediaServerIntegration $integration): bool
    {
        $effectiveUrl = $response->handlerStats()['url'] ?? null;

        return $effectiveUrl === null || $this->originsMatch($integration->base_url, $effectiveUrl);
    }

    private function originsMatch(string $expectedUrl, string $effectiveUrl): bool
    {
        $expected = parse_url($expectedUrl);
        $effective = parse_url($effectiveUrl);
        if (! is_array($expected) || ! is_array($effective)) {
            return false;
        }

        $defaultPort = fn (string $scheme): int => $scheme === 'https' ? 443 : 80;
        $expectedScheme = strtolower((string) ($expected['scheme'] ?? ''));
        $effectiveScheme = strtolower((string) ($effective['scheme'] ?? ''));

        return $expectedScheme === $effectiveScheme
            && strtolower((string) ($expected['host'] ?? '')) === strtolower((string) ($effective['host'] ?? ''))
            && ($expected['port'] ?? $defaultPort($expectedScheme)) === ($effective['port'] ?? $defaultPort($effectiveScheme));
    }

    /** @return array{success: false, message: string} */
    private function failure(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
        ];
    }
}
