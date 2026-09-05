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

    /** @return array{success: bool, message: string} */
    public function setup(MediaServerIntegration $integration): array
    {
        if (! $integration->isEmby() || ! $this->originIsAllowed($integration)) {
            return $this->failure();
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
            return $this->failure();
        }

        $data = $response->json();
        $root = is_array($data) ? ($data['ConfirmedRoot'] ?? null) : null;
        if (! $this->responseIsValid($response, $data, $integration, $root)) {
            return $this->failure();
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

    private function responseIsValid(
        Response $response,
        mixed $data,
        MediaServerIntegration $integration,
        mixed $root,
    ): bool {
        $effectiveUrl = $response->handlerStats()['url'] ?? null;

        return $response->successful()
            && ($effectiveUrl === null || $this->originsMatch($integration->base_url, $effectiveUrl))
            && is_array($data)
            && ($data['Ready'] ?? null) === true
            && is_numeric($data['CapabilityVersion'] ?? null)
            && (int) $data['CapabilityVersion'] === self::CONTRACT_VERSION
            && ($data['IntegrationId'] ?? null) === $integration->id
            && is_string($root)
            && MediaServerIntegration::isSafeWritablePath($root);
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
    private function failure(): array
    {
        return [
            'success' => false,
            'message' => 'Install an Emby companion that supports managed setup version '.self::CONTRACT_VERSION.', then retry.',
        ];
    }
}
